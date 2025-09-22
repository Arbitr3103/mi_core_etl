# Руководство по созданию дашбордов маржинальности на PHP

**ETL система управления остатками товаров**

---

## 📋 Обзор

Данное руководство поможет PHP разработчику создать дашборды для отображения данных маржинальности, рассчитанных ETL системой. Все данные хранятся в таблице `metrics_daily` и готовы для использования.

---

## 🗄️ Структура данных

### Таблица `metrics_daily`

| Поле                 | Тип           | Описание                   |
| -------------------- | ------------- | -------------------------- |
| `client_id`          | INT           | ID клиента                 |
| `metric_date`        | DATE          | Дата метрик                |
| `orders_cnt`         | INT           | Количество заказов         |
| `revenue_sum`        | DECIMAL(18,4) | Общая выручка              |
| `returns_sum`        | DECIMAL(18,4) | Сумма возвратов            |
| `cogs_sum`           | DECIMAL(18,4) | Себестоимость товаров      |
| `commission_sum`     | DECIMAL(18,4) | Комиссии маркетплейса      |
| `shipping_sum`       | DECIMAL(18,4) | Расходы на логистику       |
| `other_expenses_sum` | DECIMAL(18,4) | Прочие расходы             |
| `profit_sum`         | DECIMAL(18,4) | **Чистая прибыль**         |
| `margin_percent`     | DECIMAL(8,4)  | **Процент маржинальности** |

### Связанные таблицы

```sql
-- Клиенты
SELECT id, name FROM clients;

-- Источники данных
SELECT id, code, name FROM sources;
```

---

## 🚀 PHP API класс

Создайте файл `MarginDashboardAPI.php`:

```php
<?php
/**
 * API класс для работы с данными маржинальности
 * Предоставляет методы для получения данных для дашбордов
 */

class MarginDashboardAPI {
    private $pdo;

    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }

    /**
     * Получить общую статистику маржинальности за период
     */
    public function getMarginSummary($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT
                COUNT(DISTINCT metric_date) as days_count,
                SUM(orders_cnt) as total_orders,
                SUM(revenue_sum) as total_revenue,
                SUM(cogs_sum) as total_cogs,
                SUM(commission_sum) as total_commission,
                SUM(shipping_sum) as total_shipping,
                SUM(other_expenses_sum) as total_other_expenses,
                SUM(profit_sum) as total_profit,
                CASE
                    WHEN SUM(revenue_sum) > 0
                    THEN ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2)
                    ELSE NULL
                END as avg_margin_percent,
                MIN(metric_date) as period_start,
                MAX(metric_date) as period_end
            FROM metrics_daily
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch();
    }

    /**
     * Получить данные маржинальности по дням для графика
     */
    public function getDailyMarginChart($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT
                metric_date,
                SUM(revenue_sum) as revenue,
                SUM(profit_sum) as profit,
                CASE
                    WHEN SUM(revenue_sum) > 0
                    THEN ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2)
                    ELSE NULL
                END as margin_percent,
                SUM(orders_cnt) as orders_count
            FROM metrics_daily
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }

        $sql .= " GROUP BY metric_date ORDER BY metric_date";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Получить структуру расходов (breakdown)
     */
    public function getCostBreakdown($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT
                SUM(revenue_sum) as revenue,
                SUM(cogs_sum) as cogs,
                SUM(commission_sum) as commission,
                SUM(shipping_sum) as shipping,
                SUM(other_expenses_sum) as other_expenses,
                SUM(profit_sum) as profit,
                -- Проценты от выручки
                CASE WHEN SUM(revenue_sum) > 0 THEN
                    ROUND(SUM(cogs_sum) * 100.0 / SUM(revenue_sum), 2)
                ELSE 0 END as cogs_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN
                    ROUND(SUM(commission_sum) * 100.0 / SUM(revenue_sum), 2)
                ELSE 0 END as commission_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN
                    ROUND(SUM(shipping_sum) * 100.0 / SUM(revenue_sum), 2)
                ELSE 0 END as shipping_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN
                    ROUND(SUM(other_expenses_sum) * 100.0 / SUM(revenue_sum), 2)
                ELSE 0 END as other_expenses_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN
                    ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2)
                ELSE 0 END as profit_percent
            FROM metrics_daily
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch();
    }

    /**
     * Получить KPI метрики
     */
    public function getKPIMetrics($startDate, $endDate, $clientId = null) {
        $summary = $this->getMarginSummary($startDate, $endDate, $clientId);

        return [
            'total_revenue' => [
                'value' => number_format($summary['total_revenue'], 2),
                'label' => 'Общая выручка',
                'format' => 'currency'
            ],
            'total_profit' => [
                'value' => number_format($summary['total_profit'], 2),
                'label' => 'Чистая прибыль',
                'format' => 'currency'
            ],
            'avg_margin_percent' => [
                'value' => $summary['avg_margin_percent'],
                'label' => 'Средняя маржинальность',
                'format' => 'percent'
            ],
            'total_orders' => [
                'value' => number_format($summary['total_orders']),
                'label' => 'Всего заказов',
                'format' => 'number'
            ]
        ];
    }

    /**
     * Получить список клиентов
     */
    public function getClients() {
        $sql = "SELECT id, name FROM clients ORDER BY name";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }
}
?>
```

