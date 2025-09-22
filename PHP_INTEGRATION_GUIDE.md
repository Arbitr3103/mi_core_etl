# 🐘 PHP интеграция системы пополнения склада

Подробное руководство для PHP разработчиков по интеграции системы пополнения склада на веб-сайт.

## 🚀 Быстрый старт

### 1. Базовый PHP клиент для API

```php
<?php
class ReplenishmentAPIClient {
    private $baseUrl;
    private $timeout;

    public function __construct($baseUrl = 'http://localhost:8000', $timeout = 30) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Проверка здоровья системы
     */
    public function getHealth() {
        return $this->makeRequest('GET', '/api/health');
    }

    /**
     * Получение рекомендаций по пополнению
     */
    public function getRecommendations($limit = 50, $priority = null, $source = null) {
        $params = ['limit' => $limit];
        if ($priority) $params['priority'] = $priority;
        if ($source) $params['source'] = $source;

        $url = '/api/recommendations?' . http_build_query($params);
        return $this->makeRequest('GET', $url);
    }

    /**
     * Получение алертов
     */
    public function getAlerts($limit = 50) {
        $url = '/api/alerts?' . http_build_query(['limit' => $limit]);
        return $this->makeRequest('GET', $url);
    }

    /**
     * Запуск анализа
     */
    public function runAnalysis($options = []) {
        return $this->makeRequest('POST', '/api/analysis/run', $options);
    }

    /**
     * Подтверждение алерта
     */
    public function acknowledgeAlert($alertId, $acknowledgedBy = 'PHP Client') {
        $data = ['acknowledged_by' => $acknowledgedBy];
        return $this->makeRequest('POST', "/api/alerts/{$alertId}/acknowledge", $data);
    }

    /**
     * Выполнение HTTP запроса
     */
    private function makeRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        $decodedResponse = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = $decodedResponse['error'] ?? 'Unknown error';
            throw new Exception("API Error ({$httpCode}): " . $errorMsg);
        }

        return $decodedResponse;
    }
}
?>
```

### 2. Пример использования клиента

```php
<?php
require_once 'ReplenishmentAPIClient.php';

try {
    // Создаем клиент
    $client = new ReplenishmentAPIClient('http://your-server:8000');

    // Проверяем здоровье системы
    $health = $client->getHealth();
    echo "Статус системы: " . $health['status'] . "\n";

    // Получаем критические рекомендации
    $recommendations = $client->getRecommendations(10, 'CRITICAL');
    echo "Критических рекомендаций: " . $recommendations['total_count'] . "\n";

    // Получаем алерты
    $alerts = $client->getAlerts(20);
    echo "Активных алертов: " . $alerts['total_count'] . "\n";

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>
```

## 🎨 Веб-интерфейс на PHP

### 3. Страница дашборда склада

