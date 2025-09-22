# Руководство по оптимизации производительности и бизнес-логики
**Система управления остатками товаров**

---

## 🚀 Оптимизация производительности

### 1. Оптимизация базы данных

#### Индексы для критических запросов
```sql
-- Составные индексы для частых запросов
CREATE INDEX idx_inventory_product_source_type ON inventory(product_id, source, stock_type);
CREATE INDEX idx_inventory_quantity_updated ON inventory(quantity_present, updated_at);
CREATE INDEX idx_inventory_source_warehouse ON inventory(source, warehouse_name);

-- Индексы для поиска товаров
CREATE INDEX idx_products_search ON dim_products(sku_ozon, sku_wb, barcode);
CREATE INDEX idx_products_name_fulltext ON dim_products(name);

-- Индексы для аналитики
CREATE INDEX idx_orders_date_source ON fact_orders(order_date, source);
CREATE INDEX idx_transactions_date_type ON fact_transactions(transaction_date, transaction_type);
```

#### Партиционирование больших таблиц
```sql
-- Партиционирование по дате для fact_orders
ALTER TABLE fact_orders PARTITION BY RANGE (YEAR(order_date)) (
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Партиционирование для fact_transactions
ALTER TABLE fact_transactions PARTITION BY RANGE (YEAR(transaction_date)) (
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

#### Материализованные представления для аналитики
```sql
-- Создание таблицы для агрегированных данных
CREATE TABLE inventory_summary (
    source VARCHAR(50),
    stock_type VARCHAR(20),
    total_products INT,
    total_stock INT,
    total_reserved INT,
    avg_stock_per_product DECIMAL(10,2),
    last_updated TIMESTAMP,
    PRIMARY KEY (source, stock_type)
);

-- Процедура обновления сводки
DELIMITER //
CREATE PROCEDURE UpdateInventorySummary()
BEGIN
    DELETE FROM inventory_summary;
    
    INSERT INTO inventory_summary (source, stock_type, total_products, total_stock, total_reserved, avg_stock_per_product, last_updated)
    SELECT 
        source,
        stock_type,
        COUNT(DISTINCT product_id) as total_products,
        SUM(quantity_present) as total_stock,
        SUM(quantity_reserved) as total_reserved,
        AVG(quantity_present) as avg_stock_per_product,
        NOW() as last_updated
    FROM inventory
    WHERE quantity_present > 0
    GROUP BY source, stock_type;
END //
DELIMITER ;
```

### 2. Кэширование на уровне приложения

#### Redis кэширование
```php
<?php
class CachedInventoryService {
    private $redis;
    private $db;
    private $cache_ttl = [
        'overview' => 300,      // 5 минут
        'product' => 600,       // 10 минут
        'critical' => 60,       // 1 минута
        'summary' => 1800       // 30 минут
    ];
    
    public function __construct(Redis $redis, PDO $db) {
        $this->redis = $redis;
        $this->db = $db;
    }
    
    public function getProductStock($product_id, $use_cache = true) {
        $cache_key = "stock:product:{$product_id}";
        
        if ($use_cache) {
            $cached = $this->redis->get($cache_key);
            if ($cached !== false) {
                return json_decode($cached, true);
            }
        }
        
        // Запрос к БД
        $sql = "SELECT * FROM inventory WHERE product_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$product_id]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Кэшируем результат
        $this->redis->setex($cache_key, $this->cache_ttl['product'], json_encode($result));
        
        return $result;
    }
    
    public function invalidateProductCache($product_id) {
        $this->redis->del("stock:product:{$product_id}");
        $this->redis->del("overview:*");
    }
    
    public function warmupCache() {
        // Предзагрузка популярных данных
        $popular_products = $this->getPopularProducts();
        foreach ($popular_products as $product_id) {
            $this->getProductStock($product_id, false);
        }
    }
}
?>
```

#### Кэширование на уровне веб-сервера
```nginx
# nginx.conf
location /api/inventory {
    # Кэширование GET запросов на 5 минут
    proxy_cache inventory_cache;
    proxy_cache_valid 200 5m;
    proxy_cache_key "$request_uri$request_body";
    
    # Добавляем заголовки кэша
    add_header X-Cache-Status $upstream_cache_status;
    
    proxy_pass http://backend;
}

