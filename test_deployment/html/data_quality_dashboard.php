<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчеты о качестве данных - MDM System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card-title {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .card-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .card-subtitle {
            font-size: 12px;
            color: #95a5a6;
        }
        
        .card.danger .card-value { color: #e74c3c; }
        .card.warning .card-value { color: #f39c12; }
        .card.success .card-value { color: #27ae60; }
        .card.info .card-value { color: #3498db; }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: #7f8c8d;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.3s;
        }
        
        .tab:hover {
            color: #2c3e50;
        }
        
        .tab.active {
            color: #3498db;
            border-bottom-color: #3498db;
        }
        
        .report-section {
            display: none;
        }
        
        .report-section.active {
            display: block;
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .report-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        thead {
            background: #34495e;
            color: white;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        th {
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-danger { background: #fee; color: #e74c3c; }
        .badge-warning { background: #fef5e7; color: #f39c12; }
        .badge-success { background: #eafaf1; color: #27ae60; }
        .badge-info { background: #ebf5fb; color: #3498db; }
        
        .priority-high { color: #e74c3c; font-weight: bold; }
        .priority-medium { color: #f39c12; }
        .priority-low { color: #95a5a6; }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            border-radius: 4px;
        }
        
        .pagination button:hover:not(:disabled) {
            background: #f8f9fa;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📊 Отчеты о качестве данных</h1>
            <p class="subtitle">Мониторинг и анализ проблем с данными товаров</p>
        </header>
        
        <div class="summary-cards" id="summaryCards">
            <div class="card info">
                <div class="card-title">Общее здоровье данных</div>
                <div class="card-value" id="healthScore">-</div>
                <div class="card-subtitle">Оценка качества</div>
            </div>
            
            <div class="card danger">
                <div class="card-title">Отсутствующие названия</div>
                <div class="card-value" id="missingNames">-</div>
                <div class="card-subtitle" id="missingNamesPercent">-</div>
            </div>
            
            <div class="card warning">
                <div class="card-title">Ошибки синхронизации</div>
                <div class="card-value" id="syncErrors">-</div>
                <div class="card-subtitle" id="syncErrorsPercent">-</div>
            </div>
            
            <div class="card success">
                <div class="card-title">Требуют проверки</div>
                <div class="card-value" id="manualReview">-</div>
                <div class="card-subtitle" id="manualReviewPercent">-</div>
            </div>
        </div>
        
        <div class="tabs">
            <button class="tab active" onclick="switchTab('missing_names')">Отсутствующие названия</button>
            <button class="tab" onclick="switchTab('sync_errors')">Ошибки синхронизации</button>
            <button class="tab" onclick="switchTab('manual_review')">Требуют проверки</button>
        </div>
        
        <!-- Missing Names Report -->
        <div id="missing_names" class="report-section active">
            <div class="report-header">
                <h2 class="report-title">Товары с отсутствующими названиями</h2>
                <div class="export-buttons">
                    <button class="btn btn-success" onclick="exportReport('missing_names', 'csv')">📥 Экспорт CSV</button>
                    <button class="btn btn-primary" onclick="exportReport('missing_names', 'json')">📥 Экспорт JSON</button>
                </div>
            </div>
            <div id="missingNamesTable"></div>
        </div>
        
        <!-- Sync Errors Report -->
        <div id="sync_errors" class="report-section">
            <div class="report-header">
                <h2 class="report-title">Товары с ошибками синхронизации</h2>
                <div class="export-buttons">
                    <button class="btn btn-success" onclick="exportReport('sync_errors', 'csv')">📥 Экспорт CSV</button>
                    <button class="btn btn-primary" onclick="exportReport('sync_errors', 'json')">📥 Экспорт JSON</button>
                </div>
            </div>
            <div id="syncErrorsTable"></div>
        </div>
        
        <!-- Manual Review Report -->
        <div id="manual_review" class="report-section">
            <div class="report-header">
                <h2 class="report-title">Товары, требующие ручной проверки</h2>
                <div class="export-buttons">
                    <button class="btn btn-success" onclick="exportReport('manual_review', 'csv')">📥 Экспорт CSV</button>
                    <button class="btn btn-primary" onclick="exportReport('manual_review', 'json')">📥 Экспорт JSON</button>
                </div>
            </div>
            <div id="manualReviewTable"></div>
        </div>
    </div>
    
    <script>
        let currentTab = 'missing_names';
        let currentPage = {
            missing_names: 0,
            sync_errors: 0,
            manual_review: 0
        };
        const pageSize = 50;
        
        // Load summary on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSummary();
            loadReport('missing_names');
        });
        
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            // Update sections
            document.querySelectorAll('.report-section').forEach(section => section.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            
            currentTab = tabName;
            
            // Load report if not already loaded
            loadReport(tabName);
        }
        
        async function loadSummary() {
            try {
                const response = await fetch('/api/data-quality-reports.php?action=summary');
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    document.getElementById('healthScore').textContent = data.health_score + '%';
                    document.getElementById('missingNames').textContent = data.missing_names;
                    document.getElementById('missingNamesPercent').textContent = data.missing_names_percent + '% от общего';
                    document.getElementById('syncErrors').textContent = data.sync_errors;
                    document.getElementById('syncErrorsPercent').textContent = data.sync_errors_percent + '% от общего';
                    document.getElementById('manualReview').textContent = data.manual_review;
                    document.getElementById('manualReviewPercent').textContent = data.manual_review_percent + '% от общего';
                }
            } catch (error) {
                console.error('Error loading summary:', error);
            }
        }
        
        async function loadReport(reportType, page = 0) {
            const tableId = reportType === 'missing_names' ? 'missingNamesTable' :
                           reportType === 'sync_errors' ? 'syncErrorsTable' : 'manualReviewTable';
            
            const tableContainer = document.getElementById(tableId);
            tableContainer.innerHTML = '<div class="loading"><div class="spinner"></div>Загрузка данных...</div>';
            
            try {
                const offset = page * pageSize;
                const response = await fetch(`/api/data-quality-reports.php?action=${reportType}&limit=${pageSize}&offset=${offset}`);
                const result = await response.json();
                
                if (result.success && result.data.products.length > 0) {
                    renderTable(result.data, reportType, tableId);
                } else {
                    tableContainer.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">✅</div>
                            <h3>Проблем не найдено</h3>
                            <p>Все товары в этой категории в порядке</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading report:', error);
                tableContainer.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">❌</div>
                        <h3>Ошибка загрузки</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        function renderTable(data, reportType, tableId) {
            const products = data.products;
            const total = data.total;
            const currentOffset = data.offset;
            
            let html = '<table><thead><tr>';
            
            // Table headers based on report type
            if (reportType === 'missing_names') {
                html += '<th>ID</th><th>Inventory ID</th><th>Ozon ID</th><th>Текущее название</th><th>Тип проблемы</th><th>Остаток</th><th>Склад</th><th>Статус</th>';
            } else if (reportType === 'sync_errors') {
                html += '<th>ID</th><th>Inventory ID</th><th>Ozon ID</th><th>Название</th><th>Тип ошибки</th><th>Последняя синхронизация</th><th>Часов назад</th><th>Статус</th>';
            } else {
                html += '<th>ID</th><th>Inventory ID</th><th>Ozon ID</th><th>Название</th><th>Причина проверки</th><th>Приоритет</th><th>Остаток</th><th>Статус</th>';
            }
            
            html += '</tr></thead><tbody>';
            
            products.forEach(product => {
                html += '<tr>';
                
                if (reportType === 'missing_names') {
                    html += `
                        <td>${product.id}</td>
                        <td>${product.inventory_product_id || '-'}</td>
                        <td>${product.ozon_product_id || '-'}</td>
                        <td>${product.dim_product_name || product.cached_name || '-'}</td>
                        <td><span class="badge badge-danger">${formatIssueType(product.issue_type)}</span></td>
                        <td>${product.quantity_present || 0}</td>
                        <td>${product.warehouse_name || '-'}</td>
                        <td><span class="badge badge-${getSyncStatusClass(product.sync_status)}">${product.sync_status}</span></td>
                    `;
                } else if (reportType === 'sync_errors') {
                    html += `
                        <td>${product.id}</td>
                        <td>${product.inventory_product_id || '-'}</td>
                        <td>${product.ozon_product_id || '-'}</td>
                        <td>${product.dim_product_name || product.cached_name || '-'}</td>
                        <td><span class="badge badge-warning">${formatErrorType(product.error_type)}</span></td>
                        <td>${product.last_api_sync || 'Никогда'}</td>
                        <td>${product.hours_since_sync || '-'}</td>
                        <td><span class="badge badge-${getSyncStatusClass(product.sync_status)}">${product.sync_status}</span></td>
                    `;
                } else {
                    html += `
                        <td>${product.id}</td>
                        <td>${product.inventory_product_id || '-'}</td>
                        <td>${product.ozon_product_id || '-'}</td>
                        <td>${product.dim_product_name || product.cached_name || '-'}</td>
                        <td><span class="badge badge-info">${formatReviewReason(product.review_reason)}</span></td>
                        <td><span class="priority-${product.priority}">${product.priority.toUpperCase()}</span></td>
                        <td>${product.quantity_present || 0}</td>
                        <td><span class="badge badge-${getSyncStatusClass(product.sync_status)}">${product.sync_status}</span></td>
                    `;
                }
                
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            
            // Add pagination
            const currentPageNum = Math.floor(currentOffset / pageSize);
            const totalPages = Math.ceil(total / pageSize);
            
            html += '<div class="pagination">';
            html += `<button onclick="loadReport('${reportType}', ${currentPageNum - 1})" ${currentPageNum === 0 ? 'disabled' : ''}>← Предыдущая</button>`;
            html += `<span>Страница ${currentPageNum + 1} из ${totalPages} (Всего: ${total})</span>`;
            html += `<button onclick="loadReport('${reportType}', ${currentPageNum + 1})" ${currentPageNum >= totalPages - 1 ? 'disabled' : ''}>Следующая →</button>`;
            html += '</div>';
            
            document.getElementById(tableId).innerHTML = html;
        }
        
        function formatIssueType(type) {
            const types = {
                'completely_missing': 'Полностью отсутствует',
                'placeholder_name': 'Заглушка',
                'needs_update': 'Требует обновления',
                'unknown_issue': 'Неизвестная проблема'
            };
            return types[type] || type;
        }
        
        function formatErrorType(type) {
            const types = {
                'sync_failed': 'Ошибка синхронизации',
                'never_synced': 'Никогда не синхронизировался',
                'stale_data': 'Устаревшие данные',
                'unknown': 'Неизвестная ошибка'
            };
            return types[type] || type;
        }
        
        function formatReviewReason(reason) {
            const reasons = {
                'missing_ozon_id': 'Отсутствует Ozon ID',
                'missing_inventory_id': 'Отсутствует Inventory ID',
                'no_name_data': 'Нет данных о названии',
                'repeated_failures': 'Повторяющиеся ошибки',
                'high_stock_no_name': 'Большой остаток без названия',
                'data_inconsistency': 'Несоответствие данных'
            };
            return reasons[reason] || reason;
        }
        
        function getSyncStatusClass(status) {
            const classes = {
                'synced': 'success',
                'pending': 'warning',
                'failed': 'danger'
            };
            return classes[status] || 'info';
        }
        
        async function exportReport(reportType, format) {
            try {
                const url = `/api/data-quality-reports.php?action=export&type=${reportType}&format=${format}`;
                
                if (format === 'csv') {
                    // Download CSV file
                    window.location.href = url;
                } else {
                    // Download JSON
                    const response = await fetch(url);
                    const result = await response.json();
                    
                    if (result.success) {
                        const dataStr = JSON.stringify(result.data, null, 2);
                        const dataBlob = new Blob([dataStr], {type: 'application/json'});
                        const url = URL.createObjectURL(dataBlob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = `data_quality_report_${reportType}_${new Date().toISOString().split('T')[0]}.json`;
                        link.click();
                    }
                }
            } catch (error) {
                console.error('Error exporting report:', error);
                alert('Ошибка при экспорте отчета: ' + error.message);
            }
        }
        
        // Auto-refresh summary every 30 seconds
        setInterval(loadSummary, 30000);
    </script>
</body>
</html>
