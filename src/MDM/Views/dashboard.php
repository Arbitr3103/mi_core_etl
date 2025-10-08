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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-database me-2"></i>
                MDM System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="/mdm/dashboard">
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
                        <a class="nav-link" href="/mdm/reports">
                            <i class="fas fa-chart-bar me-1"></i>
                            Отчеты
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="navbar-text">
                            <i class="fas fa-sync-alt me-1" id="sync-indicator"></i>
                            <span id="last-sync">Загрузка...</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4">
        <!-- System Health Alert -->
        <div id="system-alerts"></div>

        <!-- Key Metrics Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Мастер-товары</h6>
                                <h3 class="mb-0" id="total-master-products">
                                    <?= number_format($data['statistics']['total_master_products']) ?>
                                </h3>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-box fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Покрытие</h6>
                                <h3 class="mb-0" id="coverage-percentage">
                                    <?= number_format($data['statistics']['coverage_percentage'], 1) ?>%
                                </h3>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-percentage fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Ожидают проверки</h6>
                                <h3 class="mb-0" id="pending-verification">
                                    <?= number_format($data['pending_items']['pending_verification']) ?>
                                </h3>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Источники данных</h6>
                                <h3 class="mb-0" id="sources-count">
                                    <?= number_format($data['statistics']['sources_count']) ?>
                                </h3>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-database fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Quality Metrics Row -->
        <div class="row mb-4">
            <!-- Data Quality Chart -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie me-2"></i>
                            Качество данных
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="qualityChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity Chart -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line me-2"></i>
                            Активность за неделю
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="activityChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Metrics Row -->
        <div class="row mb-4">
            <!-- Quality Metrics Details -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clipboard-check me-2"></i>
                            Детальные метрики качества
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Полнота данных</h6>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?= $data['quality_metrics']['completeness']['overall'] ?>%">
                                        <?= number_format($data['quality_metrics']['completeness']['overall'], 1) ?>%
                                    </div>
                                </div>
                                
                                <h6>Точность</h6>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-info" role="progressbar" 
                                         style="width: <?= $data['quality_metrics']['accuracy']['overall'] ?>%">
                                        <?= number_format($data['quality_metrics']['accuracy']['overall'], 1) ?>%
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Согласованность</h6>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-warning" role="progressbar" 
                                         style="width: <?= $data['quality_metrics']['consistency']['overall'] ?>%">
                                        <?= number_format($data['quality_metrics']['consistency']['overall'], 1) ?>%
                                    </div>
                                </div>
                                
                                <h6>Актуальность</h6>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-primary" role="progressbar" 
                                         style="width: <?= $data['quality_metrics']['freshness']['overall'] ?>%">
                                        <?= number_format($data['quality_metrics']['freshness']['overall'], 1) ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Status -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-heartbeat me-2"></i>
                            Состояние системы
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>ETL процессы</span>
                                <span class="badge bg-<?= $data['system_health']['etl_status']['status'] === 'completed' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($data['system_health']['etl_status']['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>База данных</span>
                                <span class="badge bg-<?= $data['system_health']['database_status']['status'] === 'healthy' ? 'success' : 'danger' ?>">
                                    <?= ucfirst($data['system_health']['database_status']['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted">
                                Последняя синхронизация:<br>
                                <?= $data['system_health']['last_sync'] ? date('d.m.Y H:i', strtotime($data['system_health']['last_sync'])) : 'Неизвестно' ?>
                            </small>
                        </div>
                        
                        <button class="btn btn-outline-primary btn-sm w-100" onclick="refreshDashboard()">
                            <i class="fas fa-sync-alt me-1"></i>
                            Обновить данные
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions Row -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-bolt me-2"></i>
                            Быстрые действия
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <a href="/mdm/verification" class="btn btn-outline-warning w-100 mb-2">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Проверить товары
                                    <span class="badge bg-warning ms-2"><?= $data['pending_items']['pending_verification'] ?></span>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="/mdm/products/incomplete" class="btn btn-outline-info w-100 mb-2">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Неполные данные
                                    <span class="badge bg-info ms-2"><?= $data['pending_items']['incomplete_data'] ?></span>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="/mdm/reports/quality" class="btn btn-outline-success w-100 mb-2">
                                    <i class="fas fa-chart-bar me-1"></i>
                                    Отчет качества
                                </a>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-outline-primary w-100 mb-2" onclick="runETL()">
                                    <i class="fas fa-play me-1"></i>
                                    Запустить ETL
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Dashboard Data -->
    <script>
        window.dashboardData = <?= json_encode($data) ?>;
    </script>
    
    <!-- JS Files -->
    <?php foreach ($jsFiles as $jsFile): ?>
        <script src="<?= htmlspecialchars($jsFile) ?>"></script>
    <?php endforeach; ?>
</body>
</html>