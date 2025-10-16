<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ozon v4 API - Управление остатками (Активные товары)</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
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
            transition: all 0.3s ease;
            position: relative;
        }
        
        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: #ffffff;
        }
        
        .warehouse-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .warehouse-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .warehouse-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }
        
        .warehouse-table tr:hover {
            background: #f7fafc;
        }
        
        .warehouse-table tr:last-child td {
            border-bottom: none;
        }
        
        .warehouse-type-main {
            background: linear-gradient(90deg, #4299e1, #3182ce);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .warehouse-type-analytics {
            background: linear-gradient(90deg, #48bb78, #38a169);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .stock-amount {
            font-weight: 600;
            color: #2d3748;
        }
        
        .stock-amount.high {
            color: #38a169;
        }
        
        .stock-amount.medium {
            color: #ed8936;
        }
        
        .stock-amount.low {
            color: #e53e3e;
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
            <h1>🚀 Ozon v4 API - Активные товары</h1>
            <p>Управление синхронизацией остатков активных товаров через новый v4 API</p>
        </div>
        
        <!-- Информационная панель об активных товарах -->
        <div class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px;">
            <h3 style="color: white; margin-bottom: 10px;">ℹ️ Фильтрация активных товаров</h3>
            <p style="margin-bottom: 10px;">Дашборд показывает данные только по <strong>активным товарам</strong> (товары с видимостью, наличием на складе и корректными ценами).</p>
            <div style="display: flex; gap: 20px; font-size: 0.9rem;">
                <div>📊 <strong>Активные товары:</strong> ~51 из 446 общих</div>
                <div>⚡ <strong>Улучшение производительности:</strong> 8.7x</div>
            </div>
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
            
            <!-- Маркетинговая аналитика -->
            <div class="card" style="grid-column: 1 / -1;">
                <h3>📊 Маркетинговая аналитика</h3>
                <div id="marketingContainer">
                    <p>Загрузка аналитики...</p>
                </div>
                <button class="btn btn-success" onclick="loadMarketingAnalytics()" style="margin-top: 15px;">
                    Обновить аналитику
                </button>
            </div>
            
            <!-- Детальный просмотр товаров -->
            <div class="card">
                <h3>🔍 Детальный просмотр</h3>
                <p>Остатки товаров по складам</p>
                <div style="margin-top: 15px;">
                    <input type="text" id="productSearch" placeholder="Поиск по SKU или Product ID..." 
                           style="width: 100%; padding: 8px; border: 1px solid #e2e8f0; border-radius: 4px; margin-bottom: 10px;">
                    <button class="btn btn-success" onclick="loadProductDetails()">
                        Показать товары
                    </button>
                </div>
            </div>
            
            <!-- Критические остатки -->
            <div class="card">
                <h3>⚠️ Критические остатки</h3>
                <p>Активные товары с остатками менее 5 штук</p>
                <div style="margin-top: 15px;">
                    <input type="number" id="criticalThreshold" value="5" min="1" max="50" 
                           style="width: 80px; padding: 8px; border: 1px solid #e2e8f0; border-radius: 4px; margin-right: 10px;">
                    <button class="btn btn-danger" onclick="loadCriticalStock()">
                        Показать критические
                    </button>
                </div>
                <div id="criticalStats" style="margin-top: 15px;"></div>
            </div>
        </div>
        
        <!-- Детальные таблицы -->
        <div class="card" id="productDetailsCard" style="display: none;">
            <h3>📦 Детальные остатки по товарам</h3>
            <div id="productDetailsContainer">
                <p>Выберите товары для просмотра...</p>
            </div>
        </div>
        
        <div class="card" id="criticalStockCard" style="display: none;">
            <h3>🚨 Критические остатки</h3>
            <div id="criticalStockContainer">
                <p>Загрузка критических остатков...</p>
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
                const response = await fetch('/api/inventory-v4.php?action=stats&active_only=1');
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    const statsContainer = document.getElementById('statsContainer');
                    
                    const overview = data.overview;
                    const stockTypes = data.stock_types;
                    
                    let stockTypesHtml = '';
                    if (stockTypes && stockTypes.length > 0) {
                        // Функция для определения класса остатков
                        const getStockClass = (stock) => {
                            if (stock > 100) return 'high';
                            if (stock > 10) return 'medium';
                            return 'low';
                        };
                        
                        // Разделяем на основные и аналитические склады
                        const mainWarehouses = stockTypes.filter(type => type.source === 'Ozon');
                        const analyticsWarehouses = stockTypes.filter(type => type.source === 'Ozon_Analytics');
                        
                        // Объединяем все склады для таблицы
                        const allWarehouses = [...mainWarehouses, ...analyticsWarehouses];
                        
                        stockTypesHtml = `
                            <h4 style="margin: 20px 0 10px 0; color: #2d3748;">📦 Склады и остатки</h4>
                            <table class="warehouse-table">
                                <thead>
                                    <tr>
                                        <th>Склад</th>
                                        <th>Тип</th>
                                        <th>Остатки</th>
                                        <th>Товаров</th>
                                        <th>Источник</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${allWarehouses.map(type => {
                                        const warehouseName = type.stock_type.replace(' (analytics)', '').replace(' (FBO)', '');
                                        const stockAmount = parseInt(type.total_stock) || 0;
                                        const stockClass = getStockClass(stockAmount);
                                        const isMain = type.source === 'Ozon';
                                        
                                        return `
                                            <tr>
                                                <td style="font-weight: 500;">${warehouseName}</td>
                                                <td>
                                                    <span class="${isMain ? 'warehouse-type-main' : 'warehouse-type-analytics'}">
                                                        ${isMain ? 'Основной' : 'Аналитика'}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="stock-amount ${stockClass}">
                                                        ${stockAmount.toLocaleString()}
                                                    </span>
                                                </td>
                                                <td>${type.count}</td>
                                                <td style="font-size: 0.8rem; color: #718096;">
                                                    ${type.source.replace('Ozon_', '')}
                                                </td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        `;
                    }
                    
                    statsContainer.innerHTML = `
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_products || 0}</div>
                                <div class="stat-label">Всего записей</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.products_in_stock || 0}</div>
                                <div class="stat-label">В наличии</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_warehouses || 0}</div>
                                <div class="stat-label">Складов</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.analytics_warehouses || 0}</div>
                                <div class="stat-label">Детализация</div>
                            </div>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_stock || 0}</div>
                                <div class="stat-label">Общий остаток</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${overview.total_reserved || 0}</div>
                                <div class="stat-label">Зарезервировано</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${data.api_version || 'v4'}</div>
                                <div class="stat-label">API версия</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">${Math.round(overview.avg_stock || 0)}</div>
                                <div class="stat-label">Средний остаток</div>
                            </div>
                        </div>
                        ${stockTypesHtml}
                        <p style="margin-top: 15px; font-size: 0.9rem; color: #718096;">
                            Последнее обновление: ${overview.last_update ? new Date(overview.last_update).toLocaleString() : 'Нет данных'}
                        </p>
                    `;
                }
            } catch (error) {
                log(`❌ Ошибка загрузки статистики: ${error.message}`);
            }
        }
        
        async function loadProductDetails() {
            const search = document.getElementById('productSearch').value;
            const card = document.getElementById('productDetailsCard');
            const container = document.getElementById('productDetailsContainer');
            
            try {
                log('🔍 Загрузка детальных данных по товарам...');
                
                const url = `/api/inventory-v4.php?action=products&limit=20&offset=0${search ? '&search=' + encodeURIComponent(search) : ''}`;
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success) {
                    const products = result.data.products;
                    
                    if (products.length === 0) {
                        container.innerHTML = '<p>Товары не найдены</p>';
                        card.style.display = 'block';
                        return;
                    }
                    
                    let html = `
                        <div style="margin-bottom: 15px;">
                            <strong>Найдено товаров: ${result.data.total_count}</strong>
                            ${search ? ` (поиск: "${search}")` : ''}
                        </div>
                    `;
                    
                    products.forEach(product => {
                        html += `
                            <div style="margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px;">
                                <h4 style="margin: 0 0 10px 0; color: #2d3748;">${product.sku}</h4>
                                <p style="margin: 0 0 10px 0; font-size: 0.9rem; color: #718096;">
                                    Product ID: ${product.product_id} | 
                                    Общий остаток: <strong>${product.total_stock}</strong> | 
                                    Зарезервировано: <strong>${product.total_reserved}</strong>
                                </p>
                                <table class="warehouse-table">
                                    <thead>
                                        <tr>
                                            <th>Склад</th>
                                            <th>Тип</th>
                                            <th>Остаток</th>
                                            <th>Резерв</th>
                                            <th>Источник</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${product.warehouses.map(wh => {
                                            const stockClass = wh.current_stock > 10 ? 'high' : wh.current_stock > 0 ? 'medium' : 'low';
                                            return `
                                                <tr>
                                                    <td>${wh.warehouse_name}</td>
                                                    <td>
                                                        <span class="${wh.source === 'Ozon' ? 'warehouse-type-main' : 'warehouse-type-analytics'}">
                                                            ${wh.source === 'Ozon' ? 'Основной' : 'Аналитика'}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-amount ${stockClass}">
                                                            ${wh.current_stock}
                                                        </span>
                                                    </td>
                                                    <td>${wh.reserved_stock}</td>
                                                    <td style="font-size: 0.8rem;">${wh.source.replace('Ozon_', '')}</td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                    card.style.display = 'block';
                    log(`✅ Загружено ${products.length} товаров`);
                    
                } else {
                    container.innerHTML = `<p style="color: #e53e3e;">Ошибка: ${result.error}</p>`;
                    card.style.display = 'block';
                }
                
            } catch (error) {
                log(`❌ Ошибка загрузки товаров: ${error.message}`);
                container.innerHTML = `<p style="color: #e53e3e;">Ошибка загрузки данных</p>`;
                card.style.display = 'block';
            }
        }
        
        async function loadCriticalStock() {
            const threshold = document.getElementById('criticalThreshold').value || 5;
            const card = document.getElementById('criticalStockCard');
            const container = document.getElementById('criticalStockContainer');
            const statsContainer = document.getElementById('criticalStats');
            
            try {
                log(`⚠️ Загрузка критических остатков с улучшенным определением складов (порог: ${threshold})...`);
                
                const response = await fetch(`/api/inventory-v4.php?action=critical&threshold=${threshold}&active_only=1&v=${Date.now()}`);
                const result = await response.json();
                
                if (result.success) {
                    const items = result.data.critical_items;
                    const stats = result.data.stats;
                    const warehouseAnalysis = result.data.warehouse_analysis || [];
                    
                    // Расширенная статистика в карточке
                    statsContainer.innerHTML = `
                        <div class="stats-grid" style="margin-top: 10px;">
                            <div class="stat-item" style="border-left: 4px solid #e53e3e;">
                                <div class="stat-value" style="color: #e53e3e;">${stats.total_critical_items}</div>
                                <div class="stat-label">Критических позиций</div>
                            </div>
                            <div class="stat-item" style="border-left: 4px solid #ed8936;">
                                <div class="stat-value" style="color: #ed8936;">${stats.unique_products}</div>
                                <div class="stat-label">Уникальных товаров</div>
                            </div>
                            <div class="stat-item" style="border-left: 4px solid #4299e1;">
                                <div class="stat-value" style="color: #4299e1;">${stats.affected_warehouses}</div>
                                <div class="stat-label">Затронутых складов</div>
                            </div>
                            <div class="stat-item" style="border-left: 4px solid #38a169;">
                                <div class="stat-value" style="color: #38a169;">${stats.critical_with_orders}</div>
                                <div class="stat-label">С активными заказами</div>
                            </div>
                        </div>
                    `;
                    
                    if (items.length === 0) {
                        container.innerHTML = `<p style="color: #38a169;">✅ Критических остатков не найдено!</p>`;
                        card.style.display = 'block';
                        return;
                    }
                    
                    let html = `
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <strong style="color: #e53e3e;">Найдено ${items.length} критических позиций</strong>
                                <span style="font-size: 0.9rem; color: #718096;">(остаток менее ${threshold} штук)</span>
                            </div>
                            
                            <!-- Анализ по складам -->
                            <h5 style="margin: 15px 0 10px 0; color: #2d3748;">📊 Анализ критических остатков по складам</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 20px;">
                                ${warehouseAnalysis.slice(0, 6).map(warehouse => {
                                    const sourceColor = warehouse.source === 'Ozon' ? '#4299e1' : 
                                                      warehouse.source === 'Ozon_Analytics' ? '#48bb78' : '#ed8936';
                                    return `
                                    <div style="background: white; border-radius: 8px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                        <div style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <span style="background: ${sourceColor}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; margin-right: 8px;">
                                                ${warehouse.source === 'Ozon' ? 'Основной API' : warehouse.source === 'Ozon_Analytics' ? 'Аналитика' : 'Другой'}
                                            </span>
                                            ${warehouse.stock_type ? `<span style="background: #e2e8f0; color: #2d3748; padding: 2px 6px; border-radius: 8px; font-size: 0.7rem;">${warehouse.stock_type}</span>` : ''}
                                        </div>
                                        <h6 style="margin: 0 0 8px 0; color: #2d3748; font-size: 0.9rem; font-weight: 600;">${warehouse.warehouse_display_name}</h6>
                                        <div style="margin-bottom: 5px;">
                                            <span style="font-weight: 600; color: #e53e3e;">${warehouse.critical_items_count}</span>
                                            <span style="font-size: 0.8rem; color: #718096;"> критических позиций</span>
                                        </div>
                                        <div style="margin-bottom: 5px;">
                                            <span style="font-weight: 600; color: #2d3748;">${warehouse.unique_products}</span>
                                            <span style="font-size: 0.8rem; color: #718096;"> уникальных товаров</span>
                                        </div>
                                        <div>
                                            <span style="font-weight: 600; color: #2d3748;">${warehouse.total_stock}</span>
                                            <span style="font-size: 0.8rem; color: #718096;"> общий остаток</span>
                                        </div>
                                    </div>
                                `}).join('')}
                            </div>
                        </div>
                        
                        <!-- Детальная таблица -->
                        <h5 style="margin: 15px 0 10px 0; color: #2d3748;">📋 Детальный список критических товаров</h5>
                        <table class="warehouse-table">
                            <thead>
                                <tr>
                                    <th>Товар</th>
                                    <th>Склад</th>
                                    <th>Остаток</th>
                                    <th>Резерв</th>
                                    <th>Приоритет</th>
                                    <th>Источник данных</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${items.map(item => {
                                    const urgencyColor = item.urgency_level.includes('Критично') ? '#e53e3e' : 
                                                       item.urgency_level.includes('пополнения') ? '#ed8936' : '#4299e1';
                                    const sourceColor = item.data_source === 'Ozon' ? '#4299e1' : '#48bb78';
                                    
                                    return `
                                    <tr>
                                        <td style="max-width: 250px;">
                                            <div style="margin-bottom: 5px;">
                                                <strong style="color: #2d3748; font-size: 0.9rem;">${item.display_name}</strong>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #718096;">
                                                SKU: ${item.sku}
                                            </div>
                                            ${item.brand && item.brand !== 'Без бренда' ? `<div style="font-size: 0.8rem; color: #718096;">Бренд: ${item.brand}</div>` : ''}
                                        </td>
                                        <td>
                                            <div style="margin-bottom: 5px;">
                                                <strong style="color: #2d3748; font-size: 0.9rem;">${item.warehouse_display_name}</strong>
                                            </div>
                                            ${item.stock_type ? `<div style="font-size: 0.8rem; color: #718096;">Тип: ${item.stock_type}</div>` : ''}
                                        </td>
                                        <td>
                                            <span class="stock-amount low" style="font-size: 1.1rem;">
                                                ${item.current_stock}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: ${item.reserved_stock > 0 ? '#ed8936' : '#718096'};">
                                                ${item.reserved_stock}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="background: ${urgencyColor}; color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 500;">
                                                ${item.urgency_level}
                                            </span>
                                        </td>
                                        <td>
                                            <div style="margin-bottom: 3px;">
                                                <span style="background: ${sourceColor}; color: white; padding: 2px 6px; border-radius: 8px; font-size: 0.75rem;">
                                                    ${item.data_source === 'Ozon' ? 'Основной' : 'Аналитика'}
                                                </span>
                                            </div>
                                            <div style="font-size: 0.7rem; color: #718096;">
                                                ${item.source_description}
                                            </div>
                                        </td>
                                    </tr>
                                `}).join('')}
                            </tbody>
                        </table>
                    `;
                    
                    container.innerHTML = html;
                    card.style.display = 'block';
                    log(`⚠️ Найдено ${items.length} критических позиций на ${stats.affected_warehouses} складах`);
                    
                } else {
                    container.innerHTML = `<p style="color: #e53e3e;">Ошибка: ${result.error}</p>`;
                    card.style.display = 'block';
                }
                
            } catch (error) {
                log(`❌ Ошибка загрузки критических остатков: ${error.message}`);
                container.innerHTML = `<p style="color: #e53e3e;">Ошибка загрузки данных</p>`;
                card.style.display = 'block';
            }
        }
        
        async function loadMarketingAnalytics() {
            try {
                log('📊 Загрузка компактной маркетинговой аналитики...');
                
                const response = await fetch(`/api/inventory-v4.php?action=marketing&active_only=1&v=${Date.now()}`);
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    const container = document.getElementById('marketingContainer');
                    
                    const topProducts = data.top_products || [];
                    const attentionProducts = data.attention_products || [];
                    const categoryAnalysis = data.category_analysis || [];
                    const brandAnalysis = data.brand_analysis || [];
                    const stats = data.overall_stats;
                    
                    container.innerHTML = `
                        <!-- Маркетинговые KPI -->
                        <div class="stats-grid" style="margin-bottom: 25px;">
                            <div class="stat-item" style="border-left: 4px solid #48bb78;">
                                <div class="stat-value" style="color: #48bb78;">${stats.total_unique_products || 0}</div>
                                <div class="stat-label">Товаров в ассортименте</div>
                            </div>
                            <div class="stat-item" style="border-left: 4px solid #4299e1;">
                                <div class="stat-value" style="color: #4299e1;">${stats.stock_efficiency || 0}%</div>
                                <div class="stat-label">Эффективность запасов</div>
                            </div>
                            <div class="stat-item" style="border-left: 4px solid #ed8936;">
                                <div class="stat-value" style="color: #ed8936;">${stats.critical_attention_needed || 0}</div>
                                <div class="stat-label">Требуют внимания</div>
                            </div>
                            <div class="stat-item" style="border-left: 4px solid #38a169;">
                                <div class="stat-value" style="color: #38a169;">${stats.category_diversity || 0}</div>
                                <div class="stat-label">Категорий товаров</div>
                            </div>
                        </div>
                        
                        <!-- Товары требующие внимания - компактная таблица -->
                        <h4 style="margin: 20px 0 10px 0; color: #e53e3e;">🎯 Товары требующие маркетингового внимания</h4>
                        <table class="warehouse-table" style="margin-bottom: 25px;">
                            <thead>
                                <tr>
                                    <th>Товар</th>
                                    <th>Бренд</th>
                                    <th>Категория</th>
                                    <th>Остаток</th>
                                    <th>Резерв</th>
                                    <th>Рекомендация</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${attentionProducts.slice(0, 10).map(product => {
                                    const actionColor = product.marketing_action.includes('Избыток') ? '#ed8936' : 
                                                      product.marketing_action.includes('спрос') ? '#e53e3e' : '#4299e1';
                                    return `
                                    <tr>
                                        <td style="max-width: 250px;">
                                            <strong style="color: #2d3748; font-size: 0.9rem;">${product.product_name}</strong>
                                        </td>
                                        <td style="font-weight: 500;">${product.brand}</td>
                                        <td style="font-size: 0.9rem;">${product.category}</td>
                                        <td>
                                            <span class="stock-amount ${product.total_stock > 50 ? 'high' : product.total_stock > 10 ? 'medium' : 'low'}">
                                                ${product.total_stock}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: ${product.total_reserved > 0 ? '#ed8936' : '#718096'};">
                                                ${product.total_reserved}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="background: ${actionColor}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 500;">
                                                ${product.marketing_action}
                                            </span>
                                        </td>
                                    </tr>
                                `}).join('')}
                            </tbody>
                        </table>
                        
                        <!-- Анализ по категориям - компактная таблица -->
                        <h4 style="margin: 20px 0 10px 0; color: #2d3748;">📊 Анализ по категориям товаров</h4>
                        <table class="warehouse-table" style="margin-bottom: 25px;">
                            <thead>
                                <tr>
                                    <th>Категория</th>
                                    <th>Товаров</th>
                                    <th>Общий остаток</th>
                                    <th>Средний остаток</th>
                                    <th>Низкие остатки</th>
                                    <th>Риск</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${categoryAnalysis.slice(0, 10).map(category => {
                                    const riskLevel = category.low_stock_percentage > 50 ? 'high' : 
                                                    category.low_stock_percentage > 25 ? 'medium' : 'low';
                                    const riskColor = riskLevel === 'high' ? '#e53e3e' : 
                                                    riskLevel === 'medium' ? '#ed8936' : '#48bb78';
                                    return `
                                    <tr>
                                        <td style="font-weight: 600; color: #2d3748;">${category.category}</td>
                                        <td>${category.products_count}</td>
                                        <td>
                                            <span class="stock-amount">
                                                ${parseInt(category.total_stock).toLocaleString()}
                                            </span>
                                        </td>
                                        <td>${Math.round(category.avg_stock)}</td>
                                        <td>${category.low_stock_count}</td>
                                        <td>
                                            <span style="background: ${riskColor}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem;">
                                                ${category.low_stock_percentage}%
                                            </span>
                                        </td>
                                    </tr>
                                `}).join('')}
                            </tbody>
                        </table>
                        
                        <!-- Топ бренды - компактная таблица -->
                        <h4 style="margin: 20px 0 10px 0; color: #2d3748;">🏆 Топ бренды по объему запасов</h4>
                        <table class="warehouse-table" style="margin-bottom: 25px;">
                            <thead>
                                <tr>
                                    <th>Место</th>
                                    <th>Бренд</th>
                                    <th>Товаров</th>
                                    <th>Общий остаток</th>
                                    <th>Средний остаток</th>
                                    <th>Критических</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${brandAnalysis.slice(0, 10).map((brand, index) => `
                                    <tr>
                                        <td>
                                            <span style="background: ${index < 3 ? '#48bb78' : '#4299e1'}; color: white; border-radius: 50%; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                ${index + 1}
                                            </span>
                                        </td>
                                        <td style="font-weight: 600; color: #2d3748;">${brand.brand}</td>
                                        <td>${brand.products_count}</td>
                                        <td>
                                            <span class="stock-amount">
                                                ${parseInt(brand.total_stock).toLocaleString()}
                                            </span>
                                        </td>
                                        <td>${Math.round(brand.avg_stock)}</td>
                                        <td>
                                            ${brand.critical_products > 0 ? 
                                                `<span style="color: #e53e3e; font-weight: 600;">⚠️ ${brand.critical_products}</span>` : 
                                                `<span style="color: #48bb78;">✅ 0</span>`
                                            }
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        
                        <!-- Топ товары - компактная таблица -->
                        <h4 style="margin: 20px 0 10px 0; color: #2d3748;">📦 Топ товары по остаткам</h4>
                        <table class="warehouse-table" style="margin-bottom: 25px;">
                            <thead>
                                <tr>
                                    <th>Место</th>
                                    <th>Товар</th>
                                    <th>Бренд</th>
                                    <th>Остаток</th>
                                    <th>Резерв</th>
                                    <th>Уровень запасов</th>
                                    <th>Спрос</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${topProducts.slice(0, 10).map((product, index) => `
                                    <tr>
                                        <td>
                                            <span style="background: ${index < 3 ? '#48bb78' : '#4299e1'}; color: white; border-radius: 50%; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                                ${index + 1}
                                            </span>
                                        </td>
                                        <td style="max-width: 200px;">
                                            <strong style="color: #2d3748; font-size: 0.9rem;">${product.product_name}</strong>
                                        </td>
                                        <td style="font-weight: 500;">${product.brand}</td>
                                        <td>
                                            <span class="stock-amount ${product.total_stock > 100 ? 'high' : product.total_stock > 20 ? 'medium' : 'low'}">
                                                ${product.total_stock}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: ${product.total_reserved > 0 ? '#ed8936' : '#718096'};">
                                                ${product.total_reserved}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="background: ${product.stock_level === 'Высокие остатки' ? '#48bb78' : product.stock_level === 'Средние остатки' ? '#ed8936' : '#e53e3e'}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem;">
                                                ${product.stock_level}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="color: ${product.demand_status === 'Есть заказы' ? '#48bb78' : '#718096'}; font-weight: 500;">
                                                ${product.demand_status === 'Есть заказы' ? '📈' : '📉'} ${product.demand_status}
                                            </span>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        
                        <p style="margin-top: 20px; font-size: 0.9rem; color: #718096; text-align: center;">
                            Последнее обновление: ${new Date(data.analysis_time).toLocaleString()}
                        </p>
                    `;
                    
                    log('✅ Компактная маркетинговая аналитика загружена');
                    
                } else {
                    container.innerHTML = `<p style="color: #e53e3e;">Ошибка: ${result.error}</p>`;
                }
                
            } catch (error) {
                log(`❌ Ошибка загрузки маркетинговой аналитики: ${error.message}`);
                document.getElementById('marketingContainer').innerHTML = `<p style="color: #e53e3e;">Ошибка загрузки данных</p>`;
            }
        }
        
        // Автозагрузка при открытии страницы
        document.addEventListener('DOMContentLoaded', function() {
            log('🎯 Дашборд v4 API загружен');
            loadStatus();
            loadMarketingAnalytics();
        });
        
        // Автообновление каждые 30 секунд
        setInterval(() => {
            loadStatus();
            loadMarketingAnalytics();
        }, 30000);
    </script>
    
    <!-- MDM System Dashboard Fixes -->
    <script src="/js/dashboard-fixes.js"></script>
</body>
</html>