---

## 📊 Примеры использования

### 1. Основной дашборд

Создайте файл `dashboard.php`:

```php
<?php
require_once 'MarginDashboardAPI.php';

// Подключение к БД
$api = new MarginDashboardAPI('localhost', 'mi_core_db', 'username', 'password');

// Параметры периода
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // Начало месяца
$endDate = $_GET['end_date'] ?? date('Y-m-d');     // Сегодня
$clientId = $_GET['client_id'] ?? null;

// Получаем данные
$kpiMetrics = $api->getKPIMetrics($startDate, $endDate, $clientId);
$chartData = $api->getDailyMarginChart($startDate, $endDate, $clientId);
$costBreakdown = $api->getCostBreakdown($startDate, $endDate, $clientId);
$clients = $api->getClients();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Дашборд маржинальности</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <h1>Дашборд маржинальности</h1>

        <!-- Фильтры -->
        <div class="row mb-4">
            <div class="col-md-12">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Начальная дата</label>
                        <input type="date" class="form-control" name="start_date" value="<?= $startDate ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Конечная дата</label>
                        <input type="date" class="form-control" name="end_date" value="<?= $endDate ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Клиент</label>
                        <select class="form-control" name="client_id">
                            <option value="">Все клиенты</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>" <?= $clientId == $client['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($client['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block">Применить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- KPI метрики -->
        <div class="row mb-4">
            <?php foreach ($kpiMetrics as $metric): ?>
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title"><?= $metric['label'] ?></h5>
                            <h3 class="text-primary">
                                <?= $metric['value'] ?>
                                <?= $metric['format'] === 'percent' ? '%' : '' ?>
                                <?= $metric['format'] === 'currency' ? ' ₽' : '' ?>
                            </h3>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Графики -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Динамика маржинальности</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="marginChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Структура расходов</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="costChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // График динамики маржинальности
        const marginCtx = document.getElementById('marginChart').getContext('2d');
        const marginChart = new Chart(marginCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($chartData, 'metric_date')) ?>,
                datasets: [{
                    label: 'Выручка',
                    data: <?= json_encode(array_column($chartData, 'revenue')) ?>,
                    borderColor: 'rgb(75, 192, 192)',
                    yAxisID: 'y'
                }, {
                    label: 'Прибыль',
                    data: <?= json_encode(array_column($chartData, 'profit')) ?>,
                    borderColor: 'rgb(255, 99, 132)',
                    yAxisID: 'y'
                }, {
                    label: 'Маржинальность (%)',
                    data: <?= json_encode(array_column($chartData, 'margin_percent')) ?>,
                    borderColor: 'rgb(54, 162, 235)',
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // График структуры расходов
        const costCtx = document.getElementById('costChart').getContext('2d');
        const costChart = new Chart(costCtx, {
            type: 'doughnut',
            data: {
                labels: ['Себестоимость', 'Комиссии', 'Логистика', 'Прочие расходы', 'Прибыль'],
                datasets: [{
                    data: [
                        <?= $costBreakdown['cogs'] ?>,
                        <?= $costBreakdown['commission'] ?>,
                        <?= $costBreakdown['shipping'] ?>,
                        <?= $costBreakdown['other_expenses'] ?>,
                        <?= $costBreakdown['profit'] ?>
                    ],
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#FF9F40',
                        '#4BC0C0'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
```

