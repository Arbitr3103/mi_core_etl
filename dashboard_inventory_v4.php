<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ozon v4 API - Управление остатками</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #2d3748;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        
        .card h3 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .btn {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }
        
        .btn:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
        }
        
        .status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-warning {
            background: #feebc8;
            color: #744210;
        }
        
        .status-error {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .status-info {
            background: #bee3f8;
            color: #2a4365;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .log {
            background: #1a202c;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.9rem;
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #feb2b2;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4299e1, #3182ce);
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Ozon v4 API</h1>
            <p>Управление синхронизацией остатков товаров через новый v4 API</p>
        </div>
        
        <div id="alerts"></div>
        
        <div class="cards">
            <!-- Управление синхронизацией -->
            <div class="card">
                <h3>🔄 Синхронизация</h3>
                <p>Запуск синхронизации остатков через v4 API</p>
                <div style="margin-top: 20px;">
                    <button id="syncBtn" class="btn" onclick="runSync()">
                        Запустить синхронизацию
                    </button>
                    <button id="testBtn" class="btn btn-warning" onclick="testAPI()">
                        Тест API
                    </button>
                </div>
                <div id="syncStatus" style="margin-top: 15px;"></div>
                <div id="syncProgress" style="display: none;">
                    <div class="progress-bar">
                        <div id="progressFill" class="progress-fill" style="width: 0%"></div>
                    </div>
                    <p id="progressText">Инициализация...</p>
                </div>
            </div>
            
            <!-- Статус системы -->
            <div class="card">
                <h3>📊 Статус системы</h3>
                <div id="systemStatus">
                    <p>Загрузка статуса...</p>
                </div>
                <button class="btn btn-success" onclick="loadStatus()" style="margin-top: 15px;">
                    Обновить статус
                </button>
            </div>
            
            <!-- Статистика -->
            <div class="card">
                <h3>📈 Статистика остатков</h3>
                <div id="statsContainer">
                    <p>Загрузка статистики...</p>
                </div>
                <button class="btn btn-success" onclick="loadStats()" style="margin-top: 15px;">
                    Обновить статистику
                </button>
            </div>
        </div>
        
        <!-- Лог операций -->
        <div class="card">
            <h3>📝 Лог операций</h3>
            <div id="operationLog" class="log">
                Лог операций будет отображаться здесь...
            </div>
            <button class="btn" onclick="clearLog()" style="margin-top: 10px;">
                Очистить лог
            </button>
        </div>
    </div>

    <script>
        let logContainer = document.getElementById('operationLog');
        
        function log(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `[${timestamp}] ${message}\n`;
            logContainer.textContent += logEntry;
            logContainer.scrollTop = logContainer.scrollHeight;
        }
        
        function clearLog() {
            logContainer.textContent = 'Лог очищен...\n';
        }
        
        function showAlert(message, type = 'success') {
            const alertsContainer = document.getElementById('alerts');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alertsContainer.appendChild(alert);
            
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }
        
        function setButtonLoading(buttonId, loading) {
            const btn = document.getElementById(buttonId);
            if (loading) {
                btn.disabled = true;
                btn.innerHTML = '<span class="loading"></span>Выполняется...';
            } else {
                btn.disabled = false;
                btn.innerHTML = btn.id === 'syncBtn' ? 'Запустить синхронизацию' : 'Тест API';
            }
        }
        
        function showProgress(show, text = '') {
            const progressDiv = document.getElementById('syncProgress');
            const progressText = document.getElementById('progressText');
            
            if (show) {
                progressDiv.style.display = 'block';
                progressText.textContent = text;
            } else {
                progressDiv.style.display = 'none';
            }
        }
        
        function updateProgress(percent, text) {
            document.getElementById('progressFill').style.width = percent + '%';
            document.getElementById('progressText').textContent = text;
        }
        
        async function testAPI() {
            log('🧪 Запуск теста v4 API...');
            setButtonLoading('testBtn', true);
            
            try {
                const response = await fetch('/api/inventory-v4.php?action=test');
                const result = await response.json();
                
                if (result.success && result.data.api_working) {
                    log(`✅ API тест успешен: получено ${result.data.items_received} товаров`);
                    showAlert('v4 API работает корректно!', 'success');
                    
                    document.getElementById('syncStatus').innerHTML = `
                        <span class="status status-success">API доступен</span>
                        <p style="margin-top: 10px; font-size: 0.9rem;">
                            Получено товаров: ${result.data.items_received}<br>
                            Есть еще данные: ${result.data.has_next ? 'Да' : 'Нет'}<br>
                            Пагинация: ${result.data.cursor_present ? 'Работает' : 'Не работает'}
                        </p>
                    `;
                } else {
                    log(`❌ API тест неудачен: ${result.data?.error || result.error}`);
                    showAlert('v4 API недоступен', 'error');
                    
                    document.getElementById('syncStatus').innerHTML = `
                        <span class="status status-error">API недоступен</span>
                    `;
                }
            } catch (error) {
                log(`❌ Ошибка теста API: ${error.message}`);
                showAlert('Ошибка при тестировании API', 'error');
            } finally {
                setButtonLoading('testBtn', false);
            }
        }
        
        async function runSync() {
            log('🚀 Запуск синхронизации v4 API...');
            setButtonLoading('syncBtn', true);
            showProgress(true, 'Инициализация синхронизации...');
            
            try {
                updateProgress(10, 'Подключение к API...');
                
                const response = await fetch('/api/inventory-v4.php?action=sync');
                const result = await response.json();
                
                updateProgress(50, 'Обработка данных...');
                
                if (result.success) {
                    const data = result.data;
                    updateProgress(100, 'Синхронизация завершена!');
                    
                    log(`✅ Синхронизация завершена успешно:`);
                    log(`   Статус: ${data.status}`);
                    log(`   Обработано: ${data.records_processed} записей`);
                    log(`   Вставлено: ${data.records_inserted} записей`);
                    log(`   Ошибок: ${data.records_failed}`);
                    log(`   Время выполнения: ${data.duration_seconds} сек`);
                    log(`   API запросов: ${data.api_requests_count}`);
                    
                    showAlert(`Синхронизация завершена! Обработано ${data.records_processed} записей`, 'success');
                    
                    document.getElementById('syncStatus').innerHTML = `
                        <span class="status status-success">Завершено</span>
                        <div class="stats-grid" style="margin-top: 15px;">
                            <div class="stat-item">
                                <div class="stat-value">${data.records_processed}</div>
                                <div class="stat-label">Обработано</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${data.records_inserted}</div>
                                <div class="stat-label">Вставлено</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${data.duration_seconds}с</div>
                                <div class="stat-label">Время</div>
                            </div>
                        </div>
                    `;
                    
                    // Обновляем статистику
                    setTimeout(loadStats, 1000);
                    
                } else {
                    updateProgress(0, 'Ошибка синхронизации');
                    log(`❌ Ошибка синхронизации: ${result.error}`);
                    showAlert('Ошибка при синхронизации', 'error');
                    
                    document.getElementById('syncStatus').innerHTML = `
                        <span class="status status-error">Ошибка</span>
                        <p style="margin-top: 10px; font-size: 0.9rem; color: #e53e3e;">
                            ${result.error}
                        </p>
                    `;
                }
            } catch (error) {
                updateProgress(0, 'Критическая ошибка');
                log(`❌ Критическая ошибка: ${error.message}`);
                showAlert('Критическая ошибка при синхронизации', 'error');
            } finally {
                setButtonLoading('syncBtn', false);
                setTimeout(() => showProgress(false), 2000);
            }
        }
        
        async function loadStatus() {
            try {
                const response = await fetch('/api/inventory-v4.php?action=status');
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    const statusContainer = document.getElementById('systemStatus');
                    
                    if (data.last_sync) {
                        const lastSync = data.last_sync;
                        const syncDate = new Date(lastSync.created_at).toLocaleString();
                        
                        statusContainer.innerHTML = `
                            <div style="margin-bottom: 15px;">
                                <span class="status status-${lastSync.status === 'success' ? 'success' : 'warning'}">
                                    ${lastSync.status}
                                </span>
                            </div>
                            <p><strong>Последняя синхронизация:</strong> ${syncDate}</p>
                            <p><strong>Обработано записей:</strong> ${lastSync.records_processed}</p>
                            <p><strong>Вставлено записей:</strong> ${lastSync.records_inserted}</p>
                            <p><strong>Время выполнения:</strong> ${lastSync.duration_seconds} сек</p>
                        `;
                    } else {
                        statusContainer.innerHTML = `
                            <span class="status status-info">Не запускалась</span>
                            <p style="margin-top: 10px;">v4 API синхронизация еще не запускалась</p>
                        `;
                    }
                }
            } catch (error) {
                log(`❌ Ошибка загрузки статуса: ${error.message}`);
            }
        }
        
        async function loadStats() {
            try {
                const response = await fetch('/api/inventory-v4.php?action=stats');
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    const statsContainer = document.getElementById('statsContainer');
                    
                    const overview = data.overview;
                    const stockTypes = data.stock_types;
                    
                    let stockTypesHtml = '';
                    if (stockTypes && stockTypes.length > 0) {
                        stockTypesHtml = stockTypes.map(type => `
                            <div class="stat-item">
                                <div class="stat-value">${type.total_stock || 0}</div>
                                <div class="stat-label">${type.stock_type}</div>
                            </div>
                        `).join('');
                    }
                    
                    statsContainer.innerHTML = `
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_products || 0}</div>
                                <div class="stat-label">Всего товаров</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.products_in_stock || 0}</div>
                                <div class="stat-label">В наличии</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_stock || 0}</div>
                                <div class="stat-label">Общий остаток</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_reserved || 0}</div>
                                <div class="stat-label">Зарезервировано</div>
                            </div>
                        </div>
                        ${stockTypesHtml ? `
                            <h4 style="margin: 20px 0 10px 0;">По типам складов:</h4>
                            <div class="stats-grid">${stockTypesHtml}</div>
                        ` : ''}
                        <p style="margin-top: 15px; font-size: 0.9rem; color: #718096;">
                            Последнее обновление: ${overview.last_update ? new Date(overview.last_update).toLocaleString() : 'Нет данных'}
                        </p>
                    `;
                }
            } catch (error) {
                log(`❌ Ошибка загрузки статистики: ${error.message}`);
            }
        }
        
        // Автозагрузка при открытии страницы
        document.addEventListener('DOMContentLoaded', function() {
            log('🎯 Дашборд v4 API загружен');
            loadStatus();
            loadStats();
        });
        
        // Автообновление каждые 30 секунд
        setInterval(() => {
            loadStatus();
            loadStats();
        }, 30000);
    </script>
</body>
</html>