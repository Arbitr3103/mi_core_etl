<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Резервное копирование - MDM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/src/MDM/assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/../partials/navbar.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/../partials/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-hdd me-2"></i>Резервное копирование</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="refreshStatus()">
                                <i class="fas fa-sync-alt me-1"></i>Обновить
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
                
                <!-- Backup Jobs -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-tasks me-2"></i>Задания резервного копирования
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($jobs)): ?>
                                    <p class="text-muted">Нет настроенных заданий резервного копирования</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Название</th>
                                                    <th>Тип</th>
                                                    <th>Расписание</th>
                                                    <th>Таблицы</th>
                                                    <th>Статус</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($jobs as $job): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($job['job_name']) ?></strong>
                                                            <br>
                                                            <small class="text-muted">
                                                                Хранить <?= $job['retention_days'] ?> дней
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $typeClass = [
                                                                'full' => 'primary',
                                                                'incremental' => 'warning',
                                                                'differential' => 'info'
                                                            ][$job['backup_type']] ?? 'secondary';
                                                            
                                                            $typeText = [
                                                                'full' => 'Полное',
                                                                'incremental' => 'Инкрементальное',
                                                                'differential' => 'Дифференциальное'
                                                            ][$job['backup_type']] ?? $job['backup_type'];
                                                            ?>
                                                            <span class="badge bg-<?= $typeClass ?>"><?= $typeText ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if ($job['schedule_cron']): ?>
                                                                <code><?= htmlspecialchars($job['schedule_cron']) ?></code>
                                                            <?php else: ?>
                                                                <span class="text-muted">Ручное</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-secondary">
                                                                <?= count($job['tables_to_backup']) ?> таблиц
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = [
                                                                'active' => 'success',
                                                                'inactive' => 'secondary',
                                                                'paused' => 'warning'
                                                            ][$job['status']] ?? 'secondary';
                                                            
                                                            $statusText = [
                                                                'active' => 'Активно',
                                                                'inactive' => 'Неактивно',
                                                                'paused' => 'Приостановлено'
                                                            ][$job['status']] ?? $job['status'];
                                                            ?>
                                                            <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <a href="/backup/job?id=<?= $job['id'] ?>" class="btn btn-outline-info">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                                <?php if ($job['status'] === 'active'): ?>
                                                                    <button class="btn btn-outline-primary" 
                                                                            onclick="executeBackup(<?= $job['id'] ?>)">
                                                                        <i class="fas fa-play"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
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
                
                <!-- Recent Executions -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Последние выполнения
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentExecutions)): ?>
                                    <p class="text-muted">Нет записей о выполнении резервного копирования</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Задание</th>
                                                    <th>Тип выполнения</th>
                                                    <th>Начало</th>
                                                    <th>Завершение</th>
                                                    <th>Размер файла</th>
                                                    <th>Статус</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentExecutions as $execution): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($execution['job_name']) ?></strong>
                                                            <br>
                                                            <small class="text-muted">
                                                                <?= htmlspecialchars($execution['executed_by_username'] ?: 'System') ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $typeClass = $execution['execution_type'] === 'manual' ? 'warning' : 'info';
                                                            $typeText = $execution['execution_type'] === 'manual' ? 'Ручное' : 'По расписанию';
                                                            ?>
                                                            <span class="badge bg-<?= $typeClass ?>"><?= $typeText ?></span>
                                                        </td>
                                                        <td><?= date('d.m.Y H:i', strtotime($execution['started_at'])) ?></td>
                                                        <td>
                                                            <?php if ($execution['completed_at']): ?>
                                                                <?= date('d.m.Y H:i', strtotime($execution['completed_at'])) ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">В процессе</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= $execution['backup_file_size_formatted'] ?: 'N/A' ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = [
                                                                'completed' => 'success',
                                                                'running' => 'primary',
                                                                'failed' => 'danger',
                                                                'cancelled' => 'secondary'
                                                            ][$execution['status']] ?? 'secondary';
                                                            
                                                            $statusText = [
                                                                'completed' => 'Завершено',
                                                                'running' => 'Выполняется',
                                                                'failed' => 'Ошибка',
                                                                'cancelled' => 'Отменено'
                                                            ][$execution['status']] ?? $execution['status'];
                                                            ?>
                                                            <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if ($execution['status'] === 'completed'): ?>
                                                                <div class="btn-group btn-group-sm">
                                                                    <a href="/backup/download?execution_id=<?= $execution['id'] ?>" 
                                                                       class="btn btn-outline-success" title="Скачать">
                                                                        <i class="fas fa-download"></i>
                                                                    </a>
                                                                    <button class="btn btn-outline-info" 
                                                                            onclick="verifyBackup(<?= $execution['id'] ?>)" 
                                                                            title="Проверить">
                                                                        <i class="fas fa-check-circle"></i>
                                                                    </button>
                                                                    <button class="btn btn-outline-warning" 
                                                                            onclick="showRestoreModal(<?= $execution['id'] ?>)" 
                                                                            title="Восстановить">
                                                                        <i class="fas fa-undo"></i>
                                                                    </button>
                                                                </div>
                                                            <?php elseif ($execution['status'] === 'failed'): ?>
                                                                <button class="btn btn-outline-danger btn-sm" 
                                                                        onclick="showErrorDetails('<?= htmlspecialchars($execution['error_message']) ?>')">
                                                                    <i class="fas fa-exclamation-triangle"></i>
                                                                </button>
                                                            <?php endif; ?>
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
    
    <!-- Restore Modal -->
    <div class="modal fade" id="restoreModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Восстановление из резервной копии</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="restoreForm" method="POST" action="/backup/restore">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Внимание!</strong> Восстановление заменит текущие данные. 
                            Убедитесь, что у вас есть актуальная резервная копия.
                        </div>
                        
                        <input type="hidden" id="restore_backup_execution_id" name="backup_execution_id">
                        
                        <div class="mb-3">
                            <label for="target_database" class="form-label">Целевая база данных (опционально)</label>
                            <input type="text" class="form-control" id="target_database" name="target_database" 
                                   placeholder="Оставьте пустым для восстановления в текущую БД">
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirm_restore" name="confirm_restore" required>
                            <label class="form-check-label" for="confirm_restore">
                                Я понимаю последствия и хочу выполнить восстановление
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-undo me-1"></i>Восстановить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Execute backup
        function executeBackup(jobId) {
            if (!confirm('Выполнить резервное копирование?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('job_id', jobId);
            
            fetch('/backup/execute', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Резервное копирование запущено успешно');
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при выполнении резервного копирования');
            });
        }
        
        // Verify backup
        function verifyBackup(executionId) {
            const formData = new FormData();
            formData.append('backup_execution_id', executionId);
            formData.append('verification_type', 'integrity');
            
            fetch('/backup/verify', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Проверка завершена: ' + data.result);
                } else {
                    alert('Ошибка проверки: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при проверке резервной копии');
            });
        }
        
        // Show restore modal
        function showRestoreModal(executionId) {
            document.getElementById('restore_backup_execution_id').value = executionId;
            new bootstrap.Modal(document.getElementById('restoreModal')).show();
        }
        
        // Show error details
        function showErrorDetails(errorMessage) {
            alert('Ошибка выполнения:\n\n' + errorMessage);
        }
        
        // Refresh status
        function refreshStatus() {
            location.reload();
        }
        
        // Handle restore form submission
        document.getElementById('restoreForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!confirm('Вы уверены, что хотите выполнить восстановление? Это действие нельзя отменить.')) {
                return;
            }
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Восстановление...';
            submitBtn.disabled = true;
            
            fetch('/backup/restore', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Восстановление завершено успешно');
                    bootstrap.Modal.getInstance(document.getElementById('restoreModal')).hide();
                    location.reload();
                } else {
                    alert('Ошибка восстановления: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при восстановлении');
            })
            .finally(() => {
                // Restore button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    </script>
</body>
</html>