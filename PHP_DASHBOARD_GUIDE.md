# Руководство по созданию дашбордов остатков товаров
**Для PHP разработчиков**

---

## 🎯 Обзор

Данное руководство содержит готовые компоненты для создания дашбордов управления остатками товаров с маркетплейсов Ozon и Wildberries.

---

## 📊 Основные виджеты дашборда

### 1. Сводная статистика
```php
<?php
class DashboardWidgets {
    private $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Получить общую статистику остатков
     */
    public function getOverallStats() {
        $sql = "
            SELECT 
                COUNT(DISTINCT i.product_id) as total_products,
                SUM(i.quantity_present) as total_stock,
                SUM(i.quantity_reserved) as total_reserved,
                COUNT(CASE WHEN i.quantity_present <= 10 THEN 1 END) as low_stock_count,
                MAX(i.updated_at) as last_update
            FROM inventory i
            WHERE i.quantity_present > 0
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Статистика по маркетплейсам
     */
    public function getMarketplaceStats() {
        $sql = "
            SELECT 
                i.source,
                COUNT(DISTINCT i.product_id) as products_count,
                SUM(i.quantity_present) as total_stock,
                SUM(i.quantity_reserved) as total_reserved,
                AVG(i.quantity_present) as avg_stock_per_product
            FROM inventory i
            WHERE i.quantity_present > 0
            GROUP BY i.source
            ORDER BY total_stock DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Топ товаров по остаткам
     */
    public function getTopStockProducts($limit = 10) {
        $sql = "
            SELECT 
                p.name,
                p.sku_ozon,
                p.barcode,
                SUM(i.quantity_present) as total_stock,
                GROUP_CONCAT(CONCAT(i.source, ':', i.quantity_present) SEPARATOR ', ') as stock_details
            FROM inventory i
            JOIN dim_products p ON i.product_id = p.id
            WHERE i.quantity_present > 0
            GROUP BY i.product_id, p.name, p.sku_ozon, p.barcode
            ORDER BY total_stock DESC
            LIMIT ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Критические остатки (менее 5 единиц)
     */
    public function getCriticalStock() {
        $sql = "
            SELECT 
                p.name,
                p.sku_ozon,
                p.barcode,
                i.source,
                i.warehouse_name,
                i.quantity_present,
                i.updated_at
            FROM inventory i
            JOIN dim_products p ON i.product_id = p.id
            WHERE i.quantity_present > 0 AND i.quantity_present <= 5
            ORDER BY i.quantity_present ASC, i.updated_at DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
```

### 2. HTML компоненты дашборда
```php
<?php
function renderOverallStatsWidget($stats) {
    $last_update = date('d.m.Y H:i', strtotime($stats['last_update']));
    
    echo "
    <div class='stats-grid'>
        <div class='stat-card'>
            <h3>Всего товаров</h3>
            <div class='stat-number'>{$stats['total_products']}</div>
        </div>
        <div class='stat-card'>
            <h3>Общий остаток</h3>
            <div class='stat-number'>{$stats['total_stock']}</div>
        </div>
        <div class='stat-card'>
            <h3>Зарезервировано</h3>
            <div class='stat-number'>{$stats['total_reserved']}</div>
        </div>
        <div class='stat-card alert'>
            <h3>Критические остатки</h3>
            <div class='stat-number'>{$stats['low_stock_count']}</div>
        </div>
    </div>
    <div class='last-update'>Обновлено: {$last_update}</div>
    ";
}

function renderMarketplaceChart($marketplace_stats) {
    echo "<div class='marketplace-chart'>";
    echo "<h3>Остатки по маркетплейсам</h3>";
    
    foreach ($marketplace_stats as $mp) {
        $percentage = ($mp['total_stock'] / array_sum(array_column($marketplace_stats, 'total_stock'))) * 100;
        
        echo "
        <div class='marketplace-row'>
            <div class='mp-name'>{$mp['source']}</div>
            <div class='mp-bar'>
                <div class='mp-fill' style='width: {$percentage}%'></div>
            </div>
            <div class='mp-stats'>
                {$mp['products_count']} товаров | {$mp['total_stock']} шт.
            </div>
        </div>
        ";
    }
    echo "</div>";
}

function renderCriticalStockTable($critical_stock) {
    echo "
    <div class='critical-stock-widget'>
        <h3>⚠️ Критические остатки (≤5 шт.)</h3>
        <table class='critical-table'>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Артикул</th>
                    <th>Маркетплейс</th>
                    <th>Остаток</th>
                    <th>Обновлено</th>
                </tr>
            </thead>
            <tbody>
    ";
    
    foreach ($critical_stock as $item) {
        $updated = date('d.m H:i', strtotime($item['updated_at']));
        $alert_class = $item['quantity_present'] <= 2 ? 'critical' : 'warning';
        
        echo "
        <tr class='{$alert_class}'>
            <td>{$item['name']}</td>
            <td>{$item['sku_ozon']}</td>
            <td>{$item['source']}</td>
            <td>{$item['quantity_present']}</td>
            <td>{$updated}</td>
        </tr>
        ";
    }
    
    echo "
            </tbody>
        </table>
    </div>
    ";
}
?>
```

