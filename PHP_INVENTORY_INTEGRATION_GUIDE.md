# Руководство по интеграции с системой остатков товаров
**Для PHP разработчиков**

---

## 📋 Обзор

Система ETL автоматически импортирует остатки товаров с маркетплейсов Ozon и Wildberries в базу данных MySQL. Данное руководство поможет вам интегрировать эти данные в ваши PHP приложения.

---

## 🗄️ Структура базы данных

### Таблица `inventory`
Основная таблица с остатками товаров:

```sql
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(50) NOT NULL,
    warehouse_name VARCHAR(100) NOT NULL,
    stock_type ENUM('FBO', 'FBS', 'realFBS') NOT NULL,
    quantity_present INT DEFAULT 0,
    quantity_reserved INT DEFAULT 0,
    quantity_coming INT DEFAULT 0,
    source ENUM('Ozon', 'Wildberries') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_inventory (product_id, warehouse_name, stock_type, source)
);
```

### Связанные таблицы
- `dim_products` - справочник товаров с артикулами и штрихкодами
- `fact_orders` - данные о заказах
- `fact_transactions` - финансовые транзакции

---

## 🔌 Подключение к базе данных

### Параметры подключения:
```php
<?php
$host = '127.0.0.1';
$dbname = 'mi_core_db';
$username = 'ingest_user';
$password = 'IngestUserPassword2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}
?>
```

---

## 📊 Основные SQL запросы

### 1. Получение всех остатков товара
```sql
SELECT 
    i.product_id,
    p.name as product_name,
    p.sku_ozon,
    p.sku_wb,
    p.barcode,
    i.warehouse_name,
    i.stock_type,
    i.quantity_present,
    i.quantity_reserved,
    i.source,
    i.updated_at
FROM inventory i
LEFT JOIN dim_products p ON i.product_id = p.id
WHERE i.quantity_present > 0
ORDER BY i.source, i.stock_type, i.warehouse_name;
```

### 2. Остатки конкретного товара по артикулу Ozon
```sql
SELECT 
    i.warehouse_name,
    i.stock_type,
    i.quantity_present,
    i.quantity_reserved,
    i.updated_at
FROM inventory i
JOIN dim_products p ON i.product_id = p.id
WHERE p.sku_ozon = ? AND i.source = 'Ozon';
```

### 3. Остатки товара по штрихкоду
```sql
SELECT 
    i.source,
    i.warehouse_name,
    i.stock_type,
    i.quantity_present,
    i.quantity_reserved,
    i.updated_at
FROM inventory i
JOIN dim_products p ON i.product_id = p.id
WHERE p.barcode = ?;
```

### 4. Сводка остатков по маркетплейсам
```sql
SELECT 
    i.source,
    i.stock_type,
    COUNT(*) as products_count,
    SUM(i.quantity_present) as total_stock,
    SUM(i.quantity_reserved) as total_reserved
FROM inventory i
WHERE i.quantity_present > 0
GROUP BY i.source, i.stock_type
ORDER BY i.source, i.stock_type;
```

### 5. Товары с низкими остатками
```sql
SELECT 
    p.name,
    p.sku_ozon,
    p.sku_wb,
    i.source,
    i.warehouse_name,
    i.quantity_present
FROM inventory i
JOIN dim_products p ON i.product_id = p.id
WHERE i.quantity_present > 0 AND i.quantity_present <= 10
ORDER BY i.quantity_present ASC;
```

---

## 🔧 PHP классы для работы с остатками