### 2. API для AJAX запросов

Создайте файл `api.php`:

```php
<?php
header('Content-Type: application/json');
require_once 'MarginDashboardAPI.php';

try {
    $api = new MarginDashboardAPI('localhost', 'mi_core_db', 'username', 'password');

    $action = $_GET['action'] ?? '';
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $clientId = $_GET['client_id'] ?? null;

    switch ($action) {
        case 'summary':
            $data = $api->getMarginSummary($startDate, $endDate, $clientId);
            break;

        case 'chart':
            $data = $api->getDailyMarginChart($startDate, $endDate, $clientId);
            break;

        case 'breakdown':
            $data = $api->getCostBreakdown($startDate, $endDate, $clientId);
            break;

        case 'kpi':
            $data = $api->getKPIMetrics($startDate, $endDate, $clientId);
            break;

        case 'clients':
            $data = $api->getClients();
            break;

        default:
            throw new Exception('Неизвестное действие');
    }

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
```

### 3. Использование AJAX

```javascript
// Получение данных через AJAX
function loadMarginData(startDate, endDate, clientId = null) {
  const params = new URLSearchParams({
    action: "summary",
    start_date: startDate,
    end_date: endDate,
  });

  if (clientId) {
    params.append("client_id", clientId);
  }

  fetch(`api.php?${params}`)
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        updateDashboard(data.data);
      } else {
        console.error("Ошибка:", data.error);
      }
    })
    .catch((error) => {
      console.error("Ошибка запроса:", error);
    });
}

function updateDashboard(data) {
  // Обновление KPI метрик
  document.getElementById("total-revenue").textContent = new Intl.NumberFormat(
    "ru-RU",
    { style: "currency", currency: "RUB" }
  ).format(data.total_revenue);

  document.getElementById("total-profit").textContent = new Intl.NumberFormat(
    "ru-RU",
    { style: "currency", currency: "RUB" }
  ).format(data.total_profit);

  document.getElementById("margin-percent").textContent =
    data.avg_margin_percent + "%";
}
```

---

## 📈 Готовые SQL запросы

### Топ дней по маржинальности

```sql
SELECT
    metric_date,
    SUM(revenue_sum) as revenue,
    SUM(profit_sum) as profit,
    ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2) as margin_percent
FROM metrics_daily
WHERE metric_date BETWEEN '2024-09-01' AND '2024-09-30'
    AND revenue_sum > 0
GROUP BY metric_date
ORDER BY margin_percent DESC
LIMIT 10;
```

### Сравнение периодов

```sql
SELECT
    'Текущий период' as period,
    SUM(revenue_sum) as revenue,
    SUM(profit_sum) as profit,
    ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2) as margin_percent
FROM metrics_daily
WHERE metric_date BETWEEN '2024-09-01' AND '2024-09-30'

UNION ALL

SELECT
    'Предыдущий период' as period,
    SUM(revenue_sum) as revenue,
    SUM(profit_sum) as profit,
    ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2) as margin_percent
FROM metrics_daily
WHERE metric_date BETWEEN '2024-08-01' AND '2024-08-31';
```

### Анализ по клиентам