### 3. CSS стили для дашборда
```css
/* dashboard.css */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.stat-card {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-card.alert {
    border-left: 4px solid #ff6b6b;
}

.stat-number {
    font-size: 2em;
    font-weight: bold;
    color: #2c3e50;
    margin-top: 10px;
}

.marketplace-chart {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.marketplace-row {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.mp-name {
    width: 100px;
    font-weight: bold;
}

.mp-bar {
    flex: 1;
    height: 20px;
    background: #ecf0f1;
    border-radius: 10px;
    margin: 0 15px;
    overflow: hidden;
}

.mp-fill {
    height: 100%;
    background: linear-gradient(90deg, #3498db, #2980b9);
    transition: width 0.3s ease;
}

.critical-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
}

.critical-table th,
.critical-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.critical-table tr.critical {
    background-color: #ffebee;
}

.critical-table tr.warning {
    background-color: #fff3e0;
}

.last-update {
    text-align: right;
    color: #7f8c8d;
    font-size: 0.9em;
    margin-top: 10px;
}
```

### 4. Полный пример дашборда
```php
<?php
// dashboard.php
require_once 'config/database.php';
require_once 'classes/DashboardWidgets.php';

$dashboard = new DashboardWidgets($pdo);

// Получаем данные
$overall_stats = $dashboard->getOverallStats();
$marketplace_stats = $dashboard->getMarketplaceStats();
$top_products = $dashboard->getTopStockProducts(10);
$critical_stock = $dashboard->getCriticalStock();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд остатков товаров</title>
    <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
    <div class="dashboard-container">
        <header>
            <h1>📊 Дашборд управления остатками</h1>
        </header>
        
        <main>
            <!-- Общая статистика -->
            <section class="overview">
                <?php renderOverallStatsWidget($overall_stats); ?>
            </section>
            
            <!-- Статистика по маркетплейсам -->
            <section class="marketplace-section">
                <?php renderMarketplaceChart($marketplace_stats); ?>
            </section>
            
            <!-- Критические остатки -->
            <section class="alerts">
                <?php renderCriticalStockTable($critical_stock); ?>
            </section>
            
            <!-- Топ товары -->
            <section class="top-products">
                <h3>🏆 Топ товаров по остаткам</h3>
                <div class="products-grid">
                    <?php foreach ($top_products as $product): ?>
                    <div class="product-card">
                        <h4><?= htmlspecialchars($product['name']) ?></h4>
                        <p>Артикул: <?= $product['sku_ozon'] ?></p>
                        <p>Штрихкод: <?= $product['barcode'] ?></p>
                        <div class="stock-amount"><?= $product['total_stock'] ?> шт.</div>
                        <small><?= $product['stock_details'] ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
    
    <script>
        // Автообновление каждые 5 минут
        setInterval(() => {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
```