```php
<?php
// dashboard.php
require_once 'ReplenishmentAPIClient.php';

$client = new ReplenishmentAPIClient('http://your-server:8000');
$error = null;
$data = [];

try {
    // Получаем данные для дашборда
    $data['health'] = $client->getHealth();
    $data['recommendations'] = $client->getRecommendations(20, 'CRITICAL');
    $data['alerts'] = $client->getAlerts(10);

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд пополнения склада</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .metric-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .critical { color: #dc3545; font-weight: bold; }
        .high { color: #fd7e14; font-weight: bold; }
        .medium { color: #ffc107; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <h1 class="mb-4">📦 Дашборд пополнения склада</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php else: ?>

            <!-- Статус системы -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card metric-card text-white">
                        <div class="card-body text-center">
                            <h2><?= $data['health']['status'] === 'healthy' ? '✅' : '⚠️' ?></h2>
                            <h5>Статус системы</h5>
                            <p><?= ucfirst($data['health']['status']) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body text-center">
                            <h2><?= $data['recommendations']['total_count'] ?></h2>
                            <h5>Критических</h5>
                            <p>рекомендаций</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-dark">
                        <div class="card-body text-center">
                            <h2><?= $data['alerts']['total_count'] ?></h2>
                            <h5>Активных</h5>
                            <p>алертов</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h2><?= date('H:i') ?></h2>
                            <h5>Последнее</h5>
                            <p>обновление</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Критические рекомендации -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5>🚨 Критические рекомендации по пополнению</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($data['recommendations']['recommendations'])): ?>
                                <p class="text-muted">Нет критических рекомендаций</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Товар</th>
                                                <th>Остаток</th>
                                                <th>К заказу</th>
                                                <th>Дней до исчерпания</th>
                                                <th>Срочность</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($data['recommendations']['recommendations'] as $rec): ?>
                                                <tr>
                                                    <td><code><?= htmlspecialchars($rec['sku']) ?></code></td>
                                                    <td><?= htmlspecialchars(substr($rec['product_name'], 0, 40)) ?></td>
                                                    <td><?= $rec['current_stock'] ?> шт</td>
                                                    <td class="critical"><?= $rec['recommended_order_quantity'] ?> шт</td>
                                                    <td><?= $rec['days_until_stockout'] ?? 'Н/Д' ?></td>
                                                    <td><span class="badge bg-danger"><?= $rec['urgency_score'] ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Алерты -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>🔔 Активные алерты</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($data['alerts']['alerts'])): ?>
                                <p class="text-muted">Нет активных алертов</p>
                            <?php else: ?>
                                <?php foreach ($data['alerts']['alerts'] as $alert): ?>
                                    <div class="alert alert-<?= $alert['alert_level'] === 'CRITICAL' ? 'danger' : 'warning' ?> alert-sm">
                                        <strong><?= htmlspecialchars($alert['sku']) ?></strong><br>
                                        <small><?= htmlspecialchars($alert['message']) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- Кнопки управления -->
        <div class="row mt-4">
            <div class="col-12">
                <button class="btn btn-primary" onclick="runAnalysis()">🔄 Запустить анализ</button>
                <button class="btn btn-success" onclick="location.reload()">🔄 Обновить данные</button>
                <a href="reports.php" class="btn btn-info">📊 Отчеты</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function runAnalysis() {
            if (confirm('Запустить анализ пополнения склада?')) {
                fetch('ajax_run_analysis.php', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Анализ запущен успешно!');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            alert('Ошибка: ' + data.error);
                        }
                    })
                    .catch(error => alert('Ошибка сети: ' + error));
            }
        }

        // Автообновление каждые 5 минут
        setTimeout(() => location.reload(), 300000);
    </script>
</body>
</html>
```

### 4. AJAX обработчик для запуска анализа

```php
<?php
// ajax_run_analysis.php
header('Content-Type: application/json');
require_once 'ReplenishmentAPIClient.php';

try {
    $client = new ReplenishmentAPIClient('http://your-server:8000');
    $result = $client->runAnalysis();

    echo json_encode([
        'success' => true,
        'data' => $result
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

### 5. Страница отчетов

```php
<?php
// reports.php
require_once 'ReplenishmentAPIClient.php';

$client = new ReplenishmentAPIClient('http://your-server:8000');
$report = null;
$error = null;