### Класс InventoryManager
```php
<?php
class InventoryManager {
    private $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Получить остатки товара по артикулу Ozon
     */
    public function getStockByOzonSku($sku_ozon) {
        $sql = "
            SELECT 
                i.warehouse_name,
                i.stock_type,
                i.quantity_present,
                i.quantity_reserved,
                i.updated_at
            FROM inventory i
            JOIN dim_products p ON i.product_id = p.id
            WHERE p.sku_ozon = ? AND i.source = 'Ozon'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$sku_ozon]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получить остатки товара по штрихкоду
     */
    public function getStockByBarcode($barcode) {
        $sql = "
            SELECT 
                p.name as product_name,
                i.source,
                i.warehouse_name,
                i.stock_type,
                i.quantity_present,
                i.quantity_reserved,
                i.updated_at
            FROM inventory i
            JOIN dim_products p ON i.product_id = p.id
            WHERE p.barcode = ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$barcode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получить товары с низкими остатками
     */
    public function getLowStockProducts($threshold = 10) {
        $sql = "
            SELECT 
                p.name,
                p.sku_ozon,
                p.sku_wb,
                p.barcode,
                i.source,
                i.warehouse_name,
                i.stock_type,
                i.quantity_present,
                i.updated_at
            FROM inventory i
            JOIN dim_products p ON i.product_id = p.id
            WHERE i.quantity_present > 0 AND i.quantity_present <= ?
            ORDER BY i.quantity_present ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$threshold]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получить сводку остатков по маркетплейсам
     */
    public function getStockSummary() {
        $sql = "
            SELECT 
                i.source,
                i.stock_type,
                COUNT(*) as products_count,
                SUM(i.quantity_present) as total_stock,
                SUM(i.quantity_reserved) as total_reserved,
                MAX(i.updated_at) as last_updated
            FROM inventory i
            WHERE i.quantity_present > 0
            GROUP BY i.source, i.stock_type
            ORDER BY i.source, i.stock_type
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверить наличие товара на складах
     */
    public function isProductInStock($product_identifier, $identifier_type = 'barcode') {
        $field_map = [
            'barcode' => 'p.barcode',
            'sku_ozon' => 'p.sku_ozon',
            'sku_wb' => 'p.sku_wb',
            'product_id' => 'p.id'
        ];
        
        if (!isset($field_map[$identifier_type])) {
            throw new InvalidArgumentException("Неподдерживаемый тип идентификатора");
        }
        
        $sql = "
            SELECT 
                SUM(i.quantity_present) as total_available
            FROM inventory i
            JOIN dim_products p ON i.product_id = p.id
            WHERE {$field_map[$identifier_type]} = ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$product_identifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($result['total_available'] ?? 0) > 0;
    }
}
?>
```

---

## 🌐 REST API примеры

### Создание простого API для остатков
```php
<?php
// api/inventory.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../classes/InventoryManager.php';

$inventory = new InventoryManager($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['barcode'])) {
                // GET /api/inventory?barcode=1234567890
                $result = $inventory->getStockByBarcode($_GET['barcode']);
            } elseif (isset($_GET['sku_ozon'])) {
                // GET /api/inventory?sku_ozon=ARTICLE123
                $result = $inventory->getStockByOzonSku($_GET['sku_ozon']);
            } elseif (isset($_GET['low_stock'])) {
                // GET /api/inventory?low_stock=10
                $threshold = intval($_GET['low_stock']);
                $result = $inventory->getLowStockProducts($threshold);
            } elseif (isset($_GET['summary'])) {
                // GET /api/inventory?summary=1
                $result = $inventory->getStockSummary();
            } else {
                throw new Exception("Не указан параметр запроса");
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Метод не поддерживается']);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
```

---

## 📱 Примеры использования

### 1. Проверка остатков при оформлении заказа
```php
<?php
$inventory = new InventoryManager($pdo);

// Проверяем наличие товара по штрихкоду
$barcode = '4600956005447';
if ($inventory->isProductInStock($barcode, 'barcode')) {
    echo "Товар в наличии";
    
    // Получаем детальную информацию об остатках
    $stock_details = $inventory->getStockByBarcode($barcode);
    foreach ($stock_details as $stock) {
        echo "Маркетплейс: {$stock['source']}, ";
        echo "Склад: {$stock['warehouse_name']}, ";
        echo "Доступно: {$stock['quantity_present']} шт.\n";
    }
} else {
    echo "Товар отсутствует на складах";
}
?>
```

### 2. Уведомления о низких остатках
```php
<?php
$inventory = new InventoryManager($pdo);

// Получаем товары с остатками менее 5 единиц
$low_stock_products = $inventory->getLowStockProducts(5);

if (!empty($low_stock_products)) {
    echo "⚠️ Товары с низкими остатками:\n";
    foreach ($low_stock_products as $product) {
        echo "- {$product['name']} ({$product['barcode']}): ";
        echo "{$product['quantity_present']} шт. на {$product['source']}\n";
    }
}
?>
```

