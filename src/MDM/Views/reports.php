<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- CSS Files -->
    <?php foreach ($cssFiles as $cssFile): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($cssFile) ?>">
    <?php endforeach; ?>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="/mdm/dashboard">
                <i class="fas fa-database me-2"></i>
                MDM System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/mdm/dashboard">
                            <i class="fas fa-tachometer-alt me-1"></i>
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/mdm/verification">
                            <i class="fas fa-check-circle me-1"></i>
                            Верификация
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/mdm/products">
                            <i class="fas fa-box me-1"></i>
                            Товары
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="/mdm/reports">
                            <i class="fas fa-chart-bar me-1"></i>
                            Отчеты
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4">
        <!-- Report Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h4><?= number_format($reportSummary['coverage']['coverage_percentage'], 1) ?>%</h4>
                        <p class="mb-0">Покрытие мастер-данными</p>
                        <small><?= number_format($reportSummary['coverage']['mapped_skus']) ?> из <?= number_format($reportSummary['coverage']['total_skus']) ?> SKU</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h4><?= number_format($reportSummary['quality']['completeness']['overall'], 1) ?>%</h4>
                        <p class="mb-0">Полнота данных</p>
                        <small>Общий показатель качества</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body text-center">
                        <h4><?= number_format($reportSummary['problematic']['unknown_brand']) ?></h4>
                        <p class="mb-0">Неизвестный бренд</p>
                        <small>Товаров требуют внимания</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h4><?= number_format($reportSummary['problematic']['no_category']) ?></h4>
                        <p class="mb-0">Без категории</p>
                        <small>Товаров без категории</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Types -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-file-alt me-2"></i>
                            Доступные отчеты
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 col-lg-3 mb-3">
                                <div class="report-card" data-report="coverage">
                                    <div class="report-icon">
                                        <i class="fas fa-percentage fa-2x text-primary"></i>
                                    </div>
                                    <h6>Отчет о покрытии</h6>
                                    <p class="text-muted">Анализ покрытия товаров мастер-данными по источникам</p>
                                    <button class="btn btn-primary btn-sm" onclick="generateReport('coverage')">
                                        <i class="fas fa-play me-1"></i>
                                        Сгенерировать
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-6 col-lg-3 mb-3">
                                <div class="report-card" data-report="incomplete">
                                    <div class="report-icon">
                                        <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                                    </div>
                                    <h6>Неполные данные</h6>
                                    <p class="text-muted">Товары с отсутствующими атрибутами</p>
                                    <button class="btn btn-warning btn-sm" onclick="generateReport('incomplete')">
                                        <i class="fas fa-play me-1"></i>
                                        Сгенерировать
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-6 col-lg-3 mb-3">
                                <div class="report-card" data-report="problematic">
                                    <div class="report-icon">
                                        <i class="fas fa-bug fa-2x text-danger"></i>
                                    </div>
                                    <h6>Проблемные товары</h6>
                                    <p class="text-muted">Товары с "Неизвестный бренд" или "Без категории"</p>
                                    <button class="btn btn-danger btn-sm" onclick="generateReport('problematic')">
                                        <i class="fas fa-play me-1"></i>
                                        Сгенерировать
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-6 col-lg-3 mb-3">
                                <div class="report-card" data-report="source_analysis">
                                    <div class="report-icon">
                                        <i class="fas fa-chart-line fa-2x text-success"></i>
                                    </div>
                                    <h6>Анализ источников</h6>
                                    <p class="text-muted">Статистика по источникам данных</p>
                                    <button class="btn btn-success btn-sm" onclick="generateReport('source_analysis')">
                                        <i class="fas fa-play me-1"></i>
                                        Сгенерировать
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Results -->
        <div class="row">
            <div class="col-12">
                <div class="card" id="report-results" style="display: none;">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0" id="report-title">
                            <i class="fas fa-chart-bar me-2"></i>
                            Результаты отчета
                        </h5>
                        <div>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('csv')">
                                <i class="fas fa-download me-1"></i>
                                CSV
                            </button>
                            <button class="btn btn-outline-success btn-sm" onclick="exportReport('json')">
                                <i class="fas fa-download me-1"></i>
                                JSON
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="report-content">
                        <!-- Report content will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="d-none">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Загрузка...</span>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Reports Data -->
    <script>
        window.reportSummary = <?= json_encode($reportSummary) ?>;
        let currentReportType = null;
        let currentReportData = null;

        // Generate report function
        async function generateReport(reportType) {
            try {
                currentReportType = reportType;
                showLoading();
                
                const response = await fetch(`/mdm/reports/${reportType}`);
                const result = await response.json();
                
                if (result.success) {
                    currentReportData = result.data;
                    displayReport(reportType, result.data);
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                console.error('Error generating report:', error);
                alert('Ошибка генерации отчета: ' + error.message);
            } finally {
                hideLoading();
            }
        }

        // Display report function
        function displayReport(reportType, data) {
            const reportResults = document.getElementById('report-results');
            const reportTitle = document.getElementById('report-title');
            const reportContent = document.getElementById('report-content');
            
            // Update title
            const titles = {
                'coverage': 'Отчет о покрытии мастер-данными',
                'incomplete': 'Отчет о неполных данных',
                'problematic': 'Отчет о проблемных товарах',
                'source_analysis': 'Анализ источников данных'
            };
            
            reportTitle.innerHTML = `<i class="fas fa-chart-bar me-2"></i>${titles[reportType]}`;
            
            // Generate content based on report type
            let content = '';
            switch (reportType) {
                case 'coverage':
                    content = generateCoverageContent(data);
                    break;
                case 'incomplete':
                    content = generateIncompleteContent(data);
                    break;
                case 'problematic':
                    content = generateProblematicContent(data);
                    break;
                case 'source_analysis':
                    content = generateSourceAnalysisContent(data);
                    break;
            }
            
            reportContent.innerHTML = content;
            reportResults.style.display = 'block';
            
            // Scroll to results
            reportResults.scrollIntoView({ behavior: 'smooth' });
        }

        // Generate coverage report content
        function generateCoverageContent(data) {
            let html = '<div class="row mb-4">';
            html += '<div class="col-md-6">';
            html += '<h6>Покрытие по источникам</h6>';
            html += '<div class="table-responsive">';
            html += '<table class="table table-striped">';
            html += '<thead><tr><th>Источник</th><th>Всего SKU</th><th>Сопоставлено</th><th>Покрытие</th></tr></thead>';
            html += '<tbody>';
            
            data.by_source.forEach(source => {
                html += `<tr>
                    <td>${source.source}</td>
                    <td>${source.total_skus}</td>
                    <td>${source.mapped_skus}</td>
                    <td><span class="badge bg-${source.coverage_percentage > 80 ? 'success' : source.coverage_percentage > 50 ? 'warning' : 'danger'}">${source.coverage_percentage}%</span></td>
                </tr>`;
            });
            
            html += '</tbody></table></div></div>';
            html += '<div class="col-md-6">';
            html += '<h6>Общая статистика</h6>';
            html += `<p><strong>Всего сопоставлено SKU:</strong> ${data.overall.mapped_skus}</p>`;
            html += `<p><strong>Источников с сопоставлениями:</strong> ${data.overall.sources_with_mappings}</p>`;
            html += `<p><strong>Мастер-товаров с сопоставлениями:</strong> ${data.overall.master_products_with_mappings}</p>`;
            html += '</div></div>';
            
            return html;
        }

        // Generate incomplete data content
        function generateIncompleteContent(data) {
            let html = '<div class="row mb-4">';
            html += '<div class="col-md-12">';
            html += '<h6>Сводка по неполным данным</h6>';
            html += `<p><strong>Товаров без бренда:</strong> ${data.summary.missing_brand_count}</p>`;
            html += `<p><strong>Товаров без категории:</strong> ${data.summary.missing_category_count}</p>`;
            html += `<p><strong>Товаров без описания:</strong> ${data.summary.missing_description_count}</p>`;
            html += '</div></div>';
            
            if (data.products.length > 0) {
                html += '<div class="table-responsive">';
                html += '<table class="table table-striped">';
                html += '<thead><tr><th>Master ID</th><th>Название</th><th>Проблемы</th><th>Обновлен</th></tr></thead>';
                html += '<tbody>';
                
                data.products.slice(0, 20).forEach(product => {
                    const issues = [];
                    if (product.missing_brand) issues.push('Бренд');
                    if (product.missing_category) issues.push('Категория');
                    if (product.missing_description) issues.push('Описание');
                    
                    html += `<tr>
                        <td>${product.master_id}</td>
                        <td>${product.canonical_name}</td>
                        <td><span class="badge bg-warning">${issues.join(', ')}</span></td>
                        <td>${new Date(product.updated_at).toLocaleDateString('ru-RU')}</td>
                    </tr>`;
                });
                
                html += '</tbody></table></div>';
                
                if (data.products.length > 20) {
                    html += `<p class="text-muted">Показано 20 из ${data.products.length} товаров. Экспортируйте отчет для получения полных данных.</p>`;
                }
            }
            
            return html;
        }

        // Generate problematic products content
        function generateProblematicContent(data) {
            let html = '<div class="table-responsive">';
            html += '<table class="table table-striped">';
            html += '<thead><tr><th>Master ID</th><th>Название</th><th>Бренд</th><th>Категория</th><th>Сопоставлений</th></tr></thead>';
            html += '<tbody>';
            
            data.products.slice(0, 20).forEach(product => {
                html += `<tr>
                    <td>${product.master_id}</td>
                    <td>${product.canonical_name}</td>
                    <td>${product.canonical_brand || '<span class="text-muted">Не указан</span>'}</td>
                    <td>${product.canonical_category || '<span class="text-muted">Не указана</span>'}</td>
                    <td><span class="badge bg-info">${product.mapping_count}</span></td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            
            if (data.products.length > 20) {
                html += `<p class="text-muted">Показано 20 из ${data.products.length} товаров. Экспортируйте отчет для получения полных данных.</p>`;
            }
            
            return html;
        }

        // Generate source analysis content
        function generateSourceAnalysisContent(data) {
            let html = '<div class="table-responsive">';
            html += '<table class="table table-striped">';
            html += '<thead><tr><th>Источник</th><th>Сопоставлений</th><th>Уникальных SKU</th><th>Связанных мастер-товаров</th><th>Средняя точность</th></tr></thead>';
            html += '<tbody>';
            
            data.source_analysis.forEach(source => {
                html += `<tr>
                    <td>${source.source}</td>
                    <td>${source.total_mappings}</td>
                    <td>${source.unique_skus}</td>
                    <td>${source.linked_masters}</td>
                    <td><span class="badge bg-${source.avg_confidence > 0.8 ? 'success' : source.avg_confidence > 0.6 ? 'warning' : 'danger'}">${(source.avg_confidence * 100).toFixed(1)}%</span></td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            
            return html;
        }

        // Export report function
        function exportReport(format) {
            if (!currentReportType) {
                alert('Сначала сгенерируйте отчет');
                return;
            }
            
            const url = `/mdm/reports/export?report_type=${currentReportType}&format=${format}`;
            window.open(url, '_blank');
        }

        // Utility functions
        function showLoading() {
            document.getElementById('loading-overlay').classList.remove('d-none');
        }

        function hideLoading() {
            document.getElementById('loading-overlay').classList.add('d-none');
        }
    </script>
    
    <!-- JS Files -->
    <?php foreach ($jsFiles as $jsFile): ?>
        <script src="<?= htmlspecialchars($jsFile) ?>"></script>
    <?php endforeach; ?>

    <style>
        .report-card {
            text-align: center;
            padding: 1.5rem;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            height: 100%;
            transition: all 0.3s ease;
        }
        
        .report-card:hover {
            border-color: #adb5bd;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .report-icon {
            margin-bottom: 1rem;
        }
        
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
    </style>
</body>
</html>