# Настройка кэша
proxy_cache_path /var/cache/nginx/inventory levels=1:2 keys_zone=inventory_cache:10m max_size=100m inactive=60m;
```

### 3. Оптимизация ETL процессов

#### Параллельная обработка
```python
# parallel_importer.py
import concurrent.futures
import threading
from queue import Queue

class ParallelStockImporter:
    def __init__(self, max_workers=4):
        self.max_workers = max_workers
        self.batch_size = 100
        
    def import_stocks_parallel(self):
        """Параллельный импорт остатков"""
        
        # Получаем данные из API
        ozon_data = self.get_ozon_data()
        wb_data = self.get_wb_data()
        
        # Разбиваем на батчи
        ozon_batches = self.split_into_batches(ozon_data, self.batch_size)
        wb_batches = self.split_into_batches(wb_data, self.batch_size)
        
        # Параллельная обработка
        with concurrent.futures.ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            # Запускаем обработку Ozon
            ozon_futures = [
                executor.submit(self.process_batch, batch, 'Ozon') 
                for batch in ozon_batches
            ]
            
            # Запускаем обработку WB
            wb_futures = [
                executor.submit(self.process_batch, batch, 'Wildberries') 
                for batch in wb_batches
            ]
            
            # Ждем завершения всех задач
            all_futures = ozon_futures + wb_futures
            for future in concurrent.futures.as_completed(all_futures):
                try:
                    result = future.result()
                    logger.info(f"Batch processed: {result}")
                except Exception as e:
                    logger.error(f"Batch processing error: {e}")
    
    def process_batch(self, batch, source):
        """Обработка батча данных"""
        processed_count = 0
        
        # Используем отдельное подключение для каждого потока
        connection = self.get_thread_connection()
        
        try:
            for item in batch:
                self.process_inventory_item(item, source, connection)
                processed_count += 1
                
            connection.commit()
            
        except Exception as e:
            connection.rollback()
            raise e
        finally:
            connection.close()
            
        return f"{source}: {processed_count} items processed"
```

#### Инкрементальные обновления
```python
class IncrementalImporter:
    def __init__(self):
        self.last_sync_file = 'last_sync.json'
        
    def get_last_sync_time(self, source):
        """Получить время последней синхронизации"""
        try:
            with open(self.last_sync_file, 'r') as f:
                sync_data = json.load(f)
                return sync_data.get(source)
        except FileNotFoundError:
            return None
    
    def save_sync_time(self, source, sync_time):
        """Сохранить время синхронизации"""
        try:
            with open(self.last_sync_file, 'r') as f:
                sync_data = json.load(f)
        except FileNotFoundError:
            sync_data = {}
            
        sync_data[source] = sync_time.isoformat()
        
        with open(self.last_sync_file, 'w') as f:
            json.dump(sync_data, f)
    
    def import_incremental_changes(self):
        """Импорт только изменений с последней синхронизации"""
        current_time = datetime.now()
        
        # Ozon инкрементальный импорт
        last_ozon_sync = self.get_last_sync_time('ozon')
        if last_ozon_sync:
            ozon_changes = self.get_ozon_changes_since(last_ozon_sync)
            self.process_ozon_changes(ozon_changes)
        
        # WB инкрементальный импорт
        last_wb_sync = self.get_last_sync_time('wildberries')
        if last_wb_sync:
            wb_changes = self.get_wb_changes_since(last_wb_sync)
            self.process_wb_changes(wb_changes)
        
        # Сохраняем время синхронизации
        self.save_sync_time('ozon', current_time)
        self.save_sync_time('wildberries', current_time)
```

---

## 💼 Улучшение бизнес-логики

### 1. Система алертов и уведомлений

#### Умные уведомления о критических остатках
```php
<?php
class SmartAlertSystem {
    private $db;
    private $notification_service;
    