### 3. Дашборд остатков
```php
<?php
$inventory = new InventoryManager($pdo);
$summary = $inventory->getStockSummary();

echo "<h2>Сводка остатков по маркетплейсам</h2>";
echo "<table border='1'>";
echo "<tr><th>Маркетплейс</th><th>Тип склада</th><th>Товаров</th><th>Остаток</th><th>Зарезервировано</th><th>Обновлено</th></tr>";

foreach ($summary as $row) {
    echo "<tr>";
    echo "<td>{$row['source']}</td>";
    echo "<td>{$row['stock_type']}</td>";
    echo "<td>{$row['products_count']}</td>";
    echo "<td>{$row['total_stock']}</td>";
    echo "<td>{$row['total_reserved']}</td>";
    echo "<td>{$row['last_updated']}</td>";
    echo "</tr>";
}
echo "</table>";
?>
```

---

## ⚡ Оптимизация производительности

### Рекомендуемые индексы
```sql
-- Индексы для быстрого поиска
CREATE INDEX idx_inventory_product_source ON inventory(product_id, source);
CREATE INDEX idx_inventory_quantity ON inventory(quantity_present);
CREATE INDEX idx_products_barcode ON dim_products(barcode);
CREATE INDEX idx_products_sku_ozon ON dim_products(sku_ozon);
CREATE INDEX idx_products_sku_wb ON dim_products(sku_wb);
```

### Кэширование
```php
<?php
// Пример кэширования с Redis
class CachedInventoryManager extends InventoryManager {
    private $redis;
    private $cache_ttl = 300; // 5 минут
    
    public function __construct(PDO $pdo, Redis $redis) {
        parent::__construct($pdo);
        $this->redis = $redis;
    }
    
    public function getStockByBarcode($barcode) {
        $cache_key = "stock:barcode:{$barcode}";
        
        // Проверяем кэш
        $cached = $this->redis->get($cache_key);
        if ($cached !== false) {
            return json_decode($cached, true);
        }
        
        // Получаем из БД
        $result = parent::getStockByBarcode($barcode);
        
        // Сохраняем в кэш
        $this->redis->setex($cache_key, $this->cache_ttl, json_encode($result));
        
        return $result;
    }
}
?>
```

---

## 🔄 Автоматическое обновление данных

### Информация о расписании обновлений:
- **Частота:** Данные обновляются автоматически через cron
- **Время обновления:** Еженедельно по понедельникам в 03:00
- **Источники:** Ozon (через API отчетов), Wildberries (через API складов)

### Проверка актуальности данных:
```php
<?php
function getLastUpdateTime($pdo) {
    $sql = "SELECT MAX(updated_at) as last_update FROM inventory";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['last_update'];
}

$last_update = getLastUpdateTime($pdo);
$hours_ago = (time() - strtotime($last_update)) / 3600;

if ($hours_ago > 24) {
    echo "⚠️ Данные устарели (обновлены {$hours_ago} часов назад)";
} else {
    echo "✅ Данные актуальны (обновлены {$hours_ago} часов назад)";
}
?>
```

---

## 🛠️ Отладка и мониторинг

### Логи системы
Логи ETL процессов находятся в:
- `/home/vladimir/mi_core_etl/logs/`
- Формат: `etl_run_YYYY-MM-DD.log`

### Проверка работоспособности
```php
<?php
function checkInventorySystemHealth($pdo) {
    $checks = [];
    
    // Проверяем подключение к БД
    try {
        $pdo->query("SELECT 1");
        $checks['database'] = '✅ OK';
    } catch (Exception $e) {
        $checks['database'] = '❌ ERROR: ' . $e->getMessage();
    }
    
    // Проверяем наличие данных
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory WHERE updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recent_records = $stmt->fetch()['count'];
    
    if ($recent_records > 0) {
        $checks['data_freshness'] = "✅ OK ({$recent_records} записей за неделю)";
    } else {
        $checks['data_freshness'] = '❌ Нет свежих данных';
    }
    
    return $checks;
}
?>
```

---

## 📞 Поддержка

### При возникновении проблем:
1. Проверьте логи ETL системы
2. Убедитесь в актуальности данных
3. Проверьте подключение к базе данных
4. Обратитесь к команде разработки ETL системы

### Контакты технической поддержки:
- **Документация:** См. файлы `TROUBLESHOOTING_GUIDE.md` и `SYSTEM_STATUS_REPORT.md`
- **Логи:** `/home/vladimir/mi_core_etl/logs/`

---

*Руководство подготовлено: 20 сентября 2025 г.*  
*Версия системы: Production Ready*
