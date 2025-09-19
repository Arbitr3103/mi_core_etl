# Система маржинальности - Руководство для PHP разработчика

## 🎯 Обзор системы

Полнофункциональная система анализа маржинальности для e-commerce проекта с автоматическим расчетом прибыльности товаров и детальной аналитикой.

### Ключевые возможности:
- ✅ **Автоматический расчет маржинальности** (выручка - себестоимость)
- ✅ **Детальная аналитика** по товарам, дням и клиентам
- ✅ **REST API** для интеграции с фронтендом
- ✅ **Готовые PHP классы** для быстрой разработки
- ✅ **Примеры интеграции** для JavaScript/jQuery

---

## 📊 Текущие показатели системы

### Статистика данных:
- **Общая выручка**: 1,482,031 руб. (за 14 дней)
- **Общая маржинальность**: 59.5%
- **Товаров с себестоимостью**: 56 из 271 (20.7%)
- **Средняя прибыль в день**: 63,015 руб.

### Лучшие товары по марже:
1. **Овсяные хлопья НТВ**: 79.7% маржинальность
2. **Мука сорго**: 77.5% маржинальность  
3. **Разрыхлитель**: 75.7% маржинальность

---

## 🚀 Быстрый старт

### 1. Подключение к базе данных

```php
<?php
// Скопируйте MarginAPI.php в ваш проект
require_once 'MarginAPI.php';

// Обновите настройки подключения в MarginDatabase
class MarginDatabase {
    private $host = '178.72.129.61';
    private $dbname = 'mi_core_db';
    private $username = 'your_username';  // ← Замените
    private $password = 'your_password';  // ← Замените
}
?>
```

### 2. Базовое использование

```php
<?php
try {
    $api = new MarginAPI();
    
    // Получить маржинальность за текущий месяц
    $summary = $api->getSummaryMargin(date('Y-m-01'), date('Y-m-d'));
    
    echo "Маржинальность: " . $summary['totals']['margin_percent'] . "%\n";
    echo "Прибыль: " . number_format($summary['totals']['profit'], 0) . " руб.\n";
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>
```

### 3. REST API эндпоинты

```bash
# Сводная маржинальность
GET /api/margins/summary?start_date=2025-09-01&end_date=2025-09-19

# Маржинальность по дням  
GET /api/margins/daily?start_date=2025-09-01&end_date=2025-09-19

# Топ товаров по марже
GET /api/margins/products/top?limit=20&min_revenue=1000

# Товары с низкой маржинальностью
GET /api/margins/products/low?threshold=15&min_revenue=1000

# Статистика покрытия
GET /api/margins/coverage
```

---

## 📁 Структура файлов

```
mi_core_etl/
├── PHP_MARGIN_API_GUIDE.md      # Подробная документация
├── MarginAPI.php                # Основной PHP класс
├── api_example.php              # REST API контроллер
├── frontend_examples.js         # JavaScript примеры
├── margin_reports.py            # Python отчеты (для справки)
├── recalculate_margins.py       # Пересчет данных
└── run_aggregation.py           # Автоматическая агрегация
```

---

## 🔧 Основные методы API

### MarginAPI::getSummaryMargin()
Получает сводную маржинальность за период.

```php
$summary = $api->getSummaryMargin('2025-09-01', '2025-09-19', $clientId);

// Результат:
[
    'period' => [
        'start_date' => '2025-09-01',
        'end_date' => '2025-09-19',
        'days_count' => 19
    ],
    'totals' => [
        'orders' => 4517,
        'revenue' => 1482031.0,
        'cogs' => 599817.0,
        'profit' => 882214.0,
        'margin_percent' => 59.5
    ],
    'averages' => [
        'daily_revenue' => 105859.0,
        'daily_profit' => 63015.0
    ]
]
```

### MarginAPI::getTopProductsByMargin()
Получает топ товаров по маржинальности.