    public function __construct(PDO $db, NotificationService $notification_service) {
        $this->db = $db;
        $this->notification_service = $notification_service;
    }
    
    public function checkCriticalStock() {
        // Получаем товары с критическими остатками
        $critical_products = $this->getCriticalStockProducts();
        
        foreach ($critical_products as $product) {
            $alert_level = $this->calculateAlertLevel($product);
            
            switch ($alert_level) {
                case 'CRITICAL':
                    $this->sendCriticalAlert($product);
                    break;
                case 'WARNING':
                    $this->sendWarningAlert($product);
                    break;
                case 'INFO':
                    $this->sendInfoAlert($product);
                    break;
            }
        }
    }
    
    private function calculateAlertLevel($product) {
        $total_stock = $product['total_stock'];
        $avg_daily_sales = $this->getAverageDailySales($product['product_id']);
        $days_of_stock = $total_stock / max($avg_daily_sales, 1);
        
        if ($days_of_stock <= 2) {
            return 'CRITICAL';
        } elseif ($days_of_stock <= 7) {
            return 'WARNING';
        } elseif ($days_of_stock <= 14) {
            return 'INFO';
        }
        
        return 'OK';
    }
    
    private function getAverageDailySales($product_id) {
        $sql = "
            SELECT AVG(daily_quantity) as avg_sales
            FROM (
                SELECT DATE(order_date) as order_day, SUM(quantity) as daily_quantity
                FROM fact_orders 
                WHERE product_id = ? 
                AND order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(order_date)
            ) daily_sales
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['avg_sales'] ?? 0;
    }
}
?>
```

### 2. Прогнозирование остатков

#### ML-модель для прогнозирования
```python
# stock_forecasting.py
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
from sklearn.model_selection import train_test_split
import joblib

