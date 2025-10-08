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
                        <a class="nav-link active" href="/mdm/verification">
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
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4">
        <!-- Statistics Row -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-primary"><?= number_format($statistics['total_pending']) ?></h5>
                        <small class="text-muted">Всего ожидают</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-success"><?= number_format($statistics['high_confidence']) ?></h5>
                        <small class="text-muted">Высокая точность</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-warning"><?= number_format($statistics['medium_confidence']) ?></h5>
                        <small class="text-muted">Средняя точность</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-danger"><?= number_format($statistics['low_confidence']) ?></h5>
                        <small class="text-muted">Низкая точность</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-secondary"><?= number_format($statistics['no_matches']) ?></h5>
                        <small class="text-muted">Без совпадений</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <button class="btn btn-success btn-sm w-100" id="bulk-approve-btn" disabled>
                            <i class="fas fa-check-double me-1"></i>
                            Массовое одобрение
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters and Controls -->
        <div class="row mb-3">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <label for="filter-select" class="form-label">Фильтр:</label>
                                <select class="form-select" id="filter-select">
                                    <option value="all">Все товары</option>
                                    <option value="high_confidence">Высокая точность (>90%)</option>
                                    <option value="medium_confidence">Средняя точность (70-90%)</option>
                                    <option value="low_confidence">Низкая точность (<70%)</option>
                                    <option value="no_matches">Без совпадений</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="per-page-select" class="form-label">На странице:</label>
                                <select class="form-select" id="per-page-select">
                                    <option value="10">10</option>
                                    <option value="20" selected>20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button class="btn btn-primary" id="refresh-btn">
                                        <i class="fas fa-sync-alt me-1"></i>
                                        Обновить
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Выбрано: <span id="selected-count">0</span></label>
                                <div>
                                    <button class="btn btn-outline-secondary btn-sm" id="select-all-btn">
                                        Выбрать все
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clear-selection-btn">
                                        Очистить
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="d-grid gap-2">
                            <button class="btn btn-success" id="approve-selected-btn" disabled>
                                <i class="fas fa-check me-1"></i>
                                Одобрить выбранные
                            </button>
                            <button class="btn btn-danger" id="reject-selected-btn" disabled>
                                <i class="fas fa-times me-1"></i>
                                Отклонить выбранные
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Verification Items -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>
                            Товары для верификации
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="verification-items-container">
                            <!-- Items will be loaded here -->
                        </div>
                        
                        <!-- Pagination -->
                        <div class="d-flex justify-content-between align-items-center p-3 border-top">
                            <div>
                                <small class="text-muted" id="pagination-info">Загрузка...</small>
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0" id="pagination-controls">
                                    <!-- Pagination will be generated here -->
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Comparison Modal -->
    <div class="modal fade" id="comparison-modal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-search me-2"></i>
                        Сравнение товаров
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="comparison-content">
                    <!-- Comparison content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button type="button" class="btn btn-success" id="approve-match-btn">
                        <i class="fas fa-check me-1"></i>
                        Одобрить совпадение
                    </button>
                    <button type="button" class="btn btn-warning" id="create-new-master-btn">
                        <i class="fas fa-plus me-1"></i>
                        Создать новый мастер-товар
                    </button>
                    <button type="button" class="btn btn-danger" id="reject-match-btn">
                        <i class="fas fa-times me-1"></i>
                        Отклонить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Master Product Modal -->
    <div class="modal fade" id="create-master-modal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>
                        Создать новый мастер-товар
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="create-master-form">
                        <div class="mb-3">
                            <label for="master-name" class="form-label">Каноническое название *</label>
                            <input type="text" class="form-control" id="master-name" required>
                        </div>
                        <div class="mb-3">
                            <label for="master-brand" class="form-label">Бренд</label>
                            <input type="text" class="form-control" id="master-brand">
                        </div>
                        <div class="mb-3">
                            <label for="master-category" class="form-label">Категория</label>
                            <input type="text" class="form-control" id="master-category">
                        </div>
                        <div class="mb-3">
                            <label for="master-description" class="form-label">Описание</label>
                            <textarea class="form-control" id="master-description" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="save-master-btn">
                        <i class="fas fa-save me-1"></i>
                        Создать и связать
                    </button>
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
    
    <!-- Verification Data -->
    <script>
        window.verificationData = {
            pendingItems: <?= json_encode($pendingItems) ?>,
            statistics: <?= json_encode($statistics) ?>
        };
    </script>
    
    <!-- JS Files -->
    <?php foreach ($jsFiles as $jsFile): ?>
        <script src="<?= htmlspecialchars($jsFile) ?>"></script>
    <?php endforeach; ?>
</body>
</html>