```sql
SELECT
    c.name as client_name,
    SUM(md.revenue_sum) as total_revenue,
    SUM(md.profit_sum) as total_profit,
    ROUND(SUM(md.profit_sum) * 100.0 / SUM(md.revenue_sum), 2) as margin_percent,
    COUNT(DISTINCT md.metric_date) as active_days
FROM metrics_daily md
JOIN clients c ON md.client_id = c.id
WHERE md.metric_date BETWEEN '2024-09-01' AND '2024-09-30'
GROUP BY c.id, c.name
ORDER BY margin_percent DESC;
```

---

## 🎨 CSS стили для дашборда

```css
/* dashboard.css */
.kpi-card {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 20px;
}

.kpi-value {
  font-size: 2.5rem;
  font-weight: bold;
  margin-bottom: 5px;
}

.kpi-label {
  font-size: 0.9rem;
  opacity: 0.8;
}

.chart-container {
  position: relative;
  height: 400px;
  margin-bottom: 20px;
}

.margin-positive {
  color: #28a745;
}

.margin-negative {
  color: #dc3545;
}

.table-margin {
  font-weight: bold;
}
```

---

## 🔧 Настройка подключения к БД

Создайте файл `config.php`:

```php
<?php
// Настройки подключения к базе данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core_db');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');

// Настройки приложения
define('DEFAULT_TIMEZONE', 'Europe/Moscow');
define('DATE_FORMAT', 'd.m.Y');
define('CURRENCY_SYMBOL', '₽');

// Устанавливаем временную зону
date_default_timezone_set(DEFAULT_TIMEZONE);
?>
```

---

## 📱 Адаптивность

Для мобильных устройств добавьте:

```css
@media (max-width: 768px) {
  .kpi-card {
    margin-bottom: 15px;
  }

  .kpi-value {
    font-size: 1.8rem;
  }

  .chart-container {
    height: 300px;
  }
}
```

---

## 🚀 Развертывание

1. **Скопируйте файлы** на веб-сервер
2. **Настройте подключение** к БД в `config.php`
3. **Установите зависимости** (Bootstrap, Chart.js)
4. **Настройте права доступа** к файлам
5. **Протестируйте** дашборд

---

## 📊 Примеры виджетов

### Виджет KPI

```php
function renderKPIWidget($title, $value, $format = 'number', $change = null) {
    $formattedValue = $value;

    switch ($format) {
        case 'currency':
            $formattedValue = number_format($value, 2) . ' ₽';
            break;
        case 'percent':
            $formattedValue = number_format($value, 2) . '%';
            break;
        case 'number':
            $formattedValue = number_format($value);
            break;
    }

    $changeClass = '';
    $changeIcon = '';

    if ($change !== null) {
        $changeClass = $change >= 0 ? 'text-success' : 'text-danger';
        $changeIcon = $change >= 0 ? '↑' : '↓';
    }

    echo "
    <div class='col-md-3'>
        <div class='card kpi-card'>
            <div class='card-body text-center'>
                <h5 class='card-title'>$title</h5>
                <h3 class='kpi-value'>$formattedValue</h3>
                " . ($change !== null ? "<small class='$changeClass'>$changeIcon " . abs($change) . "%</small>" : "") . "
            </div>
        </div>
    </div>
    ";
}
```

---

## 🔍 Отладка и мониторинг

### Логирование запросов

```php
class MarginDashboardAPI {
    private $logFile = 'margin_api.log';

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    public function getMarginSummary($startDate, $endDate, $clientId = null) {
        $this->log("Getting margin summary: $startDate to $endDate, client: $clientId");

        // ... остальной код

        $this->log("Margin summary result: " . json_encode($result));
        return $result;
    }
}
```

### Проверка производительности

```php
function measureExecutionTime($callback) {
    $start = microtime(true);
    $result = $callback();
    $end = microtime(true);

    $executionTime = ($end - $start) * 1000; // в миллисекундах
    error_log("Execution time: {$executionTime}ms");

    return $result;
}

// Использование
$data = measureExecutionTime(function() use ($api, $startDate, $endDate) {
    return $api->getMarginSummary($startDate, $endDate);
});
```

---


_Руководство подготовлено: 22 сентября 2025 г._  
_Версия: 1.0_