class StockForecaster:
    def __init__(self):
        self.model = RandomForestRegressor(n_estimators=100, random_state=42)
        self.is_trained = False
        
    def prepare_features(self, product_id, days_back=90):
        """Подготовка признаков для модели"""
        
        # Получаем исторические данные
        query = """
        SELECT 
            DATE(order_date) as date,
            SUM(quantity) as daily_sales,
            AVG(price) as avg_price,
            COUNT(*) as order_count
        FROM fact_orders 
        WHERE product_id = %s 
        AND order_date >= DATE_SUB(NOW(), INTERVAL %s DAY)
        GROUP BY DATE(order_date)
        ORDER BY date
        """
        
        df = pd.read_sql(query, self.db_connection, params=[product_id, days_back])
        
        # Добавляем временные признаки
        df['date'] = pd.to_datetime(df['date'])
        df['day_of_week'] = df['date'].dt.dayofweek
        df['day_of_month'] = df['date'].dt.day
        df['month'] = df['date'].dt.month
        df['is_weekend'] = df['day_of_week'].isin([5, 6]).astype(int)
        
        # Добавляем скользящие средние
        df['sales_ma_7'] = df['daily_sales'].rolling(window=7).mean()
        df['sales_ma_14'] = df['daily_sales'].rolling(window=14).mean()
        
        # Добавляем лаги
        df['sales_lag_1'] = df['daily_sales'].shift(1)
        df['sales_lag_7'] = df['daily_sales'].shift(7)
        
        return df.dropna()
    
    def train_model(self, product_ids):
        """Обучение модели на исторических данных"""
        
        all_features = []
        all_targets = []
        
        for product_id in product_ids:
            df = self.prepare_features(product_id)
            
            if len(df) < 30:  # Минимум данных для обучения
                continue
                
            features = df[['day_of_week', 'day_of_month', 'month', 'is_weekend', 
                          'avg_price', 'order_count', 'sales_ma_7', 'sales_ma_14',
                          'sales_lag_1', 'sales_lag_7']].values
            
            targets = df['daily_sales'].values
            
            all_features.extend(features)
            all_targets.extend(targets)
        
        # Обучаем модель
        X_train, X_test, y_train, y_test = train_test_split(
            all_features, all_targets, test_size=0.2, random_state=42
        )
        
        self.model.fit(X_train, y_train)
        self.is_trained = True
        
        # Сохраняем модель
        joblib.dump(self.model, 'stock_forecast_model.pkl')
        
        # Оценка качества
        score = self.model.score(X_test, y_test)
        print(f"Model R² score: {score:.3f}")
        
    def predict_demand(self, product_id, days_ahead=14):
        """Прогноз спроса на N дней вперед"""
        
        if not self.is_trained:
            self.model = joblib.load('stock_forecast_model.pkl')
            self.is_trained = True
        
        # Получаем последние данные
        df = self.prepare_features(product_id, days_back=30)
        
        if len(df) == 0:
            return None
        
        predictions = []
        last_row = df.iloc[-1].copy()
        
        for day in range(days_ahead):
            # Подготавливаем признаки для предсказания
            future_date = pd.Timestamp.now() + pd.Timedelta(days=day+1)
            
            features = [
                future_date.dayofweek,
                future_date.day,
                future_date.month,
                1 if future_date.dayofweek in [5, 6] else 0,
                last_row['avg_price'],
                last_row['order_count'],
                last_row['sales_ma_7'],
                last_row['sales_ma_14'],
                last_row['sales_lag_1'],
                last_row['sales_lag_7']
            ]
            
            # Делаем предсказание
            prediction = self.model.predict([features])[0]
            predictions.append(max(0, prediction))  # Не может быть отрицательных продаж
            
            # Обновляем лаги для следующего предсказания
            last_row['sales_lag_1'] = prediction
            if day == 6:  # Обновляем недельный лаг
                last_row['sales_lag_7'] = prediction
        
        return predictions
    
    def calculate_reorder_point(self, product_id):
        """Расчет точки перезаказа"""
        
        # Прогнозируем спрос на 14 дней
        demand_forecast = self.predict_demand(product_id, 14)
        
        if not demand_forecast:
            return None
        
        # Получаем текущие остатки
        current_stock = self.get_current_stock(product_id)
        
        # Рассчитываем точку перезаказа
        total_forecast_demand = sum(demand_forecast)
        safety_stock = total_forecast_demand * 0.2  # 20% запас безопасности
        
        reorder_point = total_forecast_demand + safety_stock
        
        return {
            'current_stock': current_stock,
            'forecast_demand_14d': total_forecast_demand,
            'safety_stock': safety_stock,
            'reorder_point': reorder_point,
            'days_until_stockout': self.calculate_stockout_days(current_stock, demand_forecast),
            'recommended_order_quantity': max(0, reorder_point - current_stock)
        }
```

### 3. Автоматизация закупок

#### Система автоматических заказов
```php
<?php
class AutoPurchaseSystem {
    private $db;
    private $forecaster;
    private $supplier_api;
    
    public function __construct(PDO $db, StockForecaster $forecaster, SupplierAPI $supplier_api) {
        $this->db = $db;
        $this->forecaster = $forecaster;
        $this->supplier_api = $supplier_api;
    }
    
    public function generatePurchaseRecommendations() {
        $products = $this->getActiveProducts();
        $recommendations = [];
        
        foreach ($products as $product) {
            $forecast = $this->forecaster->calculate_reorder_point($product['id']);
            
            if ($forecast && $forecast['recommended_order_quantity'] > 0) {
                $recommendation = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'current_stock' => $forecast['current_stock'],
                    'recommended_quantity' => $forecast['recommended_order_quantity'],
                    'days_until_stockout' => $forecast['days_until_stockout'],
                    'priority' => $this->calculatePriority($forecast),
                    'estimated_cost' => $this->estimateCost($product['id'], $forecast['recommended_order_quantity']),
                    'supplier_info' => $this->getBestSupplier($product['id'])
                ];
                
                $recommendations[] = $recommendation;
            }
        }
        
        // Сортируем по приоритету
        usort($recommendations, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        return $recommendations;
    }
    