try {
    $report = $client->makeRequest('GET', '/api/reports/comprehensive');
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчеты по складу</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container py-4">
        <h1>📊 Отчеты по складу</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger">Ошибка: <?= htmlspecialchars($error) ?></div>
        <?php elseif ($report): ?>

            <!-- Метрики запасов -->
            <div class="row mb-4">
                <div class="col-12">
                    <h3>📦 Метрики запасов</h3>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= number_format($report['inventory_metrics']['total_products']) ?></h4>
                            <p>Всего товаров</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= number_format($report['inventory_metrics']['total_inventory_value'], 0, ',', ' ') ?> ₽</h4>
                            <p>Стоимость запасов</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= $report['inventory_metrics']['low_stock_products'] ?></h4>
                            <p>Низкий остаток</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= $report['inventory_metrics']['total_recommended_orders'] ?></h4>
                            <p>К пополнению</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Метрики продаж -->
            <div class="row mb-4">
                <div class="col-12">
                    <h3>📈 Метрики продаж</h3>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= number_format($report['sales_metrics']['total_sales_volume_30d']) ?></h4>
                            <p>Продаж за 30 дней</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= $report['sales_metrics']['fast_moving_products'] ?></h4>
                            <p>Быстро движущихся</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= $report['sales_metrics']['slow_moving_products'] ?></h4>
                            <p>Медленно движущихся</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4><?= $report['sales_metrics']['no_sales_products'] ?></h4>
                            <p>Без продаж</p>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <a href="dashboard.php" class="btn btn-primary">← Назад к дашборду</a>
    </div>
</body>
</html>
```

## 🔧 Интеграция с существующей системой

### 6. Класс для работы с базой данных

```php
<?php
class InventoryIntegration {
    private $pdo;
    private $apiClient;

    public function __construct($dbConfig, $apiUrl) {
        // Подключение к базе данных
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // API клиент
        $this->apiClient = new ReplenishmentAPIClient($apiUrl);
    }

    /**
     * Синхронизация данных о товарах с системой пополнения
     */
    public function syncProductData() {
        try {
            // Получаем товары из нашей системы
            $stmt = $this->pdo->query("
                SELECT product_id, sku, name, current_stock, reserved_stock, cost_price
                FROM products
                WHERE is_active = 1
            ");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Здесь можно отправить данные в систему пополнения
            // или получить рекомендации для наших товаров

            return count($products);

        } catch (Exception $e) {
            error_log("Ошибка синхронизации: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение рекомендаций для конкретного товара
     */
    public function getProductRecommendation($productId) {
        try {
            return $this->apiClient->makeRequest('GET', "/api/recommendations/{$productId}");
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Обновление минимальных остатков на основе рекомендаций
     */
    public function updateMinStockLevels() {
        try {
            $recommendations = $this->apiClient->getRecommendations(1000);
            $updated = 0;

            foreach ($recommendations['recommendations'] as $rec) {
                $stmt = $this->pdo->prepare("
                    UPDATE products
                    SET min_stock_level = ?,
                        updated_at = NOW()
                    WHERE sku = ?
                ");

                if ($stmt->execute([$rec['min_stock_level'], $rec['sku']])) {
                    $updated++;
                }
            }

            return $updated;

        } catch (Exception $e) {
            error_log("Ошибка обновления минимальных остатков: " . $e->getMessage());
            return false;
        }
    }
}
?>
```

### 7. Cron задача для автоматической синхронизации

```php
<?php
// cron_sync.php - запускать каждый час через cron
require_once 'ReplenishmentAPIClient.php';
require_once 'InventoryIntegration.php';

$dbConfig = [
    'host' => 'localhost',
    'database' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password'
];

$integration = new InventoryIntegration($dbConfig, 'http://your-server:8000');

try {
    echo "[" . date('Y-m-d H:i:s') . "] Начало синхронизации\n";

    // Синхронизируем данные
    $synced = $integration->syncProductData();
    echo "Синхронизировано товаров: {$synced}\n";

    // Обновляем минимальные остатки
    $updated = $integration->updateMinStockLevels();
    echo "Обновлено минимальных остатков: {$updated}\n";

    echo "Синхронизация завершена успешно\n";

} catch (Exception $e) {
    echo "Ошибка синхронизации: " . $e->getMessage() . "\n";
    error_log("Cron sync error: " . $e->getMessage());
}
?>
```

### 8. Виджет для админ-панели

```php
<?php
// widget_inventory_status.php
function renderInventoryWidget() {
    try {
        $client = new ReplenishmentAPIClient('http://your-server:8000');
        $health = $client->getHealth();
        $recommendations = $client->getRecommendations(5, 'CRITICAL');
        $alerts = $client->getAlerts(3);

        ?>
        <div class="inventory-widget">
            <div class="widget-header">
                <h4>📦 Состояние склада</h4>
                <span class="status-badge <?= $health['status'] === 'healthy' ? 'success' : 'warning' ?>">
                    <?= ucfirst($health['status']) ?>
                </span>
            </div>

            <div class="widget-content">
                <div class="metric">
                    <span class="value"><?= $recommendations['total_count'] ?></span>
                    <span class="label">Критических товаров</span>
                </div>

                <div class="metric">
                    <span class="value"><?= $alerts['total_count'] ?></span>
                    <span class="label">Активных алертов</span>
                </div>

                <?php if (!empty($recommendations['recommendations'])): ?>
                    <div class="urgent-items">
                        <h5>Требуют внимания:</h5>
                        <?php foreach (array_slice($recommendations['recommendations'], 0, 3) as $rec): ?>
                            <div class="urgent-item">
                                <strong><?= htmlspecialchars($rec['sku']) ?></strong>
                                <span class="stock"><?= $rec['current_stock'] ?> шт</span>
                                <span class="action">Заказать: <?= $rec['recommended_order_quantity'] ?> шт</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="widget-actions">
                    <a href="dashboard.php" class="btn btn-sm btn-primary">Подробнее</a>
                    <button onclick="runQuickAnalysis()" class="btn btn-sm btn-success">Анализ</button>
                </div>
            </div>
        </div>

        <style>
        .inventory-widget {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-badge.success { background: #d4edda; color: #155724; }
        .status-badge.warning { background: #fff3cd; color: #856404; }
        .metric {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .metric .value {
            font-weight: bold;
            color: #dc3545;
        }
        .urgent-items {
            margin: 15px 0;
        }
        .urgent-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 12px;
        }
        .widget-actions {
            margin-top: 15px;
            text-align: center;
        }
        </style>

        <script>
        function runQuickAnalysis() {
            fetch('ajax_run_analysis.php', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Анализ запущен!');
                        setTimeout(() => location.reload(), 3000);
                    } else {
                        alert('Ошибка: ' + data.error);
                    }
                });
        }
        </script>
        <?php

    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Ошибка загрузки данных склада: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Использование в админ-панели:
// renderInventoryWidget();
?>
```

## 🛠️ Настройка и конфигурация

### 9. Конфигурационный файл

```php
<?php
// config/replenishment.php
return [
    'api' => [
        'base_url' => env('REPLENISHMENT_API_URL', 'http://localhost:8000'),
        'timeout' => 30,
        'retry_attempts' => 3,
    ],

    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'database' => env('DB_DATABASE', 'your_database'),
        'username' => env('DB_USERNAME', 'your_username'),
        'password' => env('DB_PASSWORD', 'your_password'),
    ],

    'sync' => [
        'enabled' => true,
        'interval' => 3600, // 1 час
        'batch_size' => 1000,
    ],

    'notifications' => [
        'email_enabled' => true,
        'email_recipients' => ['manager@company.com', 'warehouse@company.com'],
        'webhook_url' => env('SLACK_WEBHOOK_URL', ''),
    ],

    'thresholds' => [
        'critical_days' => 3,
        'warning_days' => 7,
        'slow_moving_days' => 30,
    ]
];
?>
```

### 10. Обработка ошибок и логирование

```php
<?php
class ReplenishmentLogger {
    private $logFile;

    public function __construct($logFile = 'replenishment.log') {
        $this->logFile = $logFile;
    }

    public function log($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logEntry = "[{$timestamp}] {$level}: {$message} {$contextStr}\n";

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }

    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log('WARNING', $message, $context);
    }
}

// Улучшенный API клиент с логированием
class EnhancedReplenishmentAPIClient extends ReplenishmentAPIClient {
    private $logger;
    private $retryAttempts;

    public function __construct($baseUrl, $timeout = 30, $retryAttempts = 3) {
        parent::__construct($baseUrl, $timeout);
        $this->logger = new ReplenishmentLogger();
        $this->retryAttempts = $retryAttempts;
    }

    protected function makeRequest($method, $endpoint, $data = null) {
        $attempts = 0;

        while ($attempts < $this->retryAttempts) {
            try {
                $this->logger->info("API Request", [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempts + 1
                ]);

                $result = parent::makeRequest($method, $endpoint, $data);

                $this->logger->info("API Success", [
                    'method' => $method,
                    'endpoint' => $endpoint
                ]);

                return $result;

            } catch (Exception $e) {
                $attempts++;

                $this->logger->error("API Error", [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ]);

                if ($attempts >= $this->retryAttempts) {
                    throw $e;
                }

                // Экспоненциальная задержка
                sleep(pow(2, $attempts - 1));
            }
        }
    }
}
?>
```

## 📱 Мобильная адаптация

### 11. Мобильный виджет для быстрого доступа

```php
<?php
// mobile_widget.php
function renderMobileInventoryWidget() {
    try {
        $client = new ReplenishmentAPIClient('http://your-server:8000');
        $recommendations = $client->getRecommendations(3, 'CRITICAL');
        $alerts = $client->getAlerts(3);
        ?>

        <div class="mobile-inventory-widget">
            <div class="widget-header">
                <span class="icon">📦</span>
                <span class="title">Склад</span>
                <span class="badge"><?= $recommendations['total_count'] ?></span>
            </div>

            <div class="widget-content" id="inventoryContent" style="display: none;">
                <?php if (!empty($recommendations['recommendations'])): ?>
                    <div class="critical-items">
                        <h6>🚨 Критические остатки:</h6>
                        <?php foreach ($recommendations['recommendations'] as $rec): ?>
                            <div class="item">
                                <span class="sku"><?= htmlspecialchars($rec['sku']) ?></span>
                                <span class="stock"><?= $rec['current_stock'] ?> шт</span>
                                <span class="order">→ <?= $rec['recommended_order_quantity'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <button onclick="runMobileAnalysis()" class="btn-mobile">Анализ</button>
                    <a href="dashboard.php" class="btn-mobile">Подробнее</a>
                </div>
            </div>
        </div>

        <style>
        .mobile-inventory-widget {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            margin: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .widget-header {
            display: flex;
            align-items: center;
            padding: 15px;
            cursor: pointer;
        }

        .widget-header .icon {
            font-size: 24px;
            margin-right: 10px;
        }

        .widget-header .title {
            flex: 1;
            font-weight: bold;
        }

        .widget-header .badge {
            background: rgba(255,255,255,0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .widget-content {
            background: rgba(255,255,255,0.1);
            padding: 15px;
        }

        .critical-items h6 {
            margin: 0 0 10px 0;
            font-size: 14px;
        }

        .item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            font-size: 12px;
        }

        .item:last-child {
            border-bottom: none;
        }

        .sku {
            font-weight: bold;
            flex: 1;
        }

        .stock {
            color: #ffeb3b;
            margin: 0 10px;
        }

        .order {
            color: #4caf50;
            font-weight: bold;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-mobile {
            flex: 1;
            padding: 8px;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.1);
            color: white;
            border-radius: 6px;
            text-decoration: none;
            text-align: center;
            font-size: 12px;
            cursor: pointer;
        }

        .btn-mobile:hover {
            background: rgba(255,255,255,0.2);
        }

        @media (max-width: 768px) {
            .mobile-inventory-widget {
                margin: 5px;
            }
        }
        </style>

        <script>
        document.querySelector('.widget-header').addEventListener('click', function() {
            const content = document.getElementById('inventoryContent');
            content.style.display = content.style.display === 'none' ? 'block' : 'none';
        });

        function runMobileAnalysis() {
            if (confirm('Запустить анализ склада?')) {
                fetch('ajax_run_analysis.php', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ Анализ запущен!');
                        } else {
                            alert('❌ Ошибка: ' + data.error);
                        }
                    })
                    .catch(error => alert('❌ Ошибка сети'));
            }
        }
        </script>

        <?php
    } catch (Exception $e) {
        echo '<div class="mobile-error">❌ Ошибка загрузки данных склада</div>';
    }
}
?>
```

## 🔐 Безопасность и авторизация

### 12. Middleware для проверки доступа

```php
<?php
class InventoryAccessMiddleware {
    private $allowedRoles = ['admin', 'warehouse_manager', 'inventory_manager'];

    public function checkAccess($userRole) {
        if (!in_array($userRole, $this->allowedRoles)) {
            http_response_code(403);
            die(json_encode(['error' => 'Доступ запрещен']));
        }
        return true;
    }

    public function requireAuth() {
        session_start();

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
            http_response_code(401);
            die(json_encode(['error' => 'Требуется авторизация']));
        }

        return $this->checkAccess($_SESSION['user_role']);
    }
}

// Использование в начале защищенных страниц:
// $middleware = new InventoryAccessMiddleware();
// $middleware->requireAuth();
?>
```

## 📊 Интеграция с популярными CMS

### 13. WordPress плагин (основа)

```php
<?php
/*
Plugin Name: Inventory Replenishment
Description: Интеграция системы пополнения склада с WordPress
Version: 1.0
*/

class InventoryReplenishmentPlugin {
    private $apiClient;

    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_inventory_analysis', [$this, 'ajax_run_analysis']);
        add_shortcode('inventory_widget', [$this, 'render_widget_shortcode']);
    }

    public function init() {
        $apiUrl = get_option('inventory_api_url', 'http://localhost:8000');
        $this->apiClient = new ReplenishmentAPIClient($apiUrl);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('inventory-js', plugin_dir_url(__FILE__) . 'inventory.js', ['jquery']);
        wp_localize_script('inventory-js', 'inventory_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('inventory_nonce')
        ]);
    }

    public function ajax_run_analysis() {
        check_ajax_referer('inventory_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        try {
            $result = $this->apiClient->runAnalysis();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function render_widget_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 5,
            'priority' => 'CRITICAL'
        ], $atts);

        try {
            $recommendations = $this->apiClient->getRecommendations($atts['limit'], $atts['priority']);

            ob_start();
            ?>
            <div class="inventory-widget-wp">
                <h4>📦 Критические остатки</h4>
                <?php if (!empty($recommendations['recommendations'])): ?>
                    <ul>
                        <?php foreach ($recommendations['recommendations'] as $rec): ?>
                            <li>
                                <strong><?= esc_html($rec['sku']) ?></strong> -
                                остаток: <?= $rec['current_stock'] ?> шт,
                                заказать: <?= $rec['recommended_order_quantity'] ?> шт
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Нет критических остатков</p>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();

        } catch (Exception $e) {
            return '<div class="error">Ошибка загрузки данных: ' . esc_html($e->getMessage()) . '</div>';
        }
    }
}

new InventoryReplenishmentPlugin();
?>
```

## 🚀 Развертывание и запуск

### 14. Пошаговая инструкция

1. **Скачайте файлы:**

```bash
# Создайте директорию для интеграции
mkdir inventory-integration
cd inventory-integration

# Скачайте основные файлы
wget https://raw.githubusercontent.com/your-repo/ReplenishmentAPIClient.php
```

2. **Настройте конфигурацию:**

```php
// config.php
<?php
define('REPLENISHMENT_API_URL', 'http://your-server:8000');
define('API_TIMEOUT', 30);
define('LOG_FILE', 'inventory.log');

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
?>
```

3. **Добавьте в crontab:**

```bash
# Синхронизация каждый час
0 * * * * /usr/bin/php /path/to/your/site/cron_sync.php

# Проверка критических остатков каждые 30 минут
*/30 * * * * /usr/bin/php /path/to/your/site/check_critical.php
```

4. **Интегрируйте в админ-панель:**

```php
// В вашей админ-панели
require_once 'ReplenishmentAPIClient.php';
require_once 'widget_inventory_status.php';

// В нужном месте вызовите:
renderInventoryWidget();
```

## 📞 Поддержка и устранение неполадок

### Частые проблемы:

1. **Ошибка подключения к API**

   - Проверьте URL API сервера
   - Убедитесь что сервер запущен
   - Проверьте файрвол

2. **Таймаут запросов**

   - Увеличьте timeout в конфигурации
   - Оптимизируйте запросы (используйте limit)

3. **Ошибки авторизации**
   - Проверьте права пользователя
   - Убедитесь что сессия активна

### Логирование:

```php
// Включите подробное логирование
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'inventory_errors.log');
```

---

💡 **Совет**: Начните с простой интеграции (базовый клиент + дашборд), затем постепенно добавляйте дополнительные функции.

🔗 **Полезные ссылки**:

- [Документация API](API_EXAMPLES.md)
- [Основная документация](README.md)
- [Примеры развертывания](deploy.sh)
