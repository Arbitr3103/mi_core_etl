# Инструкция для PHP разработчика: Работа с системой маржинальности

**Дата:** 19 сентября 2025 г.  
**Версия:** 1.0  
**База данных:** mi_core_db  

---

## 🎯 Обзор системы

Система маржинальности автоматически рассчитывает:
- **Выручку** (`revenue_sum`) - сумма продаж
- **Себестоимость** (`cogs_sum`) - затраты на товары
- **Прибыль** (`profit_sum`) - выручка минус себестоимость
- **Маржинальность** - процент прибыли от выручки

---

## 🗄️ Структура базы данных

### Основные таблицы:

#### `metrics_daily` - Ежедневные метрики
```sql
CREATE TABLE metrics_daily (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  metric_date DATE NOT NULL,
  orders_cnt INT NOT NULL DEFAULT 0,
  revenue_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  returns_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  cogs_sum DECIMAL(18,4) NULL,
  shipping_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  commission_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  other_expenses_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  profit_sum DECIMAL(18,4) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `dim_products` - Справочник товаров
```sql
CREATE TABLE dim_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku_ozon VARCHAR(255) UNIQUE,
    sku_wb VARCHAR(255),
    barcode VARCHAR(255),
    product_name VARCHAR(500),
    cost_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `fact_orders` - Детальные заказы
```sql
CREATE TABLE fact_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    order_id VARCHAR(255) NOT NULL,
    transaction_type VARCHAR(100) NOT NULL,
    sku VARCHAR(255) NOT NULL,
    qty INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    order_date DATE NOT NULL,
    cost_price DECIMAL(10,2),
    client_id INT NOT NULL,
    source_id INT NOT NULL
);
```

---

## 🔌 Подключение к базе данных

### Конфигурация подключения:
```php
<?php
class MarginDatabase {
    private $host = '178.72.129.61';
    private $dbname = 'mi_core_db';
    private $username = 'your_username';
    private $password = 'your_password';
    private $pdo;
    
    public function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}
?>
```

---

## 📊 API методы для получения маржинальности

### 1. Сводная маржинальность за период

```php
<?php
class MarginAPI {
    private $db;
    
    public function __construct() {
        $this->db = new MarginDatabase();
    }
    
    /**
     * Получить сводную маржинальность за период
     * @param string $startDate - дата начала (YYYY-MM-DD)
     * @param string $endDate - дата окончания (YYYY-MM-DD)
     * @param int|null $clientId - ID клиента (null для всех)
     * @return array
     */
    public function getSummaryMargin($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                SUM(orders_cnt) as total_orders,
                SUM(revenue_sum) as total_revenue,
                SUM(COALESCE(cogs_sum, 0)) as total_cogs,
                SUM(COALESCE(profit_sum, 0)) as total_profit,
                CASE 
                    WHEN SUM(revenue_sum) > 0 THEN 
                        ROUND((SUM(COALESCE(profit_sum, 0)) / SUM(revenue_sum)) * 100, 2)
                    ELSE 0 
                END as margin_percent,
                COUNT(DISTINCT metric_date) as days_count
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch();
        
        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_count' => (int)$result['days_count']
            ],
            'totals' => [
                'orders' => (int)$result['total_orders'],
                'revenue' => (float)$result['total_revenue'],
                'cogs' => (float)$result['total_cogs'],
                'profit' => (float)$result['total_profit'],
                'margin_percent' => (float)$result['margin_percent']
            ],
            'averages' => [
                'daily_revenue' => $result['days_count'] > 0 ? 
                    round($result['total_revenue'] / $result['days_count'], 2) : 0,
                'daily_profit' => $result['days_count'] > 0 ? 
                    round($result['total_profit'] / $result['days_count'], 2) : 0
            ]
        ];
    }
}
?>
```

### 2. Маржинальность по дням