    public function createAutoPurchaseOrders() {
        $recommendations = $this->generatePurchaseRecommendations();
        $created_orders = [];
        
        foreach ($recommendations as $rec) {
            // Проверяем автоматические правила
            if ($this->shouldAutoOrder($rec)) {
                try {
                    $order = $this->supplier_api->createPurchaseOrder([
                        'product_id' => $rec['product_id'],
                        'quantity' => $rec['recommended_quantity'],
                        'supplier_id' => $rec['supplier_info']['id']
                    ]);
                    
                    $this->logPurchaseOrder($order, 'AUTO');
                    $created_orders[] = $order;
                    
                } catch (Exception $e) {
                    $this->logError("Auto-order failed for product {$rec['product_id']}: " . $e->getMessage());
                }
            }
        }
        
        return $created_orders;
    }
    
    private function shouldAutoOrder($recommendation) {
        // Правила для автоматического заказа
        return $recommendation['days_until_stockout'] <= 3 
            && $recommendation['priority'] >= 8
            && $recommendation['estimated_cost'] <= $this->getAutoOrderBudgetLimit();
    }
}
?>
```

### 4. Аналитика и KPI

#### Система метрик эффективности
```php
<?php
class InventoryKPICalculator {
    private $db;
    
    public function calculateInventoryTurnover($period_days = 30) {
        $sql = "
            SELECT 
                p.id,
                p.name,
                AVG(i.quantity_present) as avg_inventory,
                SUM(o.quantity) as total_sold,
                (SUM(o.quantity) / AVG(i.quantity_present)) as turnover_ratio,
                (AVG(i.quantity_present) / (SUM(o.quantity) / ?)) as days_of_inventory
            FROM dim_products p
            LEFT JOIN inventory i ON p.id = i.product_id
            LEFT JOIN fact_orders o ON p.id = o.product_id 
                AND o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            WHERE i.quantity_present > 0
            GROUP BY p.id, p.name
            HAVING total_sold > 0
            ORDER BY turnover_ratio DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$period_days, $period_days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function calculateStockoutFrequency($period_days = 90) {
        // Анализ частоты отсутствия товаров на складе
        $sql = "
            SELECT 
                product_id,
                COUNT(*) as stockout_events,
                AVG(DATEDIFF(restock_date, stockout_date)) as avg_stockout_duration
            FROM stockout_events 
            WHERE stockout_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY product_id
            ORDER BY stockout_events DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$period_days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function generateInventoryReport() {
        return [
            'turnover_analysis' => $this->calculateInventoryTurnover(),
            'stockout_analysis' => $this->calculateStockoutFrequency(),
            'abc_analysis' => $this->performABCAnalysis(),
            'slow_moving_products' => $this->getSlowMovingProducts(),
            'overstocked_products' => $this->getOverstockedProducts(),
            'profitability_analysis' => $this->calculateProfitabilityByProduct()
        ];
    }
}
?>
```

---

## 📈 Рекомендации по внедрению

### Приоритет 1 (Немедленно):
1. **Добавить индексы БД** - прирост производительности до 300%
2. **Внедрить Redis кэширование** - снижение нагрузки на БД до 80%
3. **Настроить мониторинг критических остатков** - предотвращение потерь продаж

### Приоритет 2 (1-2 недели):
1. **Параллельная обработка ETL** - ускорение импорта в 2-4 раза
2. **Система алертов** - автоматические уведомления менеджерам
3. **Базовая аналитика KPI** - понимание эффективности управления остатками

### Приоритет 3 (1-2 месяца):
1. **ML-прогнозирование** - точное планирование закупок
2. **Автоматизация заказов** - снижение человеческого фактора
3. **Расширенная аналитика** - глубокое понимание бизнес-процессов

---

## 💰 Ожидаемый эффект

- **Производительность**: Ускорение запросов в 5-10 раз
- **Снижение издержек**: Оптимизация остатков на 15-25%
- **Предотвращение потерь**: Снижение stockout на 60-80%
- **Автоматизация**: Экономия 10-15 часов работы менеджера в неделю

---

*Руководство подготовлено: 21 сентября 2025 г.*
