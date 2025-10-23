<?php
/**
 * Production Environment Configuration Script
 * 
 * This script configures the production environment variables
 * and validates the configuration for the warehouse dashboard deployment.
 */

echo "🔧 Configuring Production Environment for Warehouse Dashboard\n";
echo str_repeat('=', 60) . "\n";

// ===================================================================
// SET PRODUCTION ENVIRONMENT VARIABLES
// ===================================================================

$production_vars = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'NODE_ENV' => 'production',
    'VERSION' => '1.0.0',
    'TIMEZONE' => 'Europe/Moscow',
    
    // Database settings
    'PG_HOST' => 'localhost',
    'PG_USER' => 'mi_core_user',
    'PG_PASSWORD' => 'PostgreSQL_MDM_2025_SecurePass!',
    'PG_NAME' => 'mi_core_db',
    'PG_PORT' => '5432',
    
    'DB_HOST' => 'localhost',
    'DB_USER' => 'mdm_prod_user',
    'DB_PASSWORD' => 'MDM_Prod_2025_SecurePass!',
    'DB_NAME' => 'mi_core',
    'DB_PORT' => '3306',
    
    // API Keys
    'OZON_CLIENT_ID' => '26100',
    'OZON_API_KEY' => '7e074977-e0db-4ace-ba9e-82903e088b4b',
    'WB_API_KEY' => 'eyJhbGciOiJFUzI1NiIsImtpZCI6IjIwMjUwOTA0djEiLCJ0eXAiOiJKV1QifQ.eyJlbnQiOjEsImV4cCI6MTc3MzcxODA5MiwiaWQiOiIwMTk5NGRmZC1hNDBiLTc2MjQtODI1OC1lYWNkYWZiMGJmOGIiLCJpaWQiOjUyMDk2NDc1LCJvaWQiOjQ5MzkzLCJzIjoxMDczNzU3MzEwLCJzaWQiOiI4MmIxMTA1ZC1hNjQyLTU2NjMtOGQ2Mi1mYzMxYTA5ZDhiNjAiLCJ0IjpmYWxzZSwidWlkIjo1MjA5NjQ3NX0.vdnvXQ-djVmq9oLSKxIX7-KO6MGEuuUfd0pqcgXuXAj6ogEoKq6G-O6NNzqJT-XvSPbhXGGyJyDhN1J6PajK5Q',
    
    // Logging
    'LOG_LEVEL' => 'info',
    'LOG_PATH' => '/var/log/warehouse-dashboard',
    'LOG_MAX_SIZE' => '100MB',
    'LOG_MAX_FILES' => '30',
    'LOG_CHANNEL' => 'daily',
    
    // Security
    'JWT_SECRET' => 'MDM_JWT_2025_SuperSecretKey_ProductionOnly!',
    'ENCRYPTION_KEY' => 'MDM_Encrypt_2025_SuperSecretKey_ProductionOnly!',
    
    // Performance
    'MAX_CONNECTIONS' => '200',
    'QUERY_TIMEOUT' => '30',
    'REQUEST_TIMEOUT' => '30',
    'MAX_RETRIES' => '3',
    'OZON_REQUEST_DELAY' => '0.1',
    'WB_REQUEST_DELAY' => '0.5',
    
    // API Configuration
    'API_BASE_URL' => 'https://www.market-mi.ru/api',
    'CORS_ALLOWED_ORIGINS' => 'https://www.market-mi.ru,https://market-mi.ru',
    'CORS_ENABLED' => 'true',
    'API_CACHE_ENABLED' => 'true',
    'API_CACHE_TTL' => '300',
    'RATE_LIMIT_ENABLED' => 'true',
    'RATE_LIMIT_RPM' => '60',
];

echo "📝 Setting environment variables...\n";

// Set environment variables
foreach ($production_vars as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("$key=$value");
    echo "  ✅ $key = " . (strlen($value) > 50 ? substr($value, 0, 47) . '...' : $value) . "\n";
}

echo "\n";

// ===================================================================
// VALIDATE CONFIGURATION
// ===================================================================

echo "🔍 Validating configuration...\n";

$errors = [];
$warnings = [];

// Check required directories
$required_dirs = [
    '/var/log/warehouse-dashboard' => 'Log directory',
    '/var/www/market-mi.ru' => 'Web root directory',
];

