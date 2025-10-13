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
                // Загружаем данные о товарах и остатках
                const [inventoryResponse, qualityResponse] = await Promise.all([
                    fetch('../api/inventory-analytics.php'),
                    fetch('../api/quality-metrics.php')
                ]);
                
                let inventoryData = null;
                let qualityData = null;
                
                try {
                    inventoryData = await inventoryResponse.json();
                } catch (e) {
                    console.log('Inventory API not available, using mock data');
                }
                
                try {
                    qualityData = await qualityResponse.json();
                } catch (e) {
                    console.log('Quality API not available');
                }
                
                renderDashboard(inventoryData, qualityData);
                
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('dashboard-content').innerHTML = 
                    '<p style="color: red; text-align: center;">Ошибка загрузки данных. Показываем демо-данные.</p>';
                renderDashboard(null, null);
            }
        }
        
        function renderDashboard(inventoryData, qualityData) {
            // Используем реальные данные или демо-данные
            const mockData = generateMockInventoryData();
            const data = inventoryData?.status === 'success' ? inventoryData.data : mockData;
            
            let html = '';
            
            // KPI секция
            html += renderKPISection(data);
            
            // Критические алерты
            html += renderAlertsSection(data);
            
            // Товары по категориям
            html += renderProductsSection(data);
            
            // Рекомендуемые действия
            html += renderActionsSection(data);
            
            document.getElementById('dashboard-content').innerHTML = html;
        }
        
        function renderKPISection(data) {
            return \`
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #e74c3c;">\${data.critical_stock_count}</div>
                        <div class="kpi-label">Критический остаток</div>
                        <div class="trend-indicator trend-down">
                            ↘️ Требует срочного пополнения
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #f39c12;">\${data.low_stock_count}</div>
                        <div class="kpi-label">Низкий остаток</div>
                        <div class="trend-indicator trend-down">
                            ⚠️ Планировать закупку
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" style="color: #27ae60;">\${data.overstock_count}</div>
                        <div class="kpi-label">Избыток товара</div>
                        <div class="trend-indicator trend-up">
                            📈 Возможность для акций
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value">\${(data.total_inventory_value / 1000000).toFixed(1)}М</div>
                        <div class="kpi-label">Стоимость склада (₽)</div>
                        <div class="trend-indicator trend-stable">
                            📊 Общая стоимость
                        </div>
                    </div>
                </div>
            \`;
        }
        
        function renderAlertsSection(data) {
            return \`
                <div class="alerts-section">
                    <div class="alert-card critical">
                        <h3>🚨 Критические проблемы</h3>
                        <p><strong>\${data.critical_stock_count} товаров</strong> с критически низким остатком</p>
                        <p>Возможные потери продаж: <strong>\${(data.potential_lost_sales / 1000).toFixed(0)}К ₽/день</strong></p>
                        <p>Срочно требуется пополнение склада!</p>
                    </div>
                    
                    <div class="alert-card warning">
                        <h3>⚠️ Требует внимания</h3>
                        <p><strong>\${data.low_stock_count} товаров</strong> с низким остатком</p>
                        <p>Планируйте закупку в течение <strong>7-14 дней</strong></p>
                        <p>Потенциальный риск дефицита</p>
                    </div>
                    
                    <div class="alert-card success">
                        <h3>💰 Возможности</h3>
                        <p><strong>\${data.overstock_count} товаров</strong> с избытком</p>
                        <p>Возможность провести акции и распродажи</p>
                        <p>Освободить \${(data.overstock_value / 1000000).toFixed(1)}М ₽ оборотных средств</p>
                    </div>
                </div>
            \`;
        }
        
        function renderProductsSection(data) {
            return \`
                <div class="products-grid">
                    <div class="products-card">
                        <h3>🚨 Критический остаток</h3>
                        \${data.critical_products.map(product => \`
                            <div class="product-item critical">
                                <div class="product-info">
                                    <div class="product-name">\${product.name}</div>
                                    <div class="product-details">
                                        Артикул: \${product.sku} | Продаж/день: \${product.daily_sales}
                                    </div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value critical">\${product.stock}</div>
                                    <div class="stock-label">шт. (на \${product.days_left} дн.)</div>
                                </div>
                            </div>
                        \`).join('')}
                    </div>
                    
                    <div class="products-card">
                        <h3>⚠️ Низкий остаток</h3>
                        \${data.low_stock_products.map(product => \`
                            <div class="product-item warning">
                                <div class="product-info">
                                    <div class="product-name">\${product.name}</div>
                                    <div class="product-details">
                                        Артикул: \${product.sku} | Продаж/день: \${product.daily_sales}
                                    </div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value warning">\${product.stock}</div>
                                    <div class="stock-label">шт. (на \${product.days_left} дн.)</div>
                                </div>
                            </div>
                        \`).join('')}
                    </div>
                    
                    <div class="products-card">
                        <h3>📈 Избыток товара</h3>
                        \${data.overstock_products.map(product => \`
                            <div class="product-item success">
                                <div class="product-info">
                                    <div class="product-name">\${product.name}</div>
                                    <div class="product-details">
                                        Артикул: \${product.sku} | Продаж/день: \${product.daily_sales}
                                    </div>
                                </div>
                                <div class="product-stock">
                                    <div class="stock-value success">\${product.stock}</div>
                                    <div class="stock-label">шт. (на \${product.days_left} дн.)</div>
                                </div>
                            </div>
                        \`).join('')}
                    </div>
                </div>
            \`;
        }
        
        function renderActionsSection(data) {
            return \`
                <div class="actions-section">
                    <h3>🎯 Рекомендуемые действия</h3>
                    <div class="actions-grid">
                        <div class="action-card urgent">
                            <div class="action-title">🚨 Срочное пополнение</div>
                            <div class="action-description">
                                \${data.critical_stock_count} товаров требуют немедленного пополнения. 
                                Потери продаж могут составить \${(data.potential_lost_sales / 1000).toFixed(0)}К ₽/день.
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-danger" onclick="createPurchaseOrder('critical')">
                                    📋 Создать заказ поставщику
                                </button>
                                <button class="btn btn-warning" onclick="showCriticalProducts()">
                                    📊 Список товаров
                                </button>
                            </div>
                        </div>
                        
                        <div class="action-card important">
                            <div class="action-title">💰 Запустить акции</div>
                            <div class="action-description">
                                \${data.overstock_count} товаров с избытком. Можно освободить 
                                \${(data.overstock_value / 1000000).toFixed(1)}М ₽ оборотных средств.
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-success" onclick="createPromotion()">
                                    🎉 Создать акцию
                                </button>
                                <button class="btn btn-primary" onclick="exportOverstockReport()">
                                    📄 Экспорт списка
                                </button>
                            </div>
                        </div>
                        
                        <div class="action-card normal">
                            <div class="action-title">📈 Планирование закупок</div>
                            <div class="action-description">
                                \${data.low_stock_count} товаров требуют планового пополнения в течение 1-2 недель.
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-primary" onclick="planPurchases()">
                                    📅 План закупок
                                </button>
                                <button class="btn btn-primary" onclick="forecastDemand()">
                                    📊 Прогноз спроса
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            \`;
        }
        
        function generateMockInventoryData() {
            return {
                critical_stock_count: 12,
                low_stock_count: 28,
                overstock_count: 15,
                total_inventory_value: 8500000,
                potential_lost_sales: 45000,
                overstock_value: 2100000,
                critical_products: [
                    {
                        name: "Подшипник 6205-2RS",
                        sku: "BRG-6205-2RS",
                        stock: 3,
                        daily_sales: 2,
                        days_left: 1
                    },
                    {
                        name: "Сальник 40x62x10",
                        sku: "SEAL-40-62-10",
                        stock: 5,
                        daily_sales: 3,
                        days_left: 2
                    },
                    {
                        name: "Прокладка головки блока",
                        sku: "GSKT-HEAD-001",
                        stock: 2,
                        daily_sales: 1,
                        days_left: 2
                    }
                ],
                low_stock_products: [
                    {
                        name: "Фильтр масляный W712/75",
                        sku: "FILT-OIL-W712",
                        stock: 15,
                        daily_sales: 2,
                        days_left: 7
                    },
                    {
                        name: "Свеча зажигания NGK BPR6ES",
                        sku: "SPARK-NGK-BPR6",
                        stock: 25,
                        daily_sales: 3,
                        days_left: 8
                    },
                    {
                        name: "Тормозные колодки передние",
                        sku: "BRAKE-PAD-F001",
                        stock: 8,
                        daily_sales: 1,
                        days_left: 8
                    }
                ],
                overstock_products: [
                    {
                        name: "Амортизатор задний Monroe",
                        sku: "SHOCK-MONROE-R",
                        stock: 150,
                        daily_sales: 1,
                        days_left: 150
                    },
                    {
                        name: "Радиатор охлаждения",
                        sku: "RAD-COOL-001",
                        stock: 45,
                        daily_sales: 0.3,
                        days_left: 150
                    },
                    {
                        name: "Генератор 14V 90A",
                        sku: "ALT-14V-90A",
                        stock: 25,
                        daily_sales: 0.2,
                        days_left: 125
                    }
                ]
            };
        }
        
        // Функции действий
        function createPurchaseOrder(type) {
            alert('Функция создания заказа поставщику будет добавлена в следующей версии');
        }
        
        function showCriticalProducts() {
            alert('Открытие детального списка критических товаров');
        }
        
        function createPromotion() {
            alert('Функция создания акций будет добавлена в следующей версии');
        }
        
        function exportOverstockReport() {
            alert('Экспорт отчета по избыткам будет добавлен в следующей версии');
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