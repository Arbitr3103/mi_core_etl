<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка - MDM System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .error-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .error-card {
            max-width: 500px;
            width: 100%;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .error-icon {
            font-size: 4rem;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="card error-card">
            <div class="card-body text-center p-5">
                <div class="error-icon mb-4">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                
                <h2 class="card-title text-danger mb-3">
                    <?= htmlspecialchars($errorData['message'] ?? 'Произошла ошибка') ?>
                </h2>
                
                <?php if (isset($errorData['details']) && !empty($errorData['details'])): ?>
                    <div class="alert alert-light text-start mb-4">
                        <small class="text-muted">
                            <strong>Детали ошибки:</strong><br>
                            <?= htmlspecialchars($errorData['details']) ?>
                        </small>
                    </div>
                <?php endif; ?>
                
                <div class="d-grid gap-2">
                    <a href="/mdm/dashboard" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i>
                        Вернуться на главную
                    </a>
                    
                    <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>
                        Попробовать снова
                    </button>
                </div>
                
                <div class="mt-4">
                    <small class="text-muted">
                        Если проблема повторяется, обратитесь к системному администратору.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>