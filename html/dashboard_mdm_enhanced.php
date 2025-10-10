<?php
/**
 * MDM-Enhanced Dashboard
 * 
 * Displays real product names with sync status indicators
 * and data quality metrics.
 * 
 * Requirements: 3.1, 3.2
 */

header('Content-Type: text/html; charset=utf-8');

// Load configuration
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get sync statistics
$syncStats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
        SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
        MAX(last_api_sync) as last_sync_time
    FROM product_cross_reference
")->fetch();

$syncPercentage = $syncStats['total'] > 0 
    ? round(($syncStats['synced'] / $syncStats['total']) * 100, 2) 
    : 0;

// Get products with quality indicators
$products = $pdo->query("
    SELECT 
        pcr.inventory_product_id as product_id,
        COALESCE(
            pcr.cached_name,
            dp.name,
            CONCAT('Товар ID ', pcr.inventory_product_id)
        ) as display_name,
        pcr.cached_brand as brand,
        pcr.sync_status,
        pcr.last_api_sync,
        CASE 
            WHEN pcr.cached_name IS NOT NULL 
                 AND pcr.cached_name NOT LIKE 'Товар%ID%' 
                 AND pcr.sync_status = 'synced' 
            THEN 'high'
            WHEN pcr.cached_name IS NOT NULL 
                 AND pcr.cached_name != '' 
            THEN 'medium'
            ELSE 'low'
        END as quality_level,
        SUM(i.quantity_present) as total_stock,
        SUM(i.quantity_reserved) as reserved_stock
    FROM product_cross_reference pcr
    LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
    LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
    GROUP BY pcr.inventory_product_id
    ORDER BY pcr.last_api_sync DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MDM Dashboard - Product Data Quality</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #718096;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card h3 {
            color: #718096;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #a0aec0;
            font-size: 14px;
        }
        
        .sync-progress {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .sync-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #38a169);
            transition: width 0.3s ease;
        }
        
        .products-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-header h2 {
            color: #2d3748;
        }
        
        .sync-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .sync-button:hover {
            transform: translateY(-2px);
        }
        
        .sync-button:active {
            transform: translateY(0);
        }
        
        .products-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .products-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .products-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .products-table tr:hover {
            background: #f7fafc;
        }
        
        .quality-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .quality-high {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .quality-medium {
            background: #feebc8;
            color: #7c2d12;
        }
        
        .quality-low {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .sync-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .sync-synced {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .sync-pending {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .sync-failed {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .product-name {
            font-weight: 500;
            color: #2d3748;
        }
        
        .product-id {
            color: #a0aec0;
            font-size: 12px;
        }
        
        .stock-info {
            display: flex;
            gap: 10px;
            font-size: 14px;
        }
        
        .stock-present {
            color: #38a169;
            font-weight: 600;
        }
        
        .stock-reserved {
            color: #ed8936;
        }
        
        .last-sync {
            color: #718096;
            font-size: 12px;
        }
        
        .action-button {
            background: #4299e1;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .action-button:hover {
            background: #3182ce;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #718096;
        }
        
        .loading.active {
            display: block;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 MDM Dashboard - Product Data Quality</h1>
            <p>Real-time product name synchronization and data quality monitoring</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Products</h3>
                <div class="stat-value"><?= number_format($syncStats['total']) ?></div>
                <div class="stat-label">In cross-reference table</div>
            </div>
            
            <div class="stat-card">
                <h3>Synced Products</h3>
                <div class="stat-value"><?= number_format($syncStats['synced']) ?></div>
                <div class="stat-label"><?= $syncPercentage ?>% of total</div>
                <div class="sync-progress">
                    <div class="sync-progress-bar" style="width: <?= $syncPercentage ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Pending Sync</h3>
                <div class="stat-value"><?= number_format($syncStats['pending']) ?></div>
                <div class="stat-label">Awaiting synchronization</div>
            </div>
            
            <div class="stat-card">
                <h3>Failed Sync</h3>
                <div class="stat-value"><?= number_format($syncStats['failed']) ?></div>
                <div class="stat-label">Requires attention</div>
            </div>
        </div>
        
        <div class="products-section">
            <div class="section-header">
                <h2>Products with Quality Indicators</h2>
                <button class="sync-button" onclick="triggerManualSync()">
                    🔄 Manual Sync
                </button>
            </div>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Synchronizing products...</p>
            </div>
            
            <table class="products-table" id="productsTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Data Quality</th>
                        <th>Sync Status</th>
                        <th>Stock</th>
                        <th>Last Sync</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                    <tr data-product-id="<?= htmlspecialchars($product['product_id']) ?>">
                        <td>
                            <div class="product-name"><?= htmlspecialchars($product['display_name']) ?></div>
                            <div class="product-id">ID: <?= htmlspecialchars($product['product_id']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($product['brand'] ?? '-') ?></td>
                        <td>
                            <span class="quality-badge quality-<?= $product['quality_level'] ?>">
                                <?php
                                    $qualityLabels = [
                                        'high' => '✓ High Quality',
                                        'medium' => '⚠ Medium Quality',
                                        'low' => '✗ Low Quality'
                                    ];
                                    echo $qualityLabels[$product['quality_level']];
                                ?>
                            </span>
                        </td>
                        <td>
                            <span class="sync-badge sync-<?= $product['sync_status'] ?>">
                                <?= ucfirst($product['sync_status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="stock-info">
                                <span class="stock-present">
                                    <?= number_format($product['total_stock'] ?? 0) ?>
                                </span>
                                <?php if ($product['reserved_stock'] > 0): ?>
                                <span class="stock-reserved">
                                    (<?= number_format($product['reserved_stock']) ?> reserved)
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="last-sync">
                                <?= $product['last_api_sync'] ? date('Y-m-d H:i', strtotime($product['last_api_sync'])) : 'Never' ?>
                            </div>
                        </td>
                        <td>
                            <button class="action-button" onclick="syncProduct('<?= htmlspecialchars($product['product_id']) ?>')">
                                Sync Now
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Auto-refresh every 30 seconds
        let autoRefreshInterval = setInterval(() => {
            location.reload();
        }, 30000);
        
        // Manual sync trigger
        function triggerManualSync() {
            if (confirm('Start manual synchronization for all pending products?')) {
                document.getElementById('loading').classList.add('active');
                document.getElementById('productsTable').style.opacity = '0.5';
                
                fetch('../api/sync-trigger.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ action: 'sync_all' })
                })
                .then(response => response.json())
                .then(data => {
                    alert(`Sync completed!\nSuccess: ${data.success}\nFailed: ${data.failed}`);
                    location.reload();
                })
                .catch(error => {
                    alert('Sync failed: ' + error.message);
                    document.getElementById('loading').classList.remove('active');
                    document.getElementById('productsTable').style.opacity = '1';
                });
            }
        }
        
        // Sync individual product
        function syncProduct(productId) {
            const row = document.querySelector(`tr[data-product-id="${productId}"]`);
            row.style.opacity = '0.5';
            
            fetch('../api/sync-trigger.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ 
                    action: 'sync_product',
                    product_id: productId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Product synced successfully!');
                    location.reload();
                } else {
                    alert('Sync failed: ' + data.error);
                    row.style.opacity = '1';
                }
            })
            .catch(error => {
                alert('Sync failed: ' + error.message);
                row.style.opacity = '1';
            });
        }
        
        // Stop auto-refresh when user is interacting
        document.addEventListener('click', () => {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => {
                location.reload();
            }, 60000); // Increase to 60 seconds after interaction
        });
    </script>
</body>
</html>