```php
<?php
/**
 * Получить маржинальность по дням
 * @param string $startDate
 * @param string $endDate
 * @param int|null $clientId
 * @return array
 */
public function getDailyMargins($startDate, $endDate, $clientId = null) {
    $sql = "
        SELECT 
            metric_date,
            SUM(orders_cnt) as orders,
            SUM(revenue_sum) as revenue,
            SUM(COALESCE(cogs_sum, 0)) as cogs,
            SUM(COALESCE(profit_sum, 0)) as profit,
            CASE 
                WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND((SUM(COALESCE(profit_sum, 0)) / SUM(revenue_sum)) * 100, 2)
                ELSE 0 
            END as margin_percent
        FROM metrics_daily 
        WHERE metric_date BETWEEN :start_date AND :end_date
    ";
    
    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate
    ];
    
    if ($clientId !== null) {
        $sql .= " AND client_id = :client_id";
        $params['client_id'] = $clientId;
    }
    
    $sql .= " GROUP BY metric_date ORDER BY metric_date DESC";
    
    $stmt = $this->db->getConnection()->prepare($sql);
    $stmt->execute($params);
    
    $results = $stmt->fetchAll();
    
    return array_map(function($row) {
        return [
            'date' => $row['metric_date'],
            'orders' => (int)$row['orders'],
            'revenue' => (float)$row['revenue'],
            'cogs' => (float)$row['cogs'],
            'profit' => (float)$row['profit'],
            'margin_percent' => (float)$row['margin_percent']
        ];
    }, $results);
}
?>
```

### 3. Маржинальность по товарам

```php
<?php
/**
 * Получить топ товаров по маржинальности
 * @param int $limit
 * @param string $startDate
 * @param string $endDate
 * @return array
 */
public function getTopProductsByMargin($limit = 20, $startDate = null, $endDate = null) {
    $sql = "
        SELECT 
            dp.sku_ozon,
            dp.sku_wb,
            dp.product_name,
            dp.cost_price,
            SUM(fo.qty) as total_qty,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) as revenue,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as cogs,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as profit,
            CASE 
                WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) > 0 THEN
                    ROUND(((SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END)) / 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END)) * 100, 2)
                ELSE 0
            END as margin_percent
        FROM fact_orders fo
        LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
        WHERE fo.transaction_type = 'продажа'
    ";
    
    $params = [];
    
    if ($startDate && $endDate) {
        $sql .= " AND fo.order_date BETWEEN :start_date AND :end_date";
        $params['start_date'] = $startDate;
        $params['end_date'] = $endDate;
    }
    
    $sql .= "
        GROUP BY dp.sku_ozon, dp.sku_wb, dp.product_name, dp.cost_price
        HAVING revenue > 0
        ORDER BY margin_percent DESC, profit DESC
        LIMIT :limit
    ";
    
    $stmt = $this->db->getConnection()->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    
    return array_map(function($row) {
        return [
            'sku_ozon' => $row['sku_ozon'],
            'sku_wb' => $row['sku_wb'],
            'product_name' => $row['product_name'],
            'cost_price' => (float)$row['cost_price'],
            'quantity' => (int)$row['total_qty'],
            'revenue' => (float)$row['revenue'],
            'cogs' => (float)$row['cogs'],
            'profit' => (float)$row['profit'],
            'margin_percent' => (float)$row['margin_percent']
        ];
    }, $stmt->fetchAll());
}
?>
```

### 4. Товары с низкой маржинальностью

```php
<?php
/**
 * Получить товары с низкой маржинальностью
 * @param float $marginThreshold - порог маржинальности (%)
 * @param float $minRevenue - минимальная выручка для фильтрации
 * @param int $limit
 * @return array
 */
public function getLowMarginProducts($marginThreshold = 15.0, $minRevenue = 1000.0, $limit = 20) {
    $sql = "
        SELECT 
            dp.sku_ozon,
            dp.sku_wb,
            dp.product_name,
            dp.cost_price,
            SUM(fo.qty) as total_qty,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) as revenue,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as cogs,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as profit,
            CASE 
                WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) > 0 THEN
                    ROUND(((SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END)) / 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END)) * 100, 2)
                ELSE 0
            END as margin_percent
        FROM fact_orders fo
        LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
        WHERE fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL
        GROUP BY dp.sku_ozon, dp.sku_wb, dp.product_name, dp.cost_price
        HAVING revenue > :min_revenue AND margin_percent < :margin_threshold
        ORDER BY margin_percent ASC, revenue DESC
        LIMIT :limit
    ";
    
    $stmt = $this->db->getConnection()->prepare($sql);
    $stmt->bindValue('margin_threshold', $marginThreshold);
    $stmt->bindValue('min_revenue', $minRevenue);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    
    return array_map(function($row) {
        return [
            'sku_ozon' => $row['sku_ozon'],
            'sku_wb' => $row['sku_wb'],
            'product_name' => $row['product_name'],
            'cost_price' => (float)$row['cost_price'],
            'quantity' => (int)$row['total_qty'],
            'revenue' => (float)$row['revenue'],
            'cogs' => (float)$row['cogs'],
            'profit' => (float)$row['profit'],
            'margin_percent' => (float)$row['margin_percent'],
            'warning' => 'Низкая маржинальность'
        ];
    }, $stmt->fetchAll());
}
?>
```

