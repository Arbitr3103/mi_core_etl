<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Маркетинговый Дашборд Склада - Принятие Решений</title>
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
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            text-align: center;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 2.2em;
        }
        
        .alerts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .alert-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-left: 5px solid;
        }
        
        .alert-card.critical { border-color: #e74c3c; }
        .alert-card.warning { border-color: #f39c12; }
        .alert-card.success { border-color: #27ae60; }
        
        .alert-card h3 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .products-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .products-card h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid;
        }
        
        .product-item.critical { border-color: #e74c3c; }
        .product-item.warning { border-color: #f39c12; }
        .product-item.success { border-color: #27ae60; }
        
        .product-info {
            flex: 1;
        }
        
        .product-name {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .product-details {
            font-size: 12px;
            color: #6c757d;
        }
        
        .product-stock {
            text-align: right;
        }
        
        .stock-value {
            font-size: 1.5em;
            font-weight: bold;
        }
        
        .stock-value.critical { color: #e74c3c; }
        .stock-value.warning { color: #f39c12; }
        .stock-value.success { color: #27ae60; }
        
        .stock-label {
            font-size: 12px;
            color: #6c757d;
        }
        
        .actions-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .action-card {
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid;
        }
        
        .action-card.urgent { 
            background: #fdf2f2; 
            border-color: #e74c3c; 
        }
        
        .action-card.important { 
            background: #fefbf3; 
            border-color: #f39c12; 
        }
        
        .action-card.normal { 
            background: #f0f9f4; 
            border-color: #27ae60; 
        }
        
        .action-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .action-description {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .kpi-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .kpi-value {
            font-size: 2em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .kpi-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .refresh-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .refresh-button:hover {
            transform: scale(1.1);
        }
        
        .loading {
            text-align: center;
            color: white;
            font-size: 18px;
            padding: 50px;
        }
        
        .no-data {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 20px;
        }
        
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .trend-up { color: #27ae60; }
        .trend-down { color: #e74c3c; }
        .trend-stable { color: #7f8c8d; }
        
        .warehouse-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .warehouse-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-left: 5px solid #3498db;
        }
        
        .warehouse-card.critical { border-color: #e74c3c; }
        .warehouse-card.attention { border-color: #f39c12; }
        .warehouse-card.good { border-color: #27ae60; }
        
        .warehouse-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .warehouse-name {
            font-size: 1.2em;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .warehouse-status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        
        .warehouse-status.critical { background: #e74c3c; }
        .warehouse-status.attention { background: #f39c12; }
        .warehouse-status.good { background: #27ae60; }
        
        .warehouse-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .warehouse-stat {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .warehouse-stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .warehouse-stat-label {
            font-size: 12px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Маркетинговый Дашборд Склада</h1>
            <p>Принятие решений на основе складских остатков и продаж</p>
        </div>
        
        <div id="dashboard-content" class="loading">
            ⏳ Загрузка данных о складских остатках...
        </div>
        
        <button class="refresh-button" onclick="loadDashboard()" title="Обновить данные">
            🔄
        </button>
    </div>
    
    <script>
        async function loadDashboard() {
            try {
                // Показываем индикатор загрузки
                showLoadingIndicator();
                
                // Загружаем данные о товарах и остатках из обновленного API
                const [dashboardResponse, warehouseResponse] = await Promise.all([
                    fetch('../api/inventory-analytics.php?action=dashboard'),
                    fetch('../api/inventory-analytics.php?action=warehouse-summary')
                ]);
                
                let dashboardData = null;
                let warehouseData = null;
                let errors = [];
                
                // Обработка ответа дашборда с улучшенной обработкой ошибок
                try {
                    if (!dashboardResponse.ok) {
                        throw new Error(`HTTP ${dashboardResponse.status}: ${dashboardResponse.statusText}`);
                    }
                    dashboardData = await dashboardResponse.json();
                    console.log('Dashboard data loaded:', dashboardData);
                    
                    // Проверяем статус ответа API
                    if (dashboardData.status === 'error') {
                        handleApiError(dashboardData);
                        return;
                    }
                } catch (e) {
                    console.error('Dashboard API error:', e);
                    errors.push('Ошибка загрузки данных дашборда: ' + e.message);
                }
                
                // Обработка ответа складов
                try {
                    if (!warehouseResponse.ok) {
                        throw new Error(`HTTP ${warehouseResponse.status}: ${warehouseResponse.statusText}`);
                    }
                    warehouseData = await warehouseResponse.json();
                    console.log('Warehouse data loaded:', warehouseData);
                    
                    if (warehouseData.status === 'error') {
                        console.warn('Warehouse data error:', warehouseData.message);
                        errors.push('Предупреждение: ' + warehouseData.message);
                    }
                } catch (e) {
                    console.error('Warehouse API error:', e);
                    errors.push('Ошибка загрузки данных складов: ' + e.message);
                }
                
                // Скрываем индикатор загрузки
                hideLoadingIndicator();
                
                // Показываем ошибки, если есть
                if (errors.length > 0) {
                    showErrorMessages(errors);
                }
                
                renderDashboard(dashboardData, warehouseData);
                
            } catch (error) {
                console.error('Error loading dashboard:', error);
                hideLoadingIndicator();
                showCriticalError('Критическая ошибка загрузки данных', 
                    'Не удалось подключиться к API. Проверьте подключение к серверу и базе данных.');
            }
        }
        
        // Функции для обработки ошибок и индикаторов загрузки
        function showLoadingIndicator() {
            const content = document.getElementById('dashboard-content');
            content.innerHTML = `
                <div style="text-align: center; padding: 50px;">
                    <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="margin-top: 20px; color: #666;">Загрузка данных...</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
        }
        
        function hideLoadingIndicator() {
            // Индикатор будет скрыт при рендеринге дашборда
        }
        
        function handleApiError(errorData) {
            let errorHtml = '';
            
            switch (errorData.error_code) {
                case 'NO_INVENTORY_DATA':
                    errorHtml = `
                        <div class="alert-card critical" style="margin: 20px auto; max-width: 800px;">
                            <h3>⚠️ Нет данных о складских остатках</h3>
                            <p><strong>Проблема:</strong> ${errorData.message}</p>
                            <p><strong>Детали:</strong> ${errorData.details}</p>
                            <div style="margin-top: 15px;">
                                <h4>Рекомендуемые действия:</h4>
                                <ul>
                                    ${errorData.suggestions.map(s => `<li>${s}</li>`).join('')}
                                </ul>
                            </div>
                            <button onclick="location.reload()" style="margin-top: 15px; padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                Обновить страницу
                            </button>
                        </div>
                    `;
                    break;
                    
                case 'DATABASE_ERROR':
                    errorHtml = `
                        <div class="alert-card critical" style="margin: 20px auto; max-width: 800px;">
                            <h3>🔧 Ошибка базы данных</h3>
                            <p><strong>Проблема:</strong> ${errorData.message}</p>
                            <p><strong>Детали:</strong> ${errorData.details}</p>
                            <div style="margin-top: 15px;">
                                <h4>Что можно сделать:</h4>
                                <ul>
                                    ${errorData.suggestions.map(s => `<li>${s}</li>`).join('')}
                                </ul>
                            </div>
                        </div>
                    `;
                    break;
                    
                default:
                    errorHtml = `
                        <div class="alert-card critical" style="margin: 20px auto; max-width: 800px;">
                            <h3>❌ Ошибка системы</h3>
                            <p><strong>Сообщение:</strong> ${errorData.message}</p>
                            ${errorData.details ? `<p><strong>Детали:</strong> ${errorData.details}</p>` : ''}
                            ${errorData.suggestions ? `
                                <div style="margin-top: 15px;">
                                    <h4>Рекомендации:</h4>
                                    <ul>
                                        ${errorData.suggestions.map(s => `<li>${s}</li>`).join('')}
                                    </ul>
                                </div>
                            ` : ''}
                        </div>
                    `;
            }
            
            document.getElementById('dashboard-content').innerHTML = errorHtml;
        }
        
        function showErrorMessages(errors) {
            const errorContainer = document.createElement('div');
            errorContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 1000; max-width: 400px;';
            
            errors.forEach((error, index) => {
                const errorDiv = document.createElement('div');
                errorDiv.style.cssText = `
                    background: #f8d7da; 
                    color: #721c24; 
                    padding: 12px; 
                    margin-bottom: 10px; 
                    border: 1px solid #f5c6cb; 
                    border-radius: 5px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                `;
                errorDiv.innerHTML = `
                    <strong>Предупреждение:</strong> ${error}
                    <button onclick="this.parentElement.remove()" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">×</button>
                `;
                errorContainer.appendChild(errorDiv);
                
                // Автоматически скрываем через 10 секунд
                setTimeout(() => {
                    if (errorDiv.parentElement) {
                        errorDiv.remove();
                    }
                }, 10000);
            });
            
            document.body.appendChild(errorContainer);
        }
        
        function showCriticalError(title, message) {
            document.getElementById('dashboard-content').innerHTML = `
                <div class="alert-card critical" style="margin: 20px auto; max-width: 800px; text-align: center;">
                    <h3>🚨 ${title}</h3>
                    <p style="font-size: 16px; margin: 20px 0;">${message}</p>
                    <button onclick="location.reload()" style="padding: 12px 24px; background: #e74c3c; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
                        Попробовать снова
                    </button>
                </div>
            `;
        }

        function renderDashboard(dashboardData, warehouseData) {
            // Проверяем успешность загрузки данных
            if (!dashboardData || dashboardData.status !== 'success') {
                showCriticalError(
                    'Не удалось загрузить данные', 
                    'Проверьте наличие данных в таблице inventory_data и подключение к базе данных.'
                );
                return;
            }
            
            const data = dashboardData.data;
            const warehouses = warehouseData?.status === 'success' ? warehouseData.data : [];
            
            // Показываем предупреждения о качестве данных
            if (dashboardData.metadata && dashboardData.metadata.warnings && dashboardData.metadata.warnings.length > 0) {
                showDataQualityWarnings(dashboardData.metadata);
            }
            
            let html = '';
            
        }
        
        function showDataQualityWarnings(metadata) {
            if (!metadata.warnings || metadata.warnings.length === 0) return;
            
            const warningContainer = document.createElement('div');
            warningContainer.style.cssText = 'margin-bottom: 20px;';
            
            const warningCard = document.createElement('div');
            warningCard.className = 'alert-card warning';
            warningCard.innerHTML = `
                <h3>⚠️ Предупреждения о качестве данных</h3>
                <ul>
                    ${metadata.warnings.map(warning => `<li>${warning}</li>`).join('')}
                </ul>
                ${metadata.suggestions && metadata.suggestions.length > 0 ? `
                    <div style="margin-top: 10px;">
                        <strong>Рекомендации:</strong>
                        <ul>
                            ${metadata.suggestions.map(suggestion => `<li>${suggestion}</li>`).join('')}
                        </ul>
                    </div>
                ` : ''}
                ${metadata.data_quality ? `
                    <div style="margin-top: 10px; font-size: 14px; color: #666;">
                        Товаров без названий: ${metadata.data_quality.missing_names_count} 
                        (${metadata.data_quality.missing_names_percentage}%)
                    </div>
                ` : ''}
            `;
            
            warningContainer.appendChild(warningCard);
            
            const dashboardContent = document.getElementById('dashboard-content');
            dashboardContent.insertBefore(warningContainer, dashboardContent.firstChild);
            
            // KPI секция с реальными данными
            html += renderKPISection(data);
            
            // Критические алерты
            html += renderAlertsSection(data);
            
            // Товары по категориям с реальными названиями
            html += renderProductsSection(data);
            
            // Новая секция складов
            html += renderWarehousesSection(warehouses);
            
            // Рекомендуемые действия на основе реальных данных
            html += renderActionsSection(data);
            
            document.getElementById('dashboard-content').innerHTML = html;
            
            // Загружаем детальные рекомендации
            setTimeout(() => {
                loadDetailedRecommendations();
            }, 500);
        }
        
        function renderKPISection(data) {
            const totalValue = data.total_inventory_value || 0;
            const normalCount = data.normal_count || 0;
            const totalProducts = data.summary?.total_products || 0;
            
            return \`
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #e74c3c;">\${data.critical_stock_count || 0}</div>
                        <div class="kpi-label">Критический остаток (≤5 шт)</div>
                        <div class="trend-indicator trend-down">
                            🚨 Требует срочного пополнения
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #f39c12;">\${data.low_stock_count || 0}</div>
                        <div class="kpi-label">Низкий остаток (6-20 шт)</div>
                        <div class="trend-indicator trend-down">
                            ⚠️ Планировать закупку
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #3498db;">\${normalCount}</div>
                        <div class="kpi-label">Нормальный остаток</div>
                        <div class="trend-indicator trend-stable">
                            ✅ В пределах нормы
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #27ae60;">\${data.overstock_count || 0}</div>
                        <div class="kpi-label">Избыток (>100 шт)</div>
                        <div class="trend-indicator trend-up">
                            📈 Возможность для акций
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value">\${totalProducts}</div>
                        <div class="kpi-label">Всего товаров</div>
                        <div class="trend-indicator trend-stable">
                            📦 Общее количество
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value">\${totalValue > 1000000 ? (totalValue / 1000000).toFixed(1) + 'М' : (totalValue / 1000).toFixed(0) + 'К'}</div>
                        <div class="kpi-label">Стоимость склада (₽)</div>
                        <div class="trend-indicator trend-stable">
                            💰 Общая стоимость
                        </div>
                    </div>
                </div>
            \`;
        }
        
        function renderAlertsSection(data) {
            const criticalCount = data.critical_stock_count || 0;
            const lowCount = data.low_stock_count || 0;
            const overstockCount = data.overstock_count || 0;
            const needsAttention = data.summary?.needs_attention || 0;
            
            return \`
                <div class="alerts-section">
                    <div class="alert-card critical">
                        <h3>🚨 Критические проблемы</h3>
                        <p><strong>\${criticalCount} товаров</strong> с критически низким остатком (≤5 единиц)</p>
                        <p>Эти товары могут закончиться в ближайшие дни</p>
                        <p><strong>Срочно требуется пополнение склада!</strong></p>
                    </div>
                    
                    <div class="alert-card warning">
                        <h3>⚠️ Требует внимания</h3>
                        <p><strong>\${lowCount} товаров</strong> с низким остатком (6-20 единиц)</p>
                        <p>Планируйте закупку в течение <strong>1-2 недель</strong></p>
                        <p>Потенциальный риск дефицита в ближайшем будущем</p>
                    </div>
                    
                    <div class="alert-card success">
                        <h3>💰 Возможности оптимизации</h3>
                        <p><strong>\${overstockCount} товаров</strong> с избытком (>100 единиц)</p>
                        <p>Возможность провести акции и распродажи</p>
                        <p>Освободить оборотные средства и складские площади</p>
                    </div>
                </div>
            \`;
        }
        
        function renderProductsSection(data) {
            const criticalProducts = data.critical_products || [];
            const lowStockProducts = data.low_stock_products || [];
            const overstockProducts = data.overstock_products || [];
            
            return \`
                <div class="products-grid">
                    <div class="products-card">
                        <h3>🚨 Критический остаток (≤5 шт)</h3>
                        \${criticalProducts.length > 0 ? criticalProducts.map(product => \`
                            <div class="product-item critical">
                                <div class="product-info">
                                    <div class="product-name">\${product.name || 'Товар ' + product.sku}</div>
                                    <div class="product-details">
                                        SKU: \${product.sku} | Склад: \${product.warehouse || 'Не указан'}
                                        \${product.available_stock !== undefined ? ' | Доступно: ' + product.available_stock : ''}
                                        \${product.reserved_stock !== undefined ? ' | Резерв: ' + product.reserved_stock : ''}
                                    </div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value critical">\${product.stock}</div>
                                    <div class="stock-label">шт.</div>
                                    \${product.last_updated ? '<div class="stock-label">Обновлено: ' + formatDate(product.last_updated) + '</div>' : ''}
                                </div>
                            </div>
                        \`).join('') : '<div class="no-data">Нет товаров с критическим остатком</div>'}
                    </div>
                    
                    <div class="products-card">
                        <h3>⚠️ Низкий остаток (6-20 шт)</h3>
                        \${lowStockProducts.length > 0 ? lowStockProducts.map(product => \`
                            <div class="product-item warning">
                                <div class="product-info">
                                    <div class="product-name">\${product.name || 'Товар ' + product.sku}</div>
                                    <div class="product-details">
                                        SKU: \${product.sku} | Склад: \${product.warehouse || 'Не указан'}
                                        \${product.available_stock !== undefined ? ' | Доступно: ' + product.available_stock : ''}
                                        \${product.reserved_stock !== undefined ? ' | Резерв: ' + product.reserved_stock : ''}
                                    </div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value warning">\${product.stock}</div>
                                    <div class="stock-label">шт.</div>
                                    \${product.last_updated ? '<div class="stock-label">Обновлено: ' + formatDate(product.last_updated) + '</div>' : ''}
                                </div>
                            </div>
                        \`).join('') : '<div class="no-data">Нет товаров с низким остатком</div>'}
                    </div>
                    
                    <div class="products-card">
                        <h3>📈 Избыток товара (>100 шт)</h3>
                        \${overstockProducts.length > 0 ? overstockProducts.map(product => \`
                            <div class="product-item success">
                                <div class="product-info">
                                    <div class="product-name">\${product.name || 'Товар ' + product.sku}</div>
                                    <div class="product-details">
                                        SKU: \${product.sku} | Склад: \${product.warehouse || 'Не указан'}
                                        \${product.available_stock !== undefined ? ' | Доступно: ' + product.available_stock : ''}
                                        \${product.excess_stock !== undefined ? ' | Избыток: ' + product.excess_stock : ''}
                                    </div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value success">\${product.stock}</div>
                                    <div class="stock-label">шт.</div>
                                    \${product.last_updated ? '<div class="stock-label">Обновлено: ' + formatDate(product.last_updated) + '</div>' : ''}
                                </div>
                            </div>
                        \`).join('') : '<div class="no-data">Нет товаров с избытком</div>'}
                    </div>
                </div>
            \`;
        }
        
        function renderWarehousesSection(warehouses) {
            if (!warehouses || warehouses.length === 0) {
                return \`
                    <div class="actions-section">
                        <h3>🏢 Склады</h3>
                        <div class="no-data">Нет данных о складах</div>
                    </div>
                \`;
            }
            
            return \`
                <div class="actions-section">
                    <h3>🏢 Сводка по складам</h3>
                    <div class="products-grid">
                        \${warehouses.map(warehouse => \`
                            <div class="products-card">
                                <h3>📦 \${warehouse.warehouse_name}</h3>
                                <div class="kpi-grid" style="grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px;">
                                    <div class="kpi-card" style="padding: 10px;">
                                        <div class="kpi-value" style="font-size: 1.2em;">\${warehouse.total_products}</div>
                                        <div class="kpi-label">Товаров</div>
                                    </div>
                                    <div class="kpi-card" style="padding: 10px;">
                                        <div class="kpi-value" style="font-size: 1.2em;">\${warehouse.total_stock}</div>
                                        <div class="kpi-label">Общий остаток</div>
                                    </div>
                                </div>
                                
                                <div class="product-item \${getWarehouseStatusClass(warehouse.status)}">
                                    <div class="product-info">
                                        <div class="product-name">Статус склада: \${getWarehouseStatusText(warehouse.status)}</div>
                                        <div class="product-details">
                                            Критических: \${warehouse.critical_count} | 
                                            Низких: \${warehouse.low_count} | 
                                            Избыток: \${warehouse.overstock_count}
                                        </div>
                                        \${warehouse.stock_percentage ? '<div class="product-details">Доля от общих остатков: ' + warehouse.stock_percentage + '%</div>' : ''}
                                    </div>
                                    <div class="product-stock">
                                        <button class="btn btn-primary" onclick="showWarehouseDetails('\${warehouse.warehouse_name}')">
                                            📊 Детали
                                        </button>
                                    </div>
                                </div>
                            </div>
                        \`).join('')}
                    </div>
                </div>
            \`;
        }
        
        function renderActionsSection(data) {
            const recommendations = data.recommendations || [];
            const criticalCount = data.critical_stock_count || 0;
            const lowCount = data.low_stock_count || 0;
            const overstockCount = data.overstock_count || 0;
            
            return \`
                <div class="actions-section">
                    <h3>🎯 Рекомендуемые действия</h3>
                    \${recommendations.length > 0 ? \`
                        <div class="actions-grid">
                            \${recommendations.map(rec => \`
                                <div class="action-card \${getRecommendationClass(rec.type)}">
                                    <div class="action-title">\${getRecommendationIcon(rec.type)} \${rec.title}</div>
                                    <div class="action-description">\${rec.message}</div>
                                    <div class="action-buttons">
                                        <button class="btn \${getRecommendationButtonClass(rec.type)}" onclick="handleRecommendation('\${rec.action}')">
                                            \${getRecommendationButtonText(rec.action)}
                                        </button>
                                        \${rec.type === 'urgent' ? '<button class="btn btn-warning" onclick="showCriticalProducts()">📊 Список товаров</button>' : ''}
                                        \${rec.type === 'optimization' ? '<button class="btn btn-primary" onclick="exportOverstockReport()">📄 Экспорт списка</button>' : ''}
                                    </div>
                                </div>
                            \`).join('')}
                        </div>
                    \` : \`
                        <div class="actions-grid">
                            <div class="action-card normal">
                                <div class="action-title">✅ Все в порядке</div>
                                <div class="action-description">
                                    Критических проблем с остатками не обнаружено. 
                                    Продолжайте мониторинг складских остатков.
                                </div>
                                <div class="action-buttons">
                                    <button class="btn btn-primary" onclick="loadDashboard()">
                                        🔄 Обновить данные
                                    </button>
                                </div>
                            </div>
                        </div>
                    \`}
                </div>
            \`;
        }
        
        // Вспомогательные функции для форматирования
        function formatDate(dateString) {
            if (!dateString) return 'Не указано';
            const date = new Date(dateString);
            return date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
        }
        
        function getWarehouseStatusClass(status) {
            switch(status) {
                case 'critical': return 'critical';
                case 'attention': return 'warning';
                case 'overstock': return 'success';
                case 'good': return 'success';
                default: return 'warning';
            }
        }
        
        function getWarehouseStatusText(status) {
            switch(status) {
                case 'critical': return 'Критическое состояние';
                case 'attention': return 'Требует внимания';
                case 'overstock': return 'Много избытков';
                case 'good': return 'Хорошее состояние';
                default: return 'Неизвестно';
            }
        }
        
        function getRecommendationClass(type) {
            switch(type) {
                case 'urgent': return 'urgent';
                case 'optimization': return 'important';
                case 'planning': return 'normal';
                default: return 'normal';
            }
        }
        
        function getRecommendationIcon(type) {
            switch(type) {
                case 'urgent': return '🚨';
                case 'optimization': return '💰';
                case 'planning': return '📅';
                default: return '📊';
            }
        }
        
        function getRecommendationButtonClass(type) {
            switch(type) {
                case 'urgent': return 'btn-danger';
                case 'optimization': return 'btn-success';
                case 'planning': return 'btn-primary';
                default: return 'btn-primary';
            }
        }
        
        function getRecommendationButtonText(action) {
            switch(action) {
                case 'replenish_critical': return '🚨 Срочно пополнить';
                case 'create_promotions': return '💰 Создать акции';
                case 'plan_replenishment': return '📅 Запланировать';
                case 'view_critical_products': return '📊 Список товаров';
                case 'create_purchase_order': return '📋 Заказ поставщику';
                case 'notify_manager': return '📧 Уведомить';
                case 'view_overstock_products': return '📈 Товары с избытком';
                case 'create_promotion': return '🎯 Создать акцию';
                case 'export_overstock_report': return '📄 Экспорт';
                case 'view_low_stock_products': return '⚠️ Низкий остаток';
                case 'plan_replenishment': return '📋 Планировать';
                case 'set_reorder_points': return '⚙️ Настроить';
                case 'view_warehouse_analysis': return '🏢 Анализ складов';
                case 'plan_transfers': return '🔄 Перемещения';
                case 'setup_alerts': return '🔔 Уведомления';
                case 'schedule_reports': return '📊 Отчеты';
                default: return '▶️ Выполнить';
            }
        }
        
        // Функции для обработки рекомендаций
        async function loadDetailedRecommendations() {
            try {
                const response = await fetch('../api/inventory-analytics.php?action=recommendations');
                const data = await response.json();
                
                if (data.status === 'success') {
                    renderDetailedRecommendations(data.data);
                } else {
                    console.error('Error loading recommendations:', data.message);
                }
            } catch (error) {
                console.error('Error loading detailed recommendations:', error);
            }
        }
        
        function renderDetailedRecommendations(data) {
            const recommendations = data.recommendations || [];
            const priorityActions = data.priority_actions || [];
            const summary = data.summary || {};
            
            let html = `
                <div class="actions-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3>🎯 Система рекомендаций</h3>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <span class="kpi-card" style="padding: 5px 10px; margin: 0; font-size: 12px;">
                                Всего: ${summary.total_recommendations || 0}
                            </span>
                            <span class="kpi-card" style="padding: 5px 10px; margin: 0; font-size: 12px; background: #fee;">
                                Приоритетных: ${summary.high_priority_count || 0}
                            </span>
                            <button class="btn btn-primary" onclick="loadDetailedRecommendations()">
                                🔄 Обновить
                            </button>
                        </div>
                    </div>
            `;
            
            // Приоритетные действия
            if (priorityActions.length > 0) {
                html += `
                    <div class="alert-card critical" style="margin-bottom: 20px;">
                        <h3>⚡ Требует немедленного внимания</h3>
                        ${priorityActions.map(action => `
                            <div style="margin: 10px 0; padding: 10px; background: rgba(255,255,255,0.1); border-radius: 5px;">
                                <strong>${action.title}</strong><br>
                                <small>${action.description}</small>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            // Все рекомендации
            if (recommendations.length > 0) {
                html += `<div class="actions-grid">`;
                
                recommendations.forEach(rec => {
                    const priorityClass = rec.priority <= 2 ? 'urgent' : rec.priority <= 3 ? 'important' : 'normal';
                    const priorityText = rec.priority === 1 ? 'Критический' : 
                                       rec.priority === 2 ? 'Высокий' : 
                                       rec.priority === 3 ? 'Средний' : 'Низкий';
                    
                    html += `
                        <div class="action-card ${priorityClass}">
                            <div class="action-title">
                                ${getPriorityIcon(rec.priority)} ${rec.title}
                                <span style="float: right; font-size: 10px; background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 10px;">
                                    ${priorityText}
                                </span>
                            </div>
                            <div class="action-description">${rec.description}</div>
                            
                            ${rec.affected_products ? `
                                <div style="font-size: 12px; color: #666; margin: 10px 0;">
                                    📦 Затронуто товаров: ${rec.affected_products}
                                    ${rec.estimated_days !== undefined ? ` | ⏱️ Срок: ${rec.estimated_days === 0 ? 'Немедленно' : rec.estimated_days + ' дн.'}` : ''}
                                    ${rec.estimated_cost ? ` | 💰 Стоимость: ${formatCurrency(rec.estimated_cost)}` : ''}
                                    ${rec.estimated_savings ? ` | 💵 Экономия: ${formatCurrency(rec.estimated_savings)}` : ''}
                                </div>
                            ` : ''}
                            
                            <div class="action-buttons">
                                ${rec.actions.map(action => `
                                    <button class="btn ${getActionButtonClass(action.type)}" 
                                            onclick="handleRecommendationAction('${action.action}', '${rec.id}')">
                                        ${action.label}
                                    </button>
                                `).join('')}
                            </div>
                            
                            ${rec.details ? `
                                <details style="margin-top: 10px;">
                                    <summary style="cursor: pointer; font-size: 12px; color: #666;">Подробности</summary>
                                    <div style="font-size: 11px; color: #666; margin-top: 5px; padding: 5px; background: rgba(0,0,0,0.05); border-radius: 3px;">
                                        ${rec.details.business_impact ? `<div><strong>Влияние:</strong> ${rec.details.business_impact}</div>` : ''}
                                        ${rec.details.recommended_action_time ? `<div><strong>Время действия:</strong> ${rec.details.recommended_action_time}</div>` : ''}
                                        ${rec.details.recommended_discount ? `<div><strong>Рекомендуемая скидка:</strong> ${rec.details.recommended_discount}</div>` : ''}
                                        ${rec.details.planning_horizon ? `<div><strong>Горизонт планирования:</strong> ${rec.details.planning_horizon}</div>` : ''}
                                    </div>
                                </details>
                            ` : ''}
                        </div>
                    `;
                });
                
                html += `</div>`;
            } else {
                html += `
                    <div class="action-card normal">
                        <div class="action-title">✅ Рекомендаций нет</div>
                        <div class="action-description">
                            Система не обнаружила критических проблем, требующих немедленного внимания.
                            Продолжайте регулярный мониторинг складских остатков.
                        </div>
                        <div class="action-buttons">
                            <button class="btn btn-primary" onclick="loadDashboard()">
                                🔄 Обновить данные
                            </button>
                        </div>
                    </div>
                `;
            }
            
            html += `</div>`;
            
            // Добавляем секцию рекомендаций в DOM
            const existingRecommendations = document.querySelector('.recommendations-section');
            if (existingRecommendations) {
                existingRecommendations.innerHTML = html;
            } else {
                // Вставляем после секции складов
                const warehouseSection = document.querySelector('.actions-section');
                if (warehouseSection) {
                    warehouseSection.insertAdjacentHTML('afterend', `<div class="recommendations-section">${html}</div>`);
                }
            }
        }
        
        function getPriorityIcon(priority) {
            switch(priority) {
                case 1: return '🚨';
                case 2: return '⚠️';
                case 3: return '📋';
                case 4: return '🔧';
                case 5: return '⚙️';
                default: return '📊';
            }
        }
        
        function getActionButtonClass(type) {
            switch(type) {
                case 'primary': return 'btn-primary';
                case 'danger': return 'btn-danger';
                case 'warning': return 'btn-warning';
                case 'success': return 'btn-success';
                case 'secondary': return 'btn-secondary';
                default: return 'btn-primary';
            }
        }
        
        function formatCurrency(amount) {
            if (amount > 1000000) {
                return (amount / 1000000).toFixed(1) + 'М ₽';
            } else if (amount > 1000) {
                return (amount / 1000).toFixed(0) + 'К ₽';
            } else {
                return amount.toFixed(0) + ' ₽';
            }
        }
        
        function handleRecommendationAction(action, recommendationId) {
            console.log('Handling recommendation action:', action, 'for recommendation:', recommendationId);
            
            switch(action) {
                case 'view_critical_products':
                    showCriticalProducts();
                    break;
                case 'view_overstock_products':
                    showOverstockProducts();
                    break;
                case 'view_low_stock_products':
                    showLowStockProducts();
                    break;
                case 'create_purchase_order':
                    alert('Функция создания заказа поставщику будет реализована в следующей версии');
                    break;
                case 'create_promotion':
                    alert('Функция создания акций будет реализована в следующей версии');
                    break;
                case 'export_overstock_report':
                    exportOverstockReport();
                    break;
                case 'notify_manager':
                    alert('Уведомление отправлено менеджеру');
                    break;
                case 'plan_replenishment':
                    alert('Функция планирования пополнения будет реализована в следующей версии');
                    break;
                case 'set_reorder_points':
                    alert('Функция настройки точек заказа будет реализована в следующей версии');
                    break;
                case 'view_warehouse_analysis':
                    showWarehouseAnalysis();
                    break;
                case 'plan_transfers':
                    alert('Функция планирования перемещений будет реализована в следующей версии');
                    break;
                case 'setup_alerts':
                    alert('Функция настройки уведомлений будет реализована в следующей версии');
                    break;
                case 'schedule_reports':
                    alert('Функция планирования отчетов будет реализована в следующей версии');
                    break;
                default:
                    handleRecommendation(action);
            }
        }
            switch(action) {
                case 'replenish_critical': return '📋 Создать заказ';
                case 'create_promotions': return '🎉 Создать акцию';
                case 'plan_replenishment': return '📅 План закупок';
                default: return '📊 Подробнее';
            }
        }
        
        // Дополнительные функции для работы с рекомендациями
        async function showLowStockProducts() {
            try {
                const response = await fetch('../api/inventory-analytics.php?action=dashboard');
                const data = await response.json();
                
                if (data.status === 'success') {
                    displayProductModal('Товары с низким остатком (6-20 шт)', data.data.low_stock_products, 'low');
                }
            } catch (error) {
                console.error('Error loading low stock products:', error);
            }
        }
        
        function displayProductModal(title, products, type) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0,0,0,0.5); z-index: 1000; display: flex; 
                align-items: center; justify-content: center;
            `;
            
            const content = document.createElement('div');
            content.style.cssText = `
                background: white; padding: 30px; border-radius: 15px; 
                max-width: 800px; max-height: 80vh; overflow-y: auto;
                box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            `;
            
            let html = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>${title}</h3>
                    <button onclick="this.closest('.modal').remove()" style="background: none; border: none; font-size: 24px; cursor: pointer;">×</button>
                </div>
            `;
            
            if (products && products.length > 0) {
                html += `<div style="max-height: 400px; overflow-y: auto;">`;
                products.forEach(product => {
                    const statusClass = type === 'critical' ? 'critical' : type === 'overstock' ? 'success' : 'warning';
                    html += `
                        <div class="product-item ${statusClass}" style="margin-bottom: 10px;">
                            <div class="product-info">
                                <div class="product-name">${product.name || 'Товар ' + product.sku}</div>
                                <div class="product-details">
                                    SKU: ${product.sku} | Склад: ${product.warehouse || 'Не указан'}
                                    ${product.available_stock !== undefined ? ' | Доступно: ' + product.available_stock : ''}
                                    ${product.reserved_stock !== undefined ? ' | Резерв: ' + product.reserved_stock : ''}
                                    ${product.excess_stock !== undefined ? ' | Избыток: ' + product.excess_stock : ''}
                                </div>
                            </div>
                            <div class="product-stock">
                                <div class="stock-value ${statusClass}">${product.stock}</div>
                                <div class="stock-label">шт.</div>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            } else {
                html += `<div class="no-data">Товары не найдены</div>`;
            }
            
            content.innerHTML = html;
            modal.appendChild(content);
            modal.className = 'modal';
            document.body.appendChild(modal);
            
            // Закрытие по клику вне модального окна
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
        
        function convertToCSV(data) {
            const headers = ['SKU', 'Название', 'Остаток', 'Доступно', 'Резерв', 'Склад', 'Избыток', 'Стоимость избытка'];
            const rows = data.map(item => [
                item.sku,
                item.name,
                item.stock,
                item.available_stock || 0,
                item.reserved_stock || 0,
                item.warehouse,
                item.excess_stock || 0,
                item.excess_value || 0
            ]);
            
            return [headers, ...rows].map(row => 
                row.map(field => `"${field}"`).join(',')
            ).join('\\n');
        }
        
        function downloadCSV(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function displayWarehouseAnalysisModal(warehouses) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0,0,0,0.5); z-index: 1000; display: flex; 
                align-items: center; justify-content: center;
            `;
            
            const content = document.createElement('div');
            content.style.cssText = `
                background: white; padding: 30px; border-radius: 15px; 
                max-width: 1000px; max-height: 80vh; overflow-y: auto;
                box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            `;
            
            let html = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>🏢 Анализ складов</h3>
                    <button onclick="this.closest('.modal').remove()" style="background: none; border: none; font-size: 24px; cursor: pointer;">×</button>
                </div>
            `;
            
            if (warehouses && warehouses.length > 0) {
                html += `<div class="warehouse-grid">`;
                warehouses.forEach(warehouse => {
                    html += `
                        <div class="warehouse-card ${warehouse.status}">
                            <div class="warehouse-header">
                                <div class="warehouse-name">${warehouse.warehouse_name}</div>
                                <div class="warehouse-status ${warehouse.status}">
                                    ${getWarehouseStatusText(warehouse.status)}
                                </div>
                            </div>
                            <div class="warehouse-stats">
                                <div class="warehouse-stat">
                                    <div class="warehouse-stat-value">${warehouse.total_products}</div>
                                    <div class="warehouse-stat-label">Товаров</div>
                                </div>
                                <div class="warehouse-stat">
                                    <div class="warehouse-stat-value">${warehouse.total_stock}</div>
                                    <div class="warehouse-stat-label">Остаток</div>
                                </div>
                                <div class="warehouse-stat">
                                    <div class="warehouse-stat-value">${warehouse.critical_count}</div>
                                    <div class="warehouse-stat-label">Критических</div>
                                </div>
                                <div class="warehouse-stat">
                                    <div class="warehouse-stat-value">${warehouse.overstock_count}</div>
                                    <div class="warehouse-stat-label">Избыток</div>
                                </div>
                            </div>
                            <div style="font-size: 12px; color: #666;">
                                Доля от общих остатков: ${warehouse.stock_percentage}%<br>
                                Средний остаток на товар: ${warehouse.avg_stock_per_product}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            } else {
                html += `<div class="no-data">Нет данных о складах</div>`;
            }
            
            content.innerHTML = html;
            modal.appendChild(content);
            modal.className = 'modal';
            document.body.appendChild(modal);
            
            // Закрытие по клику вне модального окна
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
        
        // Функции действий
        function handleRecommendation(action) {
            switch(action) {
                case 'replenish_critical':
                    createPurchaseOrder('critical');
                    break;
                case 'create_promotions':
                    createPromotion();
                    break;
                case 'plan_replenishment':
                    planPurchases();
                    break;
                default:
                    alert('Действие будет добавлено в следующей версии');
            }
        }
        
        function createPurchaseOrder(type) {
            alert('Функция создания заказа поставщику будет добавлена в следующей версии');
        }
        
        async function showOverstockProducts() {
            try {
                const response = await fetch('../api/inventory-analytics.php?action=overstock-products');
                const data = await response.json();
                
                if (data.status === 'success') {
                    displayProductModal('Товары с избытком (>100 шт)', data.data, 'overstock');
                }
            } catch (error) {
                console.error('Error loading overstock products:', error);
            }
        }
        
        async function showWarehouseAnalysis() {
            try {
                const response = await fetch('../api/inventory-analytics.php?action=warehouse-summary');
                const data = await response.json();
                
                if (data.status === 'success') {
                    displayWarehouseAnalysisModal(data.data);
                }
            } catch (error) {
                console.error('Error loading warehouse analysis:', error);
            }
        }
        
        async function showWarehouseDetails(warehouseName) {
            try {
                const response = await fetch(\`../api/inventory-analytics.php?action=warehouse-details&warehouse=\${encodeURIComponent(warehouseName)}\`);
                const data = await response.json();
                
                if (data.status === 'success') {
                    const warehouse = data.data;
                    let html = \`
                        <h2>Склад: \${warehouse.warehouse_name}</h2>
                        <p>Всего товаров: \${warehouse.total_products}</p>
                        <p>Общая стоимость: \${warehouse.total_value.toLocaleString('ru-RU')} ₽</p>
                        <h3>Критические товары (\${warehouse.summary.critical_count}):</h3>
                        <ul>
                    \`;
                    
                    warehouse.products_by_status.critical.forEach(product => {
                        html += \`<li>\${product.name} (SKU: \${product.sku}) - \${product.current_stock} шт.</li>\`;
                    });
                    
                    html += '</ul>';
                    
                    const newWindow = window.open('', '_blank', 'width=900,height=700');
                    newWindow.document.write(\`
                        <html>
                            <head><title>Детали склада: \${warehouse.warehouse_name}</title></head>
                            <body style="font-family: Arial, sans-serif; padding: 20px;">
                                \${html}
                                <button onclick="window.close()">Закрыть</button>
                            </body>
                        </html>
                    \`);
                } else {
                    alert('Ошибка загрузки данных о складе');
                }
            } catch (error) {
                alert('Ошибка при получении данных о складе');
            }
        }
        
        function createPromotion() {
            alert('Функция создания акций будет добавлена в следующей версии');
        }
        
        function exportOverstockReport() {
            fetch('../api/inventory-analytics.php?action=overstock-products')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.data.length > 0) {
                        const csv = convertToCSV(data.data);
                        downloadCSV(csv, 'overstock_report_' + new Date().toISOString().split('T')[0] + '.csv');
                    } else {
                        alert('Нет данных для экспорта');
                    }
                })
                .catch(error => {
                    console.error('Error exporting report:', error);
                    alert('Ошибка при экспорте отчета');
                });
        }
        
        function planPurchases() {
            alert('Функция планирования закупок будет добавлена в следующей версии');
        }
        
        function forecastDemand() {
            alert('Функция прогнозирования спроса будет добавлена в следующей версии');
        }
        
        // Загрузка при старте
        loadDashboard();
        
        // Автообновление каждые 5 минут
        setInterval(loadDashboard, 300000);
    </script>
</body>
</html>