<?php
/**
 * Sync Monitor Widget
 * 
 * Displays real-time synchronization status with alerts
 * for failed products and manual sync trigger.
 * 
 * Requirements: 3.3, 8.3
 */

// This widget can be included in any dashboard
// Usage: include 'widgets/sync_monitor_widget.php';

// Load configuration if not already loaded
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../../config.php';
}

// Get sync statistics
try {
    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    $syncStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
            SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
            MAX(last_api_sync) as last_sync_time,
            MIN(CASE WHEN sync_status = 'synced' THEN last_api_sync END) as oldest_sync_time
        FROM product_cross_reference
    ")->fetch();
    
    $syncPercentage = $syncStats['total'] > 0 
        ? round(($syncStats['synced'] / $syncStats['total']) * 100, 2) 
        : 0;
    
    // Get failed products
    $failedProducts = $pdo->query("
        SELECT 
            inventory_product_id,
            cached_name,
            last_api_sync
        FROM product_cross_reference
        WHERE sync_status = 'failed'
        ORDER BY last_api_sync DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $syncStats = [
        'total' => 0,
        'synced' => 0,
        'pending' => 0,
        'failed' => 0,
        'last_sync_time' => null,
        'oldest_sync_time' => null
    ];
    $syncPercentage = 0;
    $failedProducts = [];
}
?>

<style>
    .sync-monitor-widget {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }
    
    .widget-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .widget-title {
        font-size: 20px;
        font-weight: 600;
        color: #2d3748;
    }
    
    .sync-status-indicator {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .status-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    
    .status-dot.healthy {
        background: #48bb78;
    }
    
    .status-dot.warning {
        background: #ed8936;
    }
    
    .status-dot.critical {
        background: #f56565;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .sync-metrics {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .metric-item {
        text-align: center;
        padding: 15px;
        background: #f7fafc;
        border-radius: 8px;
    }
    
    .metric-value {
        font-size: 28px;
        font-weight: bold;
        color: #2d3748;
    }
    
    .metric-label {
        font-size: 12px;
        color: #718096;
        margin-top: 5px;
    }
    
    .sync-progress-container {
        margin-bottom: 20px;
    }
    
    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-size: 14px;
        color: #4a5568;
    }
    
    .progress-bar-container {
        width: 100%;
        height: 12px;
        background: #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
    }
    
    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #48bb78, #38a169);
        transition: width 0.5s ease;
        position: relative;
    }
    
    .progress-bar::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shimmer 2s infinite;
    }
    
    @keyframes shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
    
    .sync-info {
        display: flex;
        justify-content: space-between;
        padding: 15px;
        background: #edf2f7;
        border-radius: 8px;
        margin-bottom: 15px;
        font-size: 14px;
    }
    
    .sync-info-item {
        display: flex;
        flex-direction: column;
    }
    
    .sync-info-label {
        color: #718096;
        font-size: 12px;
        margin-bottom: 4px;
    }
    
    .sync-info-value {
        color: #2d3748;
        font-weight: 600;
    }
    
    .failed-products-alert {
        background: #fff5f5;
        border-left: 4px solid #f56565;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    
    .alert-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        color: #c53030;
        font-weight: 600;
    }
    
    .failed-products-list {
        max-height: 150px;
        overflow-y: auto;
        font-size: 13px;
    }
    
    .failed-product-item {
        padding: 8px;
        background: white;
        border-radius: 4px;
        margin-bottom: 5px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .widget-actions {
        display: flex;
        gap: 10px;
    }
    
    .widget-button {
        flex: 1;
        padding: 12px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .widget-button.primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .widget-button.secondary {
        background: #edf2f7;
        color: #4a5568;
    }
    
    .widget-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    .widget-button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
</style>

<div class="sync-monitor-widget">
    <div class="widget-header">
        <h3 class="widget-title">🔄 Sync Monitor</h3>
        <div class="sync-status-indicator">
            <?php
                $statusClass = 'healthy';
                $statusText = 'Healthy';
                
                if ($syncStats['failed'] > 10) {
                    $statusClass = 'critical';
                    $statusText = 'Critical';
                } elseif ($syncStats['failed'] > 0 || $syncPercentage < 80) {
                    $statusClass = 'warning';
                    $statusText = 'Warning';
                }
            ?>
            <div class="status-dot <?= $statusClass ?>"></div>
            <span><?= $statusText ?></span>
        </div>
    </div>
    
    <div class="sync-metrics">
        <div class="metric-item">
            <div class="metric-value"><?= number_format($syncStats['synced']) ?></div>
            <div class="metric-label">Synced</div>
        </div>
        <div class="metric-item">
            <div class="metric-value"><?= number_format($syncStats['pending']) ?></div>
            <div class="metric-label">Pending</div>
        </div>
        <div class="metric-item">
            <div class="metric-value"><?= number_format($syncStats['failed']) ?></div>
            <div class="metric-label">Failed</div>
        </div>
        <div class="metric-item">
            <div class="metric-value"><?= $syncPercentage ?>%</div>
            <div class="metric-label">Coverage</div>
        </div>
    </div>
    
    <div class="sync-progress-container">
        <div class="progress-label">
            <span>Synchronization Progress</span>
            <span><?= number_format($syncStats['synced']) ?> / <?= number_format($syncStats['total']) ?></span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar" style="width: <?= $syncPercentage ?>%"></div>
        </div>
    </div>
    
    <div class="sync-info">
        <div class="sync-info-item">
            <span class="sync-info-label">Last Sync</span>
            <span class="sync-info-value">
                <?= $syncStats['last_sync_time'] ? date('Y-m-d H:i:s', strtotime($syncStats['last_sync_time'])) : 'Never' ?>
            </span>
        </div>
        <div class="sync-info-item">
            <span class="sync-info-label">Oldest Sync</span>
            <span class="sync-info-value">
                <?= $syncStats['oldest_sync_time'] ? date('Y-m-d H:i:s', strtotime($syncStats['oldest_sync_time'])) : 'N/A' ?>
            </span>
        </div>
    </div>
    
    <?php if (!empty($failedProducts)): ?>
    <div class="failed-products-alert">
        <div class="alert-header">
            ⚠️ Failed Products (<?= count($failedProducts) ?>)
        </div>
        <div class="failed-products-list">
            <?php foreach ($failedProducts as $product): ?>
            <div class="failed-product-item">
                <span><?= htmlspecialchars($product['cached_name'] ?? 'Product ' . $product['inventory_product_id']) ?></span>
                <span style="color: #718096; font-size: 11px;">
                    ID: <?= htmlspecialchars($product['inventory_product_id']) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="widget-actions">
        <button class="widget-button primary" onclick="triggerManualSync()" id="syncButton">
            🔄 Manual Sync
        </button>
        <button class="widget-button secondary" onclick="retryFailedSync()" id="retryButton" 
                <?= empty($failedProducts) ? 'disabled' : '' ?>>
            🔁 Retry Failed
        </button>
    </div>
</div>

<script>
    function triggerManualSync() {
        const button = document.getElementById('syncButton');
        button.disabled = true;
        button.textContent = '⏳ Syncing...';
        
        fetch('../api/sync-trigger.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'sync_all' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Sync completed!\nSuccess: ${data.data.success}\nFailed: ${data.data.failed}`);
                location.reload();
            } else {
                alert('Sync failed: ' + data.error);
                button.disabled = false;
                button.textContent = '🔄 Manual Sync';
            }
        })
        .catch(error => {
            alert('Sync failed: ' + error.message);
            button.disabled = false;
            button.textContent = '🔄 Manual Sync';
        });
    }
    
    function retryFailedSync() {
        const button = document.getElementById('retryButton');
        button.disabled = true;
        button.textContent = '⏳ Retrying...';
        
        fetch('../api/sync-trigger.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'sync_failed' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Retry completed!\nReset: ${data.data.reset_count}\nSuccess: ${data.data.sync_results.success}`);
                location.reload();
            } else {
                alert('Retry failed: ' + data.error);
                button.disabled = false;
                button.textContent = '🔁 Retry Failed';
            }
        })
        .catch(error => {
            alert('Retry failed: ' + error.message);
            button.disabled = false;
            button.textContent = '🔁 Retry Failed';
        });
    }
</script>
