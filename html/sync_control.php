<?php
/**
 * Веб-интерфейс для управления синхронизацией
 */

// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $limit = (int)($_POST['limit'] ?? 20);
    
    if ($action === 'start_sync') {
        // Запуск синхронизации через exec
        $command = "cd /var/www/mi_core_api && php sync-real-product-names-v2.php --limit=$limit 2>&1";
        $output = [];
        $return_code = 0;
        
        exec($command, $output, $return_code);
        
        echo json_encode([
            'status' => $return_code === 0 ? 'success' : 'error',
            'output' => implode("\n", $output),
            'return_code' => $return_code
        ]);
        exit;
    }
    
    if ($action === 'get_status') {
        // Получение текущего статуса
        require_once '../config.php';
        
        try {
            $pdo = getDatabaseConnection();
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) as synced,
                    COUNT(CASE WHEN sync_status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) as failed
                FROM product_cross_reference
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔄 Управление Синхронизацией - MDM Система</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 2.5em;
        }
        
        .control-panel {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .control-panel h3 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #495057;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-right: 15px;
            margin-bottom: 15px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .status-panel {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .status-item {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .status-item .value {
            font-size: 2em;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .status-item .label {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .output-panel {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .output-content {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        
        .progress-bar {
            background: #e9ecef;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin: 15px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Управление Синхронизацией</h1>
            <p>Запуск и мониторинг синхронизации товарных данных</p>
        </div>
        
        <div class="control-panel">
            <h3>⚙️ Параметры синхронизации</h3>
            
            <div class="form-group">
                <label for="syncLimit">Количество товаров для обработки:</label>
                <select id="syncLimit">
                    <option value="5">5 товаров (тест)</option>
                    <option value="20" selected>20 товаров (быстро)</option>
                    <option value="50">50 товаров (средне)</option>
                    <option value="100">100 товаров (долго)</option>
                    <option value="0">Все товары (очень долго)</option>
                </select>
            </div>
            
            <button id="startSyncBtn" class="btn btn-primary" onclick="startSync()">
                🚀 Запустить синхронизацию
            </button>
            
            <button id="refreshStatusBtn" class="btn btn-secondary" onclick="refreshStatus()">
                🔄 Обновить статус
            </button>
            
            <a href="marketing_dashboard.php" class="btn btn-success">
                📊 Вернуться к дашборду
            </a>
        </div>
        
        <div class="status-panel">
            <h3>📊 Текущий статус</h3>
            <div class="status-grid" id="statusGrid">
                <div class="status-item">
                    <div class="value" id="totalCount">-</div>
                    <div class="label">Всего товаров</div>
                </div>
                <div class="status-item">
                    <div class="value" id="syncedCount">-</div>
                    <div class="label">Синхронизировано</div>
                </div>
                <div class="status-item">
                    <div class="value" id="pendingCount">-</div>
                    <div class="label">Ожидает</div>
                </div>
                <div class="status-item">
                    <div class="value" id="failedCount">-</div>
                    <div class="label">Ошибки</div>
                </div>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill" style="width: 0%">0%</div>
            </div>
        </div>
        
        <div class="output-panel">
            <h3>📝 Лог выполнения</h3>
            <div id="alertContainer"></div>
            <div class="output-content" id="outputContent">
Ожидание команды...
            </div>
        </div>
    </div>
    
    <script>
        let isRunning = false;
        
        async function startSync() {
            if (isRunning) return;
            
            const limit = document.getElementById('syncLimit').value;
            const startBtn = document.getElementById('startSyncBtn');
            const outputContent = document.getElementById('outputContent');
            const alertContainer = document.getElementById('alertContainer');
            
            isRunning = true;
            startBtn.disabled = true;
            startBtn.innerHTML = '<div class="spinner"></div> Выполняется...';
            
            outputContent.textContent = 'Запуск синхронизации...\n';
            alertContainer.innerHTML = '<div class="alert alert-info">🔄 Синхронизация запущена...</div>';
            
            try {
                const formData = new FormData();
                formData.append('action', 'start_sync');
                formData.append('limit', limit);
                
                const response = await fetch('sync_control.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    outputContent.textContent = result.output;
                    alertContainer.innerHTML = '<div class="alert alert-success">✅ Синхронизация завершена успешно!</div>';
                    refreshStatus();
                } else {
                    outputContent.textContent = result.output || 'Ошибка выполнения';
                    alertContainer.innerHTML = '<div class="alert alert-error">❌ Ошибка синхронизации</div>';
                }
            } catch (error) {
                outputContent.textContent = 'Ошибка: ' + error.message;
                alertContainer.innerHTML = '<div class="alert alert-error">❌ Ошибка соединения</div>';
            }
            
            isRunning = false;
            startBtn.disabled = false;
            startBtn.innerHTML = '🚀 Запустить синхронизацию';
        }
        
        async function refreshStatus() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_status');
                
                const response = await fetch('sync_control.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    const data = result.data;
                    
                    document.getElementById('totalCount').textContent = data.total;
                    document.getElementById('syncedCount').textContent = data.synced;
                    document.getElementById('pendingCount').textContent = data.pending;
                    document.getElementById('failedCount').textContent = data.failed;
                    
                    const percentage = data.total > 0 ? (data.synced / data.total * 100).toFixed(1) : 0;
                    const progressFill = document.getElementById('progressFill');
                    progressFill.style.width = percentage + '%';
                    progressFill.textContent = percentage + '%';
                }
            } catch (error) {
                console.error('Ошибка получения статуса:', error);
            }
        }
        
        // Автообновление статуса каждые 10 секунд
        setInterval(refreshStatus, 10000);
        
        // Загрузка статуса при старте
        refreshStatus();
    </script>
</body>
</html>