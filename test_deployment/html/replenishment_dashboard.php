<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Система Рекомендаций по Пополнению Склада</title>
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
        
        .header .subtitle {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .controls-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
        }
        
        .control-group label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .control-group input,
        .control-group select {
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .control-group input:focus,
        .control-group select:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
        
        .recommendations-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .recommendations-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .recommendations-header h3 {
            color: #2c3e50;
            font-size: 1.5em;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        
        .recommendations-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .recommendations-table th {
            background: #f8f9fa;
            padding: 15px 10px;
            text-align: left;
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 2px solid #e9ecef;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        
        .recommendations-table th:hover {
            background: #e9ecef;
        }
        
        .recommendations-table th.sortable::after {
            content: '↕️';
            position: absolute;
            right: 5px;
            opacity: 0.5;
        }
        
        .recommendations-table th.sort-asc::after {
            content: '↑';
            opacity: 1;
        }
        
        .recommendations-table th.sort-desc::after {
            content: '↓';
            opacity: 1;
        }
        
        .recommendations-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .recommendations-table tr:hover {
            background: #f8f9fa;
        }
        
        .stock-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        
        .stock-status.critical { background: #e74c3c; }
        .stock-status.low { background: #f39c12; }
        .stock-status.normal { background: #27ae60; }
        .stock-status.excess { background: #3498db; }
        
        .recommendation-value {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .recommendation-value.positive { color: #e74c3c; }
        .recommendation-value.zero { color: #6c757d; }
        
        .loading {
            text-align: center;
            color: #666;
            font-size: 18px;
            padding: 50px;
        }
        
        .no-data {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 40px;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            margin-bottom: 20px;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #c3e6cb;
            margin-bottom: 20px;
        }
        
        .config-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
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
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            border-radius: 4px;
        }
        
        .pagination button:hover {
            background: #f8f9fa;
        }
        
        .pagination button.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .controls-grid,
            .config-grid,
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .recommendations-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .table-container {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Система Рекомендаций по Пополнению Склада</h1>
            <p class="subtitle">Автоматический расчет оптимальных объемов отгрузки на основе ADS и текущих остатков</p>
        </div>
        
        <!-- Панель управления -->
        <div class="controls-section">
            <div class="controls-grid">
                <div class="control-group">
                    <button class="btn btn-primary" onclick="calculateRecommendations()">
                        🔄 Рассчитать рекомендации
                    </button>
                </div>
                <div class="control-group">
                    <button class="btn btn-success" onclick="exportToExcel()">
                        📊 Экспорт в Excel
                    </button>
                </div>
                <div class="control-group">
                    <button class="btn btn-warning" onclick="showConfigPanel()">
                        ⚙️ Настройки
                    </button>
                </div>
                <div class="control-group">
                    <button class="btn btn-secondary" onclick="generateReport()">
                        📋 Еженедельный отчет
                    </button>
                </div>
            </div>
        </div>
        
        <!-- KPI секция -->
        <div id="kpi-section" class="kpi-grid" style="display: none;">
            <!-- KPI карточки будут добавлены динамически -->
        </div>
        
        <!-- Фильтры -->
        <div class="recommendations-section">
            <div class="filter-section">
                <div class="filter-grid">
                    <div class="control-group">
                        <label>Поиск по названию/SKU:</label>
                        <input type="text" id="search-input" placeholder="Введите название или SKU товара" onkeyup="filterRecommendations()">
                    </div>
                    <div class="control-group">
                        <label>Минимальный ADS:</label>
                        <input type="number" id="min-ads" placeholder="0.1" step="0.1" min="0" onchange="filterRecommendations()">
                    </div>
                    <div class="control-group">
                        <label>Статус остатка:</label>
                        <select id="stock-status-filter" onchange="filterRecommendations()">
                            <option value="">Все статусы</option>
                            <option value="critical">Критический</option>
                            <option value="low">Низкий</option>
                            <option value="normal">Нормальный</option>
                            <option value="excess">Избыток</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label>Только с рекомендациями:</label>
                        <select id="recommendations-filter" onchange="filterRecommendations()">
                            <option value="">Все товары</option>
                            <option value="positive">Только с рекомендациями > 0</option>
                            <option value="zero">Только с избытком</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Таблица рекомендаций -->
            <div class="recommendations-header">
                <h3>📋 Рекомендации по пополнению</h3>
                <div>
                    <span id="results-count" class="kpi-card" style="padding: 5px 10px; margin: 0; font-size: 14px;">
                        Загрузка...
                    </span>
                </div>
            </div>
            
            <div id="recommendations-content" class="loading">
                ⏳ Загрузка рекомендаций...
            </div>
        </div>
        
        <!-- Панель конфигурации (скрытая по умолчанию) -->
        <div id="config-panel" class="config-section" style="display: none;">
            <h3>⚙️ Настройки системы</h3>
            <div class="config-grid">
                <div class="control-group">
                    <label>Период пополнения (дни):</label>
                    <input type="number" id="replenishment-days" value="14" min="1" max="60">
                </div>
                <div class="control-group">
                    <label>Страховой запас (дни):</label>
                    <input type="number" id="safety-days" value="7" min="0" max="30">
                </div>
                <div class="control-group">
                    <label>Период анализа продаж (дни):</label>
                    <input type="number" id="analysis-days" value="30" min="7" max="90">
                </div>
                <div class="control-group">
                    <label>Минимальный ADS:</label>
                    <input type="number" id="min-ads-threshold" value="0.1" step="0.1" min="0">
                </div>
            </div>
            <div style="margin-top: 20px;">
                <button class="btn btn-success" onclick="saveConfiguration()">
                    💾 Сохранить настройки
                </button>
                <button class="btn btn-secondary" onclick="hideConfigPanel()">
                    ❌ Отмена
                </button>
            </div>
        </div>
        
        <button class="refresh-button" onclick="loadRecommendations()" title="Обновить данные">
            🔄
        </button>
    </div>    
 
   <script>
        // Глобальные переменные
        let currentRecommendations = [];
        let filteredRecommendations = [];
        let currentSort = { column: null, direction: 'asc' };
        let currentPage = 1;
        let itemsPerPage = 50;
        
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            loadConfiguration();
            loadRecommendations();
        });
        
        // ===================================================================
        // ОСНОВНЫЕ ФУНКЦИИ ЗАГРУЗКИ ДАННЫХ
        // ===================================================================
        
        async function loadRecommendations() {
            try {
                showLoadingIndicator();
                
                const response = await fetch('../api/replenishment.php?action=recommendations');
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    currentRecommendations = data.data || [];
                    
                    // Generate summary from data if not provided
                    const summary = data.summary || generateSummaryFromData(currentRecommendations);
                    updateKPISection(summary);
                    filterRecommendations();
                } else {
                    showError('Ошибка загрузки рекомендаций: ' + (data.error?.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error loading recommendations:', error);
                showError('Не удалось загрузить рекомендации. Проверьте подключение к серверу.');
            }
        }
        
        async function calculateRecommendations() {
            try {
                showLoadingIndicator('Расчет рекомендаций...');
                
                const response = await fetch('../api/replenishment.php?action=calculate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({})
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    showSuccess('Рекомендации успешно рассчитаны!');
                    await loadRecommendations();
                } else {
                    showError('Ошибка расчета рекомендаций: ' + (data.error?.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error calculating recommendations:', error);
                showError('Не удалось рассчитать рекомендации. Проверьте подключение к серверу.');
            }
        }
        
        async function loadConfiguration() {
            try {
                const response = await fetch('../api/replenishment.php?action=config');
                const data = await response.json();
                
                if (data.status === 'success') {
                    const config = data.data;
                    document.getElementById('replenishment-days').value = config.replenishment_days || 14;
                    document.getElementById('safety-days').value = config.safety_days || 7;
                    document.getElementById('analysis-days').value = config.analysis_days || 30;
                    document.getElementById('min-ads-threshold').value = config.min_ads_threshold || 0.1;
                }
            } catch (error) {
                console.error('Error loading configuration:', error);
            }
        }
        
        async function saveConfiguration() {
            try {
                const config = {
                    replenishment_days: parseInt(document.getElementById('replenishment-days').value),
                    safety_days: parseInt(document.getElementById('safety-days').value),
                    analysis_days: parseInt(document.getElementById('analysis-days').value),
                    min_ads_threshold: parseFloat(document.getElementById('min-ads-threshold').value)
                };
                
                const response = await fetch('../api/replenishment.php?action=config', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(config)
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    showSuccess('Настройки успешно сохранены!');
                    hideConfigPanel();
                } else {
                    showError('Ошибка сохранения настроек: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error saving configuration:', error);
                showError('Не удалось сохранить настройки. Проверьте подключение к серверу.');
            }
        }
        
        // ===================================================================
        // ФУНКЦИИ ОТОБРАЖЕНИЯ ДАННЫХ
        // ===================================================================
        
        function updateKPISection(summary) {
            const kpiSection = document.getElementById('kpi-section');
            
            const html = `
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #e74c3c;">${summary.critical_count || 0}</div>
                    <div class="kpi-label">Критический остаток</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #f39c12;">${summary.low_stock_count || 0}</div>
                    <div class="kpi-label">Низкий остаток</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #3498db;">${summary.recommendations_count || 0}</div>
                    <div class="kpi-label">Рекомендаций к отгрузке</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #27ae60;">${summary.total_products || 0}</div>
                    <div class="kpi-label">Всего товаров</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value">${formatCurrency(summary.total_recommended_value || 0)}</div>
                    <div class="kpi-label">Стоимость рекомендаций</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value">${formatDate(summary.last_calculation || new Date())}</div>
                    <div class="kpi-label">Последний расчет</div>
                </div>
            `;
            
            kpiSection.innerHTML = html;
            kpiSection.style.display = 'grid';
        }
        
        function renderRecommendationsTable(recommendations) {
            if (!recommendations || recommendations.length === 0) {
                return `
                    <div class="no-data">
                        <h3>📭 Нет данных для отображения</h3>
                        <p>Рекомендации не найдены. Попробуйте:</p>
                        <ul style="text-align: left; display: inline-block; margin-top: 10px;">
                            <li>Рассчитать новые рекомендации</li>
                            <li>Изменить фильтры поиска</li>
                            <li>Проверить настройки системы</li>
                        </ul>
                        <div style="margin-top: 20px;">
                            <button class="btn btn-primary" onclick="calculateRecommendations()">
                                🔄 Рассчитать рекомендации
                            </button>
                        </div>
                    </div>
                `;
            }
            
            // Пагинация
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedData = recommendations.slice(startIndex, endIndex);
            
            let html = `
                <div class="table-container">
                    <table class="recommendations-table">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="sortTable('product_name')">
                                    Товар ${getSortIcon('product_name')}
                                </th>
                                <th class="sortable" onclick="sortTable('sku')">
                                    SKU ${getSortIcon('sku')}
                                </th>
                                <th class="sortable" onclick="sortTable('ads')">
                                    ADS ${getSortIcon('ads')}
                                </th>
                                <th class="sortable" onclick="sortTable('current_stock')">
                                    Текущий остаток ${getSortIcon('current_stock')}
                                </th>
                                <th class="sortable" onclick="sortTable('target_stock')">
                                    Целевой запас ${getSortIcon('target_stock')}
                                </th>
                                <th class="sortable" onclick="sortTable('recommended_quantity')">
                                    Рекомендация ${getSortIcon('recommended_quantity')}
                                </th>
                                <th>Статус</th>
                                <th class="sortable" onclick="sortTable('calculation_date')">
                                    Дата расчета ${getSortIcon('calculation_date')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            paginatedData.forEach(item => {
                const stockStatus = getStockStatus(item.current_stock, item.ads);
                const recommendationClass = item.recommended_quantity > 0 ? 'positive' : 'zero';
                const recommendationText = item.recommended_quantity > 0 
                    ? item.recommended_quantity 
                    : '0 (запас избыточен)';
                
                html += `
                    <tr>
                        <td>
                            <strong>${item.product_name || 'Товар ' + item.sku}</strong>
                        </td>
                        <td>${item.sku || 'N/A'}</td>
                        <td>${parseFloat(item.ads || 0).toFixed(2)}</td>
                        <td>${item.current_stock || 0}</td>
                        <td>${item.target_stock || 0}</td>
                        <td>
                            <span class="recommendation-value ${recommendationClass}">
                                ${recommendationText}
                            </span>
                        </td>
                        <td>
                            <span class="stock-status ${stockStatus.class}">
                                ${stockStatus.text}
                            </span>
                        </td>
                        <td>${formatDate(item.calculation_date)}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            // Добавляем пагинацию
            if (recommendations.length > itemsPerPage) {
                html += renderPagination(recommendations.length);
            }
            
            return html;
        }
        
        function renderPagination(totalItems) {
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            if (totalPages <= 1) return '';
            
            let html = '<div class="pagination">';
            
            // Кнопка "Предыдущая"
            html += `<button ${currentPage === 1 ? 'disabled' : ''} onclick="changePage(${currentPage - 1})">‹ Пред</button>`;
            
            // Номера страниц
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    html += `<button class="${i === currentPage ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    html += '<span>...</span>';
                }
            }
            
            // Кнопка "Следующая"
            html += `<button ${currentPage === totalPages ? 'disabled' : ''} onclick="changePage(${currentPage + 1})">След ›</button>`;
            
            html += '</div>';
            
            return html;
        }
        
        // ===================================================================
        // ФУНКЦИИ ФИЛЬТРАЦИИ И СОРТИРОВКИ
        // ===================================================================
        
        function filterRecommendations() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            const minAds = parseFloat(document.getElementById('min-ads').value) || 0;
            const statusFilter = document.getElementById('stock-status-filter').value;
            const recommendationsFilter = document.getElementById('recommendations-filter').value;
            
            filteredRecommendations = currentRecommendations.filter(item => {
                // Поиск по названию/SKU
                const matchesSearch = !searchTerm || 
                    (item.product_name && item.product_name.toLowerCase().includes(searchTerm)) ||
                    (item.sku && item.sku.toLowerCase().includes(searchTerm));
                
                // Фильтр по минимальному ADS
                const matchesAds = (item.ads || 0) >= minAds;
                
                // Фильтр по статусу остатка
                let matchesStatus = true;
                if (statusFilter) {
                    const status = getStockStatus(item.current_stock, item.ads);
                    matchesStatus = status.class === statusFilter;
                }
                
                // Фильтр по рекомендациям
                let matchesRecommendations = true;
                if (recommendationsFilter === 'positive') {
                    matchesRecommendations = (item.recommended_quantity || 0) > 0;
                } else if (recommendationsFilter === 'zero') {
                    matchesRecommendations = (item.recommended_quantity || 0) <= 0;
                }
                
                return matchesSearch && matchesAds && matchesStatus && matchesRecommendations;
            });
            
            // Применяем текущую сортировку
            if (currentSort.column) {
                sortRecommendations(currentSort.column, currentSort.direction);
            }
            
            // Сбрасываем на первую страницу
            currentPage = 1;
            
            updateRecommendationsDisplay();
        }
        
        function sortTable(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }
            
            sortRecommendations(column, currentSort.direction);
            updateRecommendationsDisplay();
        }
        
        function sortRecommendations(column, direction) {
            filteredRecommendations.sort((a, b) => {
                let aVal = a[column];
                let bVal = b[column];
                
                // Обработка разных типов данных
                if (column === 'ads' || column === 'current_stock' || column === 'target_stock' || column === 'recommended_quantity') {
                    aVal = parseFloat(aVal) || 0;
                    bVal = parseFloat(bVal) || 0;
                } else if (column === 'calculation_date') {
                    aVal = new Date(aVal);
                    bVal = new Date(bVal);
                } else {
                    aVal = (aVal || '').toString().toLowerCase();
                    bVal = (bVal || '').toString().toLowerCase();
                }
                
                if (aVal < bVal) return direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return direction === 'asc' ? 1 : -1;
                return 0;
            });
        }
        
        function getSortIcon(column) {
            if (currentSort.column !== column) return '';
            return currentSort.direction === 'asc' ? '↑' : '↓';
        }
        
        function changePage(page) {
            currentPage = page;
            updateRecommendationsDisplay();
        }
        
        function updateRecommendationsDisplay() {
            const content = document.getElementById('recommendations-content');
            const resultsCount = document.getElementById('results-count');
            
            content.innerHTML = renderRecommendationsTable(filteredRecommendations);
            resultsCount.textContent = `Показано: ${filteredRecommendations.length} из ${currentRecommendations.length}`;
        }
        
        // ===================================================================
        // ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
        // ===================================================================
        
        function getStockStatus(currentStock, ads) {
            const stock = parseInt(currentStock) || 0;
            const dailySales = parseFloat(ads) || 0;
            
            if (stock <= 5) {
                return { class: 'critical', text: 'Критический' };
            } else if (stock <= 20) {
                return { class: 'low', text: 'Низкий' };
            } else if (stock > 100) {
                return { class: 'excess', text: 'Избыток' };
            } else {
                return { class: 'normal', text: 'Нормальный' };
            }
        }
        
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('ru-RU');
        }
        
        function formatCurrency(amount) {
            const num = parseFloat(amount) || 0;
            if (num > 1000000) {
                return (num / 1000000).toFixed(1) + 'M ₽';
            } else if (num > 1000) {
                return (num / 1000).toFixed(0) + 'K ₽';
            } else {
                return num.toFixed(0) + ' ₽';
            }
        }
        
        function generateSummaryFromData(recommendations) {
            if (!recommendations || recommendations.length === 0) {
                return {
                    critical_count: 0,
                    low_stock_count: 0,
                    recommendations_count: 0,
                    total_products: 0,
                    total_recommended_value: 0,
                    last_calculation: new Date()
                };
            }
            
            let criticalCount = 0;
            let lowStockCount = 0;
            let recommendationsCount = 0;
            let totalRecommendedValue = 0;
            
            recommendations.forEach(item => {
                const stock = parseInt(item.current_stock) || 0;
                const recommendedQty = parseInt(item.recommended_quantity) || 0;
                
                if (stock <= 5) {
                    criticalCount++;
                } else if (stock <= 20) {
                    lowStockCount++;
                }
                
                if (recommendedQty > 0) {
                    recommendationsCount++;
                    // Estimate value (assuming average price of 1000 rubles per item)
                    totalRecommendedValue += recommendedQty * 1000;
                }
            });
            
            return {
                critical_count: criticalCount,
                low_stock_count: lowStockCount,
                recommendations_count: recommendationsCount,
                total_products: recommendations.length,
                total_recommended_value: totalRecommendedValue,
                last_calculation: new Date()
            };
        }
        
        function showLoadingIndicator(message = 'Загрузка данных...') {
            const content = document.getElementById('recommendations-content');
            content.innerHTML = `
                <div class="loading">
                    <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="margin-top: 20px;">${message}</p>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
        }
        
        function showError(message) {
            const content = document.getElementById('recommendations-content');
            content.innerHTML = `
                <div class="error-message">
                    <strong>❌ Ошибка:</strong> ${message}
                    <div style="margin-top: 10px;">
                        <button class="btn btn-primary" onclick="loadRecommendations()">
                            🔄 Попробовать снова
                        </button>
                    </div>
                </div>
            `;
        }
        
        function showSuccess(message) {
            // Создаем временное уведомление
            const notification = document.createElement('div');
            notification.className = 'success-message';
            notification.innerHTML = `<strong>✅ Успех:</strong> ${message}`;
            notification.style.cssText = `
                position: fixed; top: 20px; right: 20px; z-index: 1000; 
                max-width: 400px; box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            `;
            
            document.body.appendChild(notification);
            
            // Автоматически удаляем через 3 секунды
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 3000);
        }
        
        // ===================================================================
        // ФУНКЦИИ ПАНЕЛИ УПРАВЛЕНИЯ
        // ===================================================================
        
        function showConfigPanel() {
            document.getElementById('config-panel').style.display = 'block';
        }
        
        function hideConfigPanel() {
            document.getElementById('config-panel').style.display = 'none';
        }
        
        async function generateReport() {
            try {
                showLoadingIndicator('Генерация отчета...');
                
                const response = await fetch('../api/replenishment.php?action=report');
                const data = await response.json();
                
                if (data.status === 'success') {
                    showSuccess('Еженедельный отчет успешно сгенерирован!');
                    await loadRecommendations();
                } else {
                    showError('Ошибка генерации отчета: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error generating report:', error);
                showError('Не удалось сгенерировать отчет. Проверьте подключение к серверу.');
            }
        }
        
        function exportToExcel() {
            if (!filteredRecommendations || filteredRecommendations.length === 0) {
                showError('Нет данных для экспорта');
                return;
            }
            
            // Подготавливаем данные для экспорта
            const headers = [
                'Товар', 'SKU', 'ADS', 'Текущий остаток', 
                'Целевой запас', 'Рекомендация к отгрузке', 'Статус', 'Дата расчета'
            ];
            
            const rows = filteredRecommendations.map(item => [
                item.product_name || 'Товар ' + item.sku,
                item.sku || 'N/A',
                parseFloat(item.ads || 0).toFixed(2),
                item.current_stock || 0,
                item.target_stock || 0,
                item.recommended_quantity > 0 ? item.recommended_quantity : '0 (запас избыточен)',
                getStockStatus(item.current_stock, item.ads).text,
                formatDate(item.calculation_date)
            ]);
            
            // Создаем CSV
            const csvContent = [headers, ...rows]
                .map(row => row.map(field => `"${field}"`).join(','))
                .join('\\n');
            
            // Скачиваем файл
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `replenishment_recommendations_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showSuccess('Данные экспортированы в CSV файл');
        }
    </script>
</body>
</html>