```php
$products = $api->getTopProductsByMargin(10, null, null, 1000);

foreach ($products as $product) {
    echo sprintf(
        "%s: %s (%.1f%%)\n",
        $product['sku_ozon'],
        $product['product_name'],
        $product['margin_percent']
    );
}
```

### MarginAPI::getLowMarginProducts()
Находит товары с низкой маржинальностью.

```php
$lowMargin = $api->getLowMarginProducts(15.0, 1000.0, 10);

if (!empty($lowMargin)) {
    echo "⚠️ Товары требуют внимания:\n";
    foreach ($lowMargin as $product) {
        echo "- {$product['sku_ozon']}: {$product['margin_percent']}%\n";
    }
}
```

---

## 🌐 REST API интеграция

### Настройка API контроллера

1. Скопируйте `api_example.php` в папку `/api/margins.php`
2. Настройте веб-сервер для обработки запросов
3. Обновите настройки CORS при необходимости

### Пример AJAX запроса

```javascript
// Получение сводной маржинальности
fetch('/api/margins/summary?start_date=2025-09-01&end_date=2025-09-19')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Маржинальность:', data.data.totals.margin_percent + '%');
        }
    });
```

### Формат ответа API

```json
{
    "success": true,
    "data": {
        "totals": {
            "revenue": 1482031.0,
            "profit": 882214.0,
            "margin_percent": 59.5
        }
    },
    "timestamp": "2025-09-19T10:25:58+00:00"
}
```

---

## 📈 Интеграция с фронтендом

### Готовый дашборд (JavaScript)

```html
<!DOCTYPE html>
<html>
<head>
    <title>Дашборд маржинальности</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container mt-4">
        <h1>Анализ маржинальности</h1>
        <div id="margin-dashboard"></div>
    </div>
    
    <script src="frontend_examples.js"></script>
    <script>
        // Инициализация дашборда
        const dashboard = new MarginDashboard('margin-dashboard');
    </script>
</body>
</html>
```

### jQuery плагин

```javascript
$('#margin-dashboard').marginDashboard({
    autoRefresh: true,
    refreshInterval: 300000 // Обновление каждые 5 минут
});
```

---

## 🗄️ Структура базы данных

### Основные таблицы:

#### `metrics_daily` - Ежедневные метрики
```sql
- metric_date: DATE           # Дата
- revenue_sum: DECIMAL(18,4)  # Выручка
- cogs_sum: DECIMAL(18,4)     # Себестоимость  
- profit_sum: DECIMAL(18,4)   # Прибыль
- orders_cnt: INT             # Количество заказов
```

#### `dim_products` - Справочник товаров
```sql
- sku_ozon: VARCHAR(255)      # Артикул Ozon
- sku_wb: VARCHAR(255)        # Артикул Wildberries
- barcode: VARCHAR(255)       # Штрихкод
- product_name: VARCHAR(500)  # Название товара
- cost_price: DECIMAL(10,2)   # Себестоимость
```

#### `fact_orders` - Детальные заказы
```sql
- order_id: VARCHAR(255)      # ID заказа
- sku: VARCHAR(255)           # Артикул товара
- qty: INT                    # Количество
- price: DECIMAL(10,2)        # Цена за единицу
- order_date: DATE            # Дата заказа
- transaction_type: VARCHAR   # Тип операции
```

---

## ⚙️ Автоматизация и обновление данных

### Автоматический расчет маржинальности

Система автоматически рассчитывает маржинальность при:
- Новых продажах (через ETL процесс)
- Обновлении себестоимости товаров
- Ежедневной агрегации метрик (cron: 3:00 утра)

### Пересчет исторических данных

```bash
# На сервере (при необходимости)
cd /home/vladimir/mi_core_etl
python3 recalculate_margins.py
```

### Обновление себестоимости

```bash
# Загрузка нового прайс-листа
scp cost_price.xlsx vladimir@178.72.129.61:/home/vladimir/mi_core_etl/data/
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && ./run_cost_import.sh"
```

