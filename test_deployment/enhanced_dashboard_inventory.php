<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MDM Система - Полный дашборд остатков</title>
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
            max-width: 1400px;
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
        
        .tabs {
            display: flex;
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .tab {
            flex: 1;
            padding: 15px 20px;
            background: #f8f9fa;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            background: #667eea;
            color: white;
        }
        
        .tab:hover {
            background: #e2e8f0;
        }
        
        .tab.active:hover {
            background: #5a67d8;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .card h3 {
            color: #4a5568;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .metric {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .metric.success { color: #38a169; }
        .metric.warning { color: #d69e2e; }
        .metric.danger { color: #e53e3e; }
        
        .search-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4a5568;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background 0.3s ease;
        }
        
        .btn:hover {
            background: #5a67d8;
        }
        
        .btn-secondary {
            background: #718096;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-critical {
            background: #fed7d7;
            color: #c53030;
        }
        
        .status-low {
            background: #feebc8;
            color: #c05621;
        }
        
        .status-good {
            background: #c6f6d5;
            color: #2f855a;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
        }
        
        .refresh-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            border: none;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: #5a67d8;
            transform: scale(1.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 MDM Система управления остатками</h1>
            <p>Полный контроль над товарными остатками и складскими операциями</p>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('overview')">📊 Обзор</button>
            <button class="tab" onclick="showTab('products')">📦 Товары</button>
            <button class="tab" onclick="showTab('critical')">⚠️ Критические остатки</button>
            <button class="tab" onclick="showTab('analytics')">📈 Аналитика</button>
        </div>

        <!-- Обзор -->
        <div id="overview" class="tab-content active">
            <div class="cards" id="overview-cards">
                <div class="loading">Загрузка данных...</div>
            </div>
        </div>

        <!-- Товары -->
        <div id="products" class="tab-content">
            <div class="search-section">
                <h3>🔍 Поиск товаров</h3>
                <div class="search-form">
                    <div class="form-group">
                        <label>Поиск по ID или названию</label>
                        <input type="text" id="product-search" placeholder="Введите ID товара или название">
                    </div>
                    <div class="form-group">
                        <label>Склад</label>
                        <select id="warehouse-filter">
                            <option value="">Все склады</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Лимит</label>
                        <select id="product-limit">
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <button class="btn" onclick="searchProducts()">Найти</button>
                    <button class="btn btn-secondary" onclick="clearSearch()">Очистить</button>
                </div>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID товара</th>
                            <th>Название</th>
                            <th>Склад</th>
                            <th>Остаток</th>
                            <th>Зарезервировано</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody id="products-table">
                        <tr><td colspan="6" class="loading">Загрузка товаров...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Критические остатки -->
        <div id="critical" class="tab-content">
            <div class="search-section">
                <h3>⚠️ Настройки критических остатков</h3>
                <div class="search-form">
                    <div class="form-group">
                        <label>Порог критического остатка</label>
                        <input type="number" id="critical-threshold" value="5" min="1" max="100">
                    </div>
                    <button class="btn" onclick="loadCriticalItems()">Обновить</button>
                </div>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID товара</th>
                            <th>Название</th>
                            <th>Склад</th>
                            <th>Остаток</th>
                            <th>Зарезервировано</th>
                            <th>Уровень критичности</th>
                        </tr>
                    </thead>
                    <tbody id="critical-table">
                        <tr><td colspan="6" class="loading">Загрузка критических остатков...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Аналитика -->
        <div id="analytics" class="tab-content">
            <div class="cards" id="analytics-cards">
                <div class="loading">Загрузка аналитики...</div>
            </div>
            <div class="table-container">
                <h3>📈 Топ товары по остаткам</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID товара</th>
                            <th>Название</th>
                            <th>Общий остаток</th>
                            <th>Зарезервировано</th>
                            <th>Складов</th>
                            <th>Уровень запасов</th>
                        </tr>
                    </thead>
                    <tbody id="top-products-table">
                        <tr><td colspan="6" class="loading">Загрузка топ товаров...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <button class="refresh-btn" onclick="refreshCurrentTab()" title="Обновить данные">
        🔄
    </button>

    <script>
        const API_BASE = '/api/inventory-v4.php';
        let currentTab = 'overview';

        // Переключение табов
        function showTab(tabName) {
            // Скрыть все табы
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Показать выбранный таб
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
            currentTab = tabName;

            // Загрузить данные для таба
            loadTabData(tabName);
        }

        // Загрузка данных для таба
        function loadTabData(tabName) {
            switch(tabName) {
                case 'overview':
                    loadOverview();
                    break;
                case 'products':
                    loadProducts();
                    loadWarehouses();
                    break;
                case 'critical':
                    loadCriticalItems();
                    break;
                case 'analytics':
                    loadAnalytics();
                    break;
            }
        }

        // Загрузка обзора
        async function loadOverview() {
            try {
                const response = await fetch(`${API_BASE}?action=overview`);
                const data = await response.json();
                
                if (data.success) {
                    displayOverview(data.data);
                } else {
                    showError('overview-cards', data.error);
                }
            } catch (error) {
                showError('overview-cards', 'Ошибка загрузки данных: ' + error.message);
            }
        }

        // Отображение обзора
        function displayOverview(data) {
            const overview = data.overview;
            const html = `
                <div class="card">
                    <h3>📦 Всего товаров</h3>
                    <div class="metric">${overview.total_products || 0}</div>
                    <small>записей в системе</small>
                </div>
                <div class="card">
                    <h3>✅ В наличии</h3>
                    <div class="metric success">${overview.products_in_stock || 0}</div>
                    <small>товаров доступно</small>
                </div>
                <div class="card">
                    <h3>📊 Общий остаток</h3>
                    <div class="metric">${overview.total_stock || 0}</div>
                    <small>единиц товара</small>
                </div>
                <div class="card">
                    <h3>🔒 Зарезервировано</h3>
                    <div class="metric warning">${overview.total_reserved || 0}</div>
                    <small>единиц в резерве</small>
                </div>
                <div class="card">
                    <h3>🏪 Складов</h3>
                    <div class="metric">${overview.total_warehouses || 0}</div>
                    <small>активных складов</small>
                </div>
                <div class="card">
                    <h3>📈 Средний остаток</h3>
                    <div class="metric">${Math.round(overview.avg_stock || 0)}</div>
                    <small>единиц на товар</small>
                </div>
            `;
            document.getElementById('overview-cards').innerHTML = html;
        }

        // Загрузка товаров
        async function loadProducts(search = '', warehouse = '', limit = 20) {
            try {
                let url = `${API_BASE}?action=products&limit=${limit}`;
                if (search) url += `&search=${encodeURIComponent(search)}`;
                if (warehouse) url += `&warehouse=${encodeURIComponent(warehouse)}`;

                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    displayProducts(data.data.products);
                } else {
                    showError('products-table', data.error);
                }
            } catch (error) {
                showError('products-table', 'Ошибка загрузки товаров: ' + error.message);
            }
        }

        // Отображение товаров
        function displayProducts(products) {
            if (!products || products.length === 0) {
                document.getElementById('products-table').innerHTML = 
                    '<tr><td colspan="6" class="loading">Товары не найдены</td></tr>';
                return;
            }

            const html = products.map(product => {
                const status = getStockStatus(product.total_stock);
                return `
                    <tr>
                        <td>${product.sku}</td>
                        <td>${product.display_name || 'Без названия'}</td>
                        <td>${product.warehouses ? product.warehouses.map(w => w.warehouse_name).join(', ') : 'Не указан'}</td>
                        <td>${product.total_stock || 0}</td>
                        <td>${product.total_reserved || 0}</td>
                        <td><span class="status-badge ${status.class}">${status.text}</span></td>
                    </tr>
                `;
            }).join('');
            
            document.getElementById('products-table').innerHTML = html;
        }

        // Загрузка критических остатков
        async function loadCriticalItems() {
            const threshold = document.getElementById('critical-threshold').value || 5;
            
            try {
                const response = await fetch(`${API_BASE}?action=critical&threshold=${threshold}`);
                const data = await response.json();
                
                if (data.success) {
                    displayCriticalItems(data.data.critical_items);
                } else {
                    showError('critical-table', data.error);
                }
            } catch (error) {
                showError('critical-table', 'Ошибка загрузки критических остатков: ' + error.message);
            }
        }

        // Отображение критических остатков
        function displayCriticalItems(items) {
            if (!items || items.length === 0) {
                document.getElementById('critical-table').innerHTML = 
                    '<tr><td colspan="6" class="loading">Критические остатки не найдены</td></tr>';
                return;
            }

            const html = items.map(item => `
                <tr>
                    <td>${item.sku}</td>
                    <td>${item.display_name || 'Без названия'}</td>
                    <td>${item.warehouse_name || 'Не указан'}</td>
                    <td>${item.current_stock || 0}</td>
                    <td>${item.reserved_stock || 0}</td>
                    <td><span class="status-badge status-critical">${item.urgency_level || 'Критично'}</span></td>
                </tr>
            `).join('');
            
            document.getElementById('critical-table').innerHTML = html;
        }

        // Загрузка аналитики
        async function loadAnalytics() {
            try {
                const response = await fetch(`${API_BASE}?action=marketing`);
                const data = await response.json();
                
                if (data.success) {
                    displayAnalytics(data.data);
                } else {
                    showError('analytics-cards', data.error);
                }
            } catch (error) {
                showError('analytics-cards', 'Ошибка загрузки аналитики: ' + error.message);
            }
        }

        // Отображение аналитики
        function displayAnalytics(data) {
            const stats = data.overall_stats;
            const html = `
                <div class="card">
                    <h3>🎯 Уникальных товаров</h3>
                    <div class="metric">${stats.total_unique_products || 0}</div>
                    <small>различных позиций</small>
                </div>
                <div class="card">
                    <h3>📊 Общий запас</h3>
                    <div class="metric success">${stats.total_inventory_stock || 0}</div>
                    <small>единиц товара</small>
                </div>
                <div class="card">
                    <h3>📈 Средний остаток</h3>
                    <div class="metric">${Math.round(stats.avg_stock_per_product || 0)}</div>
                    <small>на товар</small>
                </div>
                <div class="card">
                    <h3>🟢 Высокие остатки</h3>
                    <div class="metric success">${stats.well_stocked_products || 0}</div>
                    <small>товаров (>50)</small>
                </div>
                <div class="card">
                    <h3>🟡 Средние остатки</h3>
                    <div class="metric warning">${stats.medium_stocked_products || 0}</div>
                    <small>товаров (10-50)</small>
                </div>
                <div class="card">
                    <h3>🔴 Низкие остатки</h3>
                    <div class="metric danger">${stats.low_stocked_products || 0}</div>
                    <small>товаров (<10)</small>
                </div>
            `;
            document.getElementById('analytics-cards').innerHTML = html;

            // Отображение топ товаров
            if (data.top_products) {
                displayTopProducts(data.top_products);
            }
        }

        // Отображение топ товаров
        function displayTopProducts(products) {
            const html = products.map(product => `
                <tr>
                    <td>${product.sku}</td>
                    <td>${product.product_name || 'Без названия'}</td>
                    <td>${product.total_stock || 0}</td>
                    <td>${product.total_reserved || 0}</td>
                    <td>${product.warehouses_count || 0}</td>
                    <td><span class="status-badge ${getStockLevelClass(product.stock_level)}">${product.stock_level}</span></td>
                </tr>
            `).join('');
            
            document.getElementById('top-products-table').innerHTML = html;
        }

        // Загрузка складов для фильтра
        async function loadWarehouses() {
            try {
                const response = await fetch(`${API_BASE}?action=stats`);
                const data = await response.json();
                
                if (data.success && data.data.stock_types) {
                    const warehouses = [...new Set(data.data.stock_types.map(item => item.source))];
                    const select = document.getElementById('warehouse-filter');
                    select.innerHTML = '<option value="">Все склады</option>' + 
                        warehouses.map(w => `<option value="${w}">${w}</option>`).join('');
                }
            } catch (error) {
                console.error('Ошибка загрузки складов:', error);
            }
        }

        // Поиск товаров
        function searchProducts() {
            const search = document.getElementById('product-search').value;
            const warehouse = document.getElementById('warehouse-filter').value;
            const limit = document.getElementById('product-limit').value;
            loadProducts(search, warehouse, limit);
        }

        // Очистка поиска
        function clearSearch() {
            document.getElementById('product-search').value = '';
            document.getElementById('warehouse-filter').value = '';
            document.getElementById('product-limit').value = '20';
            loadProducts();
        }

        // Обновление текущего таба
        function refreshCurrentTab() {
            loadTabData(currentTab);
        }

        // Получение статуса остатка
        function getStockStatus(stock) {
            if (stock <= 5) return { class: 'status-critical', text: 'Критично' };
            if (stock <= 20) return { class: 'status-low', text: 'Низкий' };
            return { class: 'status-good', text: 'Хорошо' };
        }

        // Получение класса для уровня запасов
        function getStockLevelClass(level) {
            if (level === 'Высокие остатки') return 'status-good';
            if (level === 'Средние остатки') return 'status-low';
            return 'status-critical';
        }

        // Показ ошибки
        function showError(containerId, message) {
            document.getElementById(containerId).innerHTML = 
                `<div class="error">Ошибка: ${message}</div>`;
        }

        // Обработчик Enter в поиске
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('product-search').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchProducts();
                }
            });

            // Загрузка начальных данных
            loadTabData('overview');
        });

        // Автообновление каждые 5 минут
        setInterval(refreshCurrentTab, 300000);
    </script>
</body>
</html>