<?php
/**
 * Диагностика сервера для настройки PostgreSQL
 * Этот файл нужно загрузить на продакшн сервер
 */

header('Content-Type: text/plain; charset=utf-8');

echo "🔍 SERVER DIAGNOSTICS FOR POSTGRESQL SETUP\n";
echo "==========================================\n\n";

echo "1. Server Information:\n";
echo "   OS: " . php_uname('s') . " " . php_uname('r') . "\n";
echo "   PHP Version: " . phpversion() . "\n";
echo "   Current User: " . get_current_user() . "\n";
echo "   Current Directory: " . getcwd() . "\n";
echo "   Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Not set') . "\n\n";

echo "2. PostgreSQL Check:\n";

// Check if PostgreSQL is installed
$psql_check = shell_exec('which psql 2>/dev/null');
if ($psql_check) {
    echo "   ✅ PostgreSQL client found: " . trim($psql_check) . "\n";
    
    // Check PostgreSQL version
    $pg_version = shell_exec('psql --version 2>/dev/null');
    if ($pg_version) {
        echo "   PostgreSQL Version: " . trim($pg_version) . "\n";
    }
} else {
    echo "   ❌ PostgreSQL client not found\n";
}

// Check if PostgreSQL server is running
echo "\n3. PostgreSQL Server Status:\n";
$pg_status = shell_exec('systemctl status postgresql 2>/dev/null || service postgresql status 2>/dev/null');
if ($pg_status) {
    echo "   Service Status: " . (strpos($pg_status, 'active') !== false ? '✅ Running' : '❌ Not running') . "\n";
} else {
    echo "   ❌ Cannot check PostgreSQL service status\n";
}

// Check port 5432
echo "\n4. Port 5432 Check:\n";
$port_check = @fsockopen('localhost', 5432, $errno, $errstr, 5);
if ($port_check) {
    echo "   ✅ Port 5432 is open\n";
    fclose($port_check);
} else {
    echo "   ❌ Port 5432 is not accessible: $errstr ($errno)\n";
}

// Check environment variables
echo "\n5. Environment Variables:\n";
$env_vars = ['PG_HOST', 'PG_USER', 'PG_PASSWORD', 'PG_NAME', 'PG_PORT'];
foreach ($env_vars as $var) {
    $value = getenv($var);
    if ($value) {
        if ($var === 'PG_PASSWORD') {
            echo "   $var: ✅ Set (" . strlen($value) . " chars)\n";
        } else {
            echo "   $var: ✅ $value\n";
        }
    } else {
        echo "   $var: ❌ Not set\n";
    }
}

// Check file system
echo "\n6. File System Check:\n";
$files_to_check = [
    '../.env',
    '../config/production.php',
    '../config/production_db_override.php',
    '../config/database_postgresql.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "   ✅ $file exists\n";
    } else {
        echo "   ❌ $file missing\n";
    }
}

// Try to connect to PostgreSQL
echo "\n7. PostgreSQL Connection Test:\n";
try {
    // Try with hardcoded credentials
    $dsn = "pgsql:host=localhost;port=5432;dbname=mi_core_db";
    $pdo = new PDO($dsn, 'mi_core_user', 'PostgreSQL_MDM_2025_SecurePass!', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "   ✅ Connection successful!\n";
    
    $result = $pdo->query('SELECT version()')->fetch();
    echo "   PostgreSQL Version: " . $result['version'] . "\n";
    
    $result = $pdo->query("SELECT count(*) as count FROM information_schema.tables WHERE table_schema = 'public'")->fetch();
    echo "   Tables in database: " . $result['count'] . "\n";
    
} catch (PDOException $e) {
    echo "   ❌ Connection failed: " . $e->getMessage() . "\n";
    
    // Provide specific solutions based on error
    $error = $e->getMessage();
    echo "\n   🔧 Suggested Solutions:\n";
    
    if (strpos($error, 'fe_sendauth: no password supplied') !== false) {
        echo "   - Password authentication issue\n";
        echo "   - Run: sudo -u postgres psql\n";
        echo "   - Then: ALTER USER mi_core_user PASSWORD 'PostgreSQL_MDM_2025_SecurePass!';\n";
    } elseif (strpos($error, 'role') !== false && strpos($error, 'does not exist') !== false) {
        echo "   - User does not exist\n";
        echo "   - Run: sudo -u postgres createuser -P mi_core_user\n";
        echo "   - Password: PostgreSQL_MDM_2025_SecurePass!\n";
    } elseif (strpos($error, 'database') !== false && strpos($error, 'does not exist') !== false) {
        echo "   - Database does not exist\n";
        echo "   - Run: sudo -u postgres createdb -O mi_core_user mi_core_db\n";
    } elseif (strpos($error, 'Connection refused') !== false) {
        echo "   - PostgreSQL server is not running\n";
        echo "   - Run: sudo systemctl start postgresql\n";
    }
}

echo "\n8. Setup Commands for Server Admin:\n";
echo "   # Install PostgreSQL (Ubuntu/Debian):\n";
echo "   sudo apt update && sudo apt install -y postgresql postgresql-contrib\n\n";
echo "   # Start PostgreSQL:\n";
echo "   sudo systemctl start postgresql\n";
echo "   sudo systemctl enable postgresql\n\n";
echo "   # Create user and database:\n";
echo "   sudo -u postgres psql << 'EOF'\n";
echo "   CREATE USER mi_core_user WITH PASSWORD 'PostgreSQL_MDM_2025_SecurePass!';\n";
echo "   CREATE DATABASE mi_core_db OWNER mi_core_user;\n";
echo "   GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;\n";
echo "   \\q\n";
echo "   EOF\n\n";
echo "   # Test connection:\n";
echo "   PGPASSWORD='PostgreSQL_MDM_2025_SecurePass!' psql -h localhost -U mi_core_user -d mi_core_db -c \"SELECT version();\"\n\n";

echo "🏁 DIAGNOSTICS COMPLETE\n";
echo "========================\n";
echo "Please run the setup commands above on the server to fix the database connection.\n";
?>