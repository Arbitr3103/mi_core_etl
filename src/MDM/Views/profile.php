<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль пользователя - MDM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/src/MDM/assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/partials/navbar.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-user me-2"></i>Профиль пользователя</h1>
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
                
                <div class="row">
                    <!-- User Information -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Информация о пользователе
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Имя пользователя:</strong></div>
                                    <div class="col-sm-8"><?= htmlspecialchars($user['username']) ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Email:</strong></div>
                                    <div class="col-sm-8"><?= htmlspecialchars($user['email']) ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Полное имя:</strong></div>
                                    <div class="col-sm-8"><?= htmlspecialchars($user['full_name'] ?: 'Не указано') ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Последний вход:</strong></div>
                                    <div class="col-sm-8">
                                        <?php if ($user['last_login']): ?>
                                            <?= date('d.m.Y H:i', strtotime($user['last_login'])) ?>
                                        <?php else: ?>
                                            Никогда
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Roles -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-user-tag me-2"></i>Роли пользователя
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($roles)): ?>
                                    <p class="text-muted">Роли не назначены</p>
                                <?php else: ?>
                                    <?php foreach ($roles as $role): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <span class="badge bg-primary"><?= htmlspecialchars($role['display_name']) ?></span>
                                                <?php if ($role['description']): ?>
                                                    <small class="text-muted d-block"><?= htmlspecialchars($role['description']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                с <?= date('d.m.Y', strtotime($role['assigned_at'])) ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Change Password -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-key me-2"></i>Изменить пароль
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="changePasswordForm" method="POST" action="/change-password">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Текущий пароль</label>
                                        <input type="password" class="form-control" id="current_password" 
                                               name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">Новый пароль</label>
                                        <input type="password" class="form-control" id="new_password" 
                                               name="new_password" required minlength="8">
                                        <div class="form-text">Минимум 8 символов</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Подтвердите новый пароль</label>
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Изменить пароль
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Последняя активность
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($activities)): ?>
                                    <p class="text-muted">Нет записей об активности</p>
                                <?php else: ?>
                                    <div class="activity-list" style="max-height: 300px; overflow-y: auto;">
                                        <?php foreach ($activities as $activity): ?>
                                            <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                                                <div>
                                                    <small class="fw-bold"><?= htmlspecialchars($activity['action']) ?></small>
                                                    <?php if ($activity['resource']): ?>
                                                        <small class="text-muted d-block">
                                                            <?= htmlspecialchars($activity['resource']) ?>
                                                            <?php if ($activity['resource_id']): ?>
                                                                #<?= htmlspecialchars($activity['resource_id']) ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= date('d.m H:i', strtotime($activity['created_at'])) ?>
                                                </small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle password change form
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                alert('Новые пароли не совпадают');
                return;
            }
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Сохранение...';
            submitBtn.disabled = true;
            
            fetch('/change-password', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    const form = document.getElementById('changePasswordForm');
                    form.parentNode.insertBefore(alertDiv, form);
                    
                    // Clear form
                    form.reset();
                } else {
                    alert(data.error);
                }
            })
            .catch(error => {
                console.error('Password change error:', error);
                alert('Произошла ошибка при изменении пароля');
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