foreach ($required_dirs as $dir => $description) {
    if (!is_dir($dir)) {
        $warnings[] = "$description does not exist: $dir";
    } elseif (!is_writable($dir)) {
        $errors[] = "$description is not writable: $dir";
    } else {
        echo "  ✅ $description: $dir\n";
    }
}

// Test database connections
echo "\n🗄️ Testing database connections...\n";

// Test PostgreSQL
try {
    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s",
        $_ENV['PG_HOST'],
        $_ENV['PG_PORT'],
        $_ENV['PG_NAME']
    );
    
    $pdo = new PDO($dsn, $_ENV['PG_USER'], $_ENV['PG_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "  ✅ PostgreSQL connection successful\n";
} catch (Exception $e) {
    $errors[] = "PostgreSQL connection failed: " . $e->getMessage();
    echo "  ❌ PostgreSQL connection failed: " . $e->getMessage() . "\n";
}

// Test MySQL
try {
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $_ENV['DB_HOST'],
        $_ENV['DB_PORT'],
        $_ENV['DB_NAME']
    );
    
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "  ✅ MySQL connection successful\n";
} catch (Exception $e) {
    $warnings[] = "MySQL connection failed: " . $e->getMessage();
    echo "  ⚠️ MySQL connection failed: " . $e->getMessage() . "\n";
}

// Test API keys
echo "\n🔑 Validating API keys...\n";

if (!empty($_ENV['OZON_CLIENT_ID']) && !empty($_ENV['OZON_API_KEY'])) {
    echo "  ✅ Ozon API credentials configured\n";
} else {
    $warnings[] = "Ozon API credentials not configured";
    echo "  ⚠️ Ozon API credentials not configured\n";
}

if (!empty($_ENV['WB_API_KEY'])) {
    echo "  ✅ Wildberries API key configured\n";
} else {
    $warnings[] = "Wildberries API key not configured";
    echo "  ⚠️ Wildberries API key not configured\n";
}

// Test security settings
echo "\n🔒 Validating security settings...\n";

if (!empty($_ENV['JWT_SECRET']) && strlen($_ENV['JWT_SECRET']) >= 32) {
    echo "  ✅ JWT secret configured\n";
} else {
    $errors[] = "JWT secret not configured or too short";
    echo "  ❌ JWT secret not configured or too short\n";
}

if (!empty($_ENV['ENCRYPTION_KEY']) && strlen($_ENV['ENCRYPTION_KEY']) >= 32) {
    echo "  ✅ Encryption key configured\n";
} else {
    $errors[] = "Encryption key not configured or too short";
    echo "  ❌ Encryption key not configured or too short\n";
}

// ===================================================================
// CREATE PRODUCTION .ENV FILE
// ===================================================================

echo "\n📄 Creating production .env file...\n";

$env_content = "# Production Environment Variables for Warehouse Dashboard\n";
$env_content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($production_vars as $key => $value) {
    $env_content .= "$key=$value\n";
}

file_put_contents('.env.production', $env_content);
echo "  ✅ .env.production file created\n";

// ===================================================================
// SUMMARY
// ===================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "📊 CONFIGURATION SUMMARY\n";
echo str_repeat('=', 60) . "\n";

if (empty($errors)) {
    echo "✅ Configuration is valid for production deployment!\n";
} else {
    echo "❌ Configuration has errors that must be fixed:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

if (!empty($warnings)) {
    echo "\n⚠️ Warnings (non-critical):\n";
    foreach ($warnings as $warning) {
        echo "  - $warning\n";
    }
}

echo "\n📋 Next steps:\n";
echo "1. Copy .env.production to production server\n";
echo "2. Run: source .env.production\n";
echo "3. Test API endpoints\n";
echo "4. Deploy frontend build\n";

echo "\n🔗 Production URLs:\n";
echo "Dashboard: https://www.market-mi.ru/warehouse-dashboard\n";
echo "API: https://www.market-mi.ru/api/warehouse-dashboard.php\n";

// Exit with error code if there are critical errors
if (!empty($errors)) {
    exit(1);
}

echo "\n🎉 Production environment configuration completed successfully!\n";
exit(0);