---

## 🌐 REST API эндпоинты

### Пример реализации REST API:

```php
<?php
// api/margins.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'MarginAPI.php';

try {
    $api = new MarginAPI();
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // GET /api/margins/summary?start_date=2025-09-01&end_date=2025-09-19&client_id=1
    if ($method === 'GET' && end($pathParts) === 'summary') {
        $startDate = $_GET['start_date'] ?? date('Y-m-01'); // Начало месяца
        $endDate = $_GET['end_date'] ?? date('Y-m-d'); // Сегодня
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        
        $result = $api->getSummaryMargin($startDate, $endDate, $clientId);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
    
    // GET /api/margins/daily?start_date=2025-09-01&end_date=2025-09-19
    elseif ($method === 'GET' && end($pathParts) === 'daily') {
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        
        $result = $api->getDailyMargins($startDate, $endDate, $clientId);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
    
    // GET /api/margins/products/top?limit=20
    elseif ($method === 'GET' && $pathParts[count($pathParts)-2] === 'products' && end($pathParts) === 'top') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $result = $api->getTopProductsByMargin($limit, $startDate, $endDate);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
    
    // GET /api/margins/products/low?threshold=15&min_revenue=1000
    elseif ($method === 'GET' && $pathParts[count($pathParts)-2] === 'products' && end($pathParts) === 'low') {
        $threshold = isset($_GET['threshold']) ? (float)$_GET['threshold'] : 15.0;
        $minRevenue = isset($_GET['min_revenue']) ? (float)$_GET['min_revenue'] : 1000.0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        
        $result = $api->getLowMarginProducts($threshold, $minRevenue, $limit);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
    
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found'], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
```

---

## 📋 Примеры использования

### 1. Получение сводной маржинальности за месяц:

```php
<?php
$api = new MarginAPI();
$result = $api->getSummaryMargin('2025-09-01', '2025-09-19', 1);

echo "Выручка: " . number_format($result['totals']['revenue'], 2) . " руб.\n";
echo "Прибыль: " . number_format($result['totals']['profit'], 2) . " руб.\n";
echo "Маржинальность: " . $result['totals']['margin_percent'] . "%\n";
?>
```

### 2. Получение топ-10 товаров по марже:

```php
<?php
$api = new MarginAPI();
$products = $api->getTopProductsByMargin(10);

foreach ($products as $product) {
    echo sprintf(
        "SKU: %s | Выручка: %.2f | Маржа: %.1f%%\n",
        $product['sku_ozon'] ?: $product['sku_wb'],
        $product['revenue'],
        $product['margin_percent']
    );
}
?>
```

### 3. AJAX запрос из JavaScript:

```javascript
// Получение сводной маржинальности
fetch('/api/margins/summary?start_date=2025-09-01&end_date=2025-09-19')
    .then(response => response.json())
    .then(data => {
        console.log('Общая маржинальность:', data.totals.margin_percent + '%');
        console.log('Прибыль:', data.totals.profit.toLocaleString() + ' руб.');
    });

// Получение топ товаров
fetch('/api/margins/products/top?limit=10')
    .then(response => response.json())
    .then(products => {
        products.forEach(product => {
            console.log(`${product.sku_ozon}: ${product.margin_percent}%`);
        });
    });
```

---

## ⚠️ Важные замечания

### 1. Покрытие данными:
- Только **20.7%** товаров имеют себестоимость
- Для товаров без себестоимости `cogs_sum = 0`, `profit_sum = revenue_sum`
- Рекомендуется загрузить полный прайс-лист для точных расчетов

### 2. Производительность:
- Используйте индексы на `metric_date`, `client_id`
- Для больших периодов используйте пагинацию
- Кешируйте часто запрашиваемые данные

### 3. Обновление данных:
- Система автоматически пересчитывает маржинальность при новых продажах
- Для пересчета исторических данных используйте `recalculate_margins.py`

---

## 🔧 Техническая поддержка

При возникновении вопросов или проблем:
1. Проверьте подключение к базе данных
2. Убедитесь в корректности SQL запросов
3. Проверьте наличие данных в таблицах `metrics_daily` и `dim_products`

**Система готова к использованию!** 🚀
