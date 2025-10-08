<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аудит системы - MDM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/src/MDM/assets/css/dashboard.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include __DIR__ . '/../partials/navbar.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/../partials/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-shield-alt me-2"></i>Аудит системы</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="exportAudit('csv')">
                                <i class="fas fa-file-csv me-1"></i>Экспорт CSV
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="exportAudit('json')">
                                <i class="fas fa-file-code me-1"></i>Экспорт JSON
                            </button>
                        </div>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($_SESSION['success_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($_SESSION['error_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <!-- Date Filter -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-4">
                                        <label for="date_from" class="form-label">Дата с</label>
                                        <input type="date" class="form-control" id="date_from" name="date_from" 
                                               value="<?= htmlspecialchars($dateFrom) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="date_to" class="form-label">Дата по</label>
                                        <input type="date" class="form-control" id="date_to" name="date_to" 
                                               value="<?= htmlspecialchars($dateTo) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">&nbsp;</label>
                                        <div>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-filter me-1"></i>Применить фильтр
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-database me-2"></i>Операции с мастер-данными
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="masterProductsChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-link me-2"></i>Операции с сопоставлениями
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="skuMappingsChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Active Users -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-users me-2"></i>Наиболее активные пользователи
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($statistics['active_users'])): ?>
                                    <p class="text-muted">Нет данных об активности пользователей</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Пользователь</th>
                                                    <th>Полное имя</th>
                                                    <th>Количество операций</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($statistics['active_users'] as $user): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                                        <td><?= htmlspecialchars($user['full_name'] ?: '-') ?></td>
                                                        <td>
                                                            <span class="badge bg-primary"><?= $user['total_operations'] ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Audit Entries -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Последние изменения
                                </h5>
                                <div>
                                    <a href="/audit/master-products" class="btn btn-sm btn-outline-primary me-2">
                                        Мастер-данные
                                    </a>
                                    <a href="/audit/sku-mappings" class="btn btn-sm btn-outline-primary">
                                        Сопоставления
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentAudit)): ?>
                                    <p class="text-muted">Нет записей аудита</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Тип</th>
                                                    <th>ID</th>
                                                    <th>Операция</th>
                                                    <th>Пользователь</th>
                                                    <th>Дата</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentAudit as $entry): ?>
                                                    <tr>
                                                        <td>
                                                            <?php if (isset($entry['master_id'])): ?>
                                                                <span class="badge bg-info">Мастер-данные</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Сопоставление</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <code><?= htmlspecialchars($entry['master_id'] ?? $entry['external_sku'] ?? 'N/A') ?></code>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $operationClass = [
                                                                'INSERT' => 'success',
                                                                'UPDATE' => 'warning',
                                                                'DELETE' => 'danger'
                                                            ][$entry['operation']] ?? 'secondary';
                                                            ?>
                                                            <span class="badge bg-<?= $operationClass ?>"><?= $entry['operation'] ?></span>
                                                        </td>
                                                        <td><?= htmlspecialchars($entry['username'] ?: 'System') ?></td>
                                                        <td><?= date('d.m.Y H:i', strtotime($entry['created_at'])) ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-info" 
                                                                    onclick="showAuditDetails('<?= isset($entry['master_id']) ? 'master_product' : 'sku_mapping' ?>', '<?= $entry['id'] ?>')">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Audit Details Modal -->
    <div class="modal fade" id="auditDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Детали изменения</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="auditDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize charts
        const masterProductsData = <?= json_encode($statistics['master_products']) ?>;
        const skuMappingsData = <?= json_encode($statistics['sku_mappings']) ?>;
        
        // Master Products Chart
        const masterProductsCtx = document.getElementById('masterProductsChart').getContext('2d');
        new Chart(masterProductsCtx, {
            type: 'doughnut',
            data: {
                labels: masterProductsData.map(item => item.operation),
                datasets: [{
                    data: masterProductsData.map(item => item.count),
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        
        // SKU Mappings Chart
        const skuMappingsCtx = document.getElementById('skuMappingsChart').getContext('2d');
        new Chart(skuMappingsCtx, {
            type: 'doughnut',
            data: {
                labels: skuMappingsData.map(item => item.operation),
                datasets: [{
                    data: skuMappingsData.map(item => item.count),
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        
        // Export audit function
        function exportAudit(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('format', format);
            window.location.href = '/audit/export?' + params.toString();
        }
        
        // Show audit details
        function showAuditDetails(type, id) {
            fetch(`/audit/details?type=${type}&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const details = data.data;
                        let content = '<div class="row">';
                        
                        // Basic info
                        content += '<div class="col-md-6">';
                        content += '<h6>Основная информация</h6>';
                        content += `<p><strong>Операция:</strong> ${details.operation}</p>`;
                        content += `<p><strong>Пользователь:</strong> ${details.username || 'System'}</p>`;
                        content += `<p><strong>Дата:</strong> ${new Date(details.created_at).toLocaleString('ru-RU')}</p>`;
                        content += `<p><strong>IP адрес:</strong> ${details.ip_address || 'N/A'}</p>`;
                        content += '</div>';
                        
                        // Changes
                        content += '<div class="col-md-6">';
                        content += '<h6>Изменения</h6>';
                        if (details.changed_fields && details.changed_fields.length > 0) {
                            content += '<ul>';
                            details.changed_fields.forEach(field => {
                                content += `<li>${field}</li>`;
                            });
                            content += '</ul>';
                        } else {
                            content += '<p class="text-muted">Нет информации об изменениях</p>';
                        }
                        content += '</div>';
                        
                        content += '</div>';
                        
                        // Old and new values
                        if (details.old_values || details.new_values) {
                            content += '<hr>';
                            content += '<div class="row">';
                            
                            if (details.old_values) {
                                content += '<div class="col-md-6">';
                                content += '<h6>Старые значения</h6>';
                                content += '<pre class="bg-light p-2 rounded">' + JSON.stringify(details.old_values, null, 2) + '</pre>';
                                content += '</div>';
                            }
                            
                            if (details.new_values) {
                                content += '<div class="col-md-6">';
                                content += '<h6>Новые значения</h6>';
                                content += '<pre class="bg-light p-2 rounded">' + JSON.stringify(details.new_values, null, 2) + '</pre>';
                                content += '</div>';
                            }
                            
                            content += '</div>';
                        }
                        
                        document.getElementById('auditDetailsContent').innerHTML = content;
                        new bootstrap.Modal(document.getElementById('auditDetailsModal')).show();
                    } else {
                        alert('Ошибка загрузки деталей: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Произошла ошибка при загрузке деталей');
                });
        }
    </script>
</body>
</html>