### 5. AJAX API для динамического обновления
```php
<?php
// api/dashboard-data.php
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../classes/DashboardWidgets.php';

$dashboard = new DashboardWidgets($pdo);
$action = $_GET['action'] ?? 'overview';

try {
    switch ($action) {
        case 'overview':
            $data = $dashboard->getOverallStats();
            break;
            
        case 'marketplace':
            $data = $dashboard->getMarketplaceStats();
            break;
            
        case 'critical':
            $data = $dashboard->getCriticalStock();
            break;
            
        case 'top-products':
            $limit = intval($_GET['limit'] ?? 10);
            $data = $dashboard->getTopStockProducts($limit);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
```

### 6. JavaScript для динамических обновлений
```javascript
// dashboard.js
class InventoryDashboard {
    constructor() {
        this.updateInterval = 60000; // 1 минута
        this.init();
    }
    
    init() {
        this.updateOverview();
        this.updateCriticalStock();
        
        // Автообновление
        setInterval(() => {
            this.updateOverview();
            this.updateCriticalStock();
        }, this.updateInterval);
    }
    
    async updateOverview() {
        try {
            const response = await fetch('/api/dashboard-data.php?action=overview');
            const result = await response.json();
            
            if (result.success) {
                this.renderOverviewStats(result.data);
            }
        } catch (error) {
            console.error('Ошибка обновления статистики:', error);
        }
    }
    
    async updateCriticalStock() {
        try {
            const response = await fetch('/api/dashboard-data.php?action=critical');
            const result = await response.json();
            
            if (result.success) {
                this.renderCriticalAlerts(result.data);
            }
        } catch (error) {
            console.error('Ошибка обновления критических остатков:', error);
        }
    }
    
    renderOverviewStats(stats) {
        document.querySelector('.total-products .stat-number').textContent = stats.total_products;
        document.querySelector('.total-stock .stat-number').textContent = stats.total_stock;
        document.querySelector('.total-reserved .stat-number').textContent = stats.total_reserved;
        document.querySelector('.low-stock-count .stat-number').textContent = stats.low_stock_count;
        
        // Обновляем время последнего обновления
        const lastUpdate = new Date(stats.last_update).toLocaleString('ru-RU');
        document.querySelector('.last-update').textContent = `Обновлено: ${lastUpdate}`;
    }
    
    renderCriticalAlerts(criticalItems) {
        const container = document.querySelector('.critical-stock-widget tbody');
        
        if (criticalItems.length === 0) {
            container.innerHTML = '<tr><td colspan="5">✅ Критических остатков нет</td></tr>';
            return;
        }
        
        container.innerHTML = criticalItems.map(item => {
            const alertClass = item.quantity_present <= 2 ? 'critical' : 'warning';
            const updated = new Date(item.updated_at).toLocaleString('ru-RU');
            
            return `
                <tr class="${alertClass}">
                    <td>${item.name}</td>
                    <td>${item.sku_ozon}</td>
                    <td>${item.source}</td>
                    <td>${item.quantity_present}</td>
                    <td>${updated}</td>
                </tr>
            `;
        }).join('');
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    new InventoryDashboard();
});
```

---

## 🚀 Быстрый старт

1. **Подключите базу данных** используя параметры из основного руководства
2. **Скопируйте класс `DashboardWidgets`** в ваш проект
3. **Добавьте CSS стили** для красивого отображения
4. **Создайте файл `dashboard.php`** с примером выше
5. **Настройте AJAX API** для динамических обновлений

**Результат:** Полнофункциональный дашборд с автообновлением данных об остатках товаров! 📊

---

*Руководство подготовлено: 21 сентября 2025 г.*