---

## 🎨 Примеры использования

### 1. Виджет маржинальности для админки

```php
<?php
class MarginWidget {
    private $api;
    
    public function __construct() {
        $this->api = new MarginAPI();
    }
    
    public function render() {
        $summary = $this->api->getSummaryMargin(date('Y-m-01'), date('Y-m-d'));
        $margin = $summary['totals']['margin_percent'];
        
        $color = $margin >= 50 ? 'success' : ($margin >= 30 ? 'warning' : 'danger');
        
        echo "<div class='alert alert-{$color}'>";
        echo "<h4>Маржинальность за месяц: {$margin}%</h4>";
        echo "<p>Прибыль: " . number_format($summary['totals']['profit'], 0) . " руб.</p>";
        echo "</div>";
    }
}
?>
```

### 2. Отчет по товарам

```php
<?php
function generateProductReport() {
    $api = new MarginAPI();
    $products = $api->getTopProductsByMargin(20);
    
    echo "<table class='table'>";
    echo "<tr><th>Товар</th><th>Выручка</th><th>Маржа</th></tr>";
    
    foreach ($products as $product) {
        $marginClass = $product['margin_percent'] >= 50 ? 'text-success' : 'text-warning';
        echo "<tr>";
        echo "<td>{$product['sku_ozon']}</td>";
        echo "<td>" . number_format($product['revenue'], 0) . " руб.</td>";
        echo "<td class='{$marginClass}'>{$product['margin_percent']}%</td>";
        echo "</tr>";
    }
    
    echo "</table>";
}
?>
```

### 3. API для мобильного приложения

```php
<?php
// mobile_api.php
header('Content-Type: application/json');

$api = new MarginAPI();

switch ($_GET['action']) {
    case 'summary':
        $data = $api->getSummaryMargin(date('Y-m-01'), date('Y-m-d'));
        break;
        
    case 'top_products':
        $data = $api->getTopProductsByMargin(10);
        break;
        
    default:
        $data = ['error' => 'Unknown action'];
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
?>
```

---

## 🔍 Мониторинг и алерты

### Проверка товаров с низкой маржинальностью

```php
<?php
function checkLowMarginAlerts() {
    $api = new MarginAPI();
    $lowMargin = $api->getLowMarginProducts(15.0, 1000.0, 10);
    
    if (!empty($lowMargin)) {
        // Отправить уведомление
        $message = "Найдено " . count($lowMargin) . " товаров с низкой маржинальностью";
        
        // Email, Slack, Telegram и т.д.
        sendAlert($message, $lowMargin);
    }
}

// Запуск через cron каждый день
// 0 9 * * * php /path/to/check_alerts.php
?>
```

### Мониторинг покрытия себестоимостью

```php
<?php
function checkCoverageStatus() {
    $api = new MarginAPI();
    $coverage = $api->getCostCoverageStats();
    
    if ($coverage['coverage_percent'] < 50) {
        $message = "Низкое покрытие себестоимостью: {$coverage['coverage_percent']}%";
        sendAlert($message);
    }
}
?>
```

---

## 📞 Поддержка и развитие

### Текущий статус системы:
- ✅ **Полностью функциональна** и готова к использованию
- ✅ **Автоматические расчеты** работают корректно
- ✅ **API протестирован** и стабилен
- ✅ **Документация** полная и актуальная

### Возможности для развития:
- 📈 Прогнозирование маржинальности
- 📊 Дополнительные метрики (ROI, ROAS)
- 🎯 Персонализированные дашборды
- 📱 Мобильное приложение
- 🤖 Автоматические рекомендации по ценообразованию

### При возникновении вопросов:
1. Проверьте подключение к базе данных
2. Убедитесь в корректности параметров запросов
3. Проверьте логи системы на сервере
4. Обратитесь к разработчикам ETL системы

---

**Система маржинальности готова к продуктивному использованию!** 🚀

*Последнее обновление: 19 сентября 2025 г.*
