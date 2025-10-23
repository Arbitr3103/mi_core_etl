<?php
/**
 * Диагностика подключения к базе данных PostgreSQL
 * 
 * Этот скрипт поможет определить и решить проблемы с подключением к PostgreSQL
 */

echo "🔍 ДИАГНОСТИКА ПОДКЛЮЧЕНИЯ К POSTGRESQL\n";
echo "=====================================\n\n";

// Загружаем конфигурацию
require_once __DIR__ . '/config/database_postgresql.php';

echo "1. Проверка конфигурации...\n";
echo "   PG_HOST: " . (getenv('PG_HOST') ?: 'НЕ УСТАНОВЛЕНО') . "\n";
echo "   PG_PORT: " . (getenv('PG_PORT') ?: 'НЕ УСТАНОВЛЕНО') . "\n";
echo "   PG_USER: " . (getenv('PG_USER') ?: 'НЕ УСТАНОВЛЕНО') . "\n";
echo "   PG_PASSWORD: " . (getenv('PG_PASSWORD') ? '✅ УСТАНОВЛЕНО (' . strlen(getenv('PG_PASSWORD')) . ' символов)' : '❌ НЕ УСТАНОВЛЕНО') . "\n";
echo "   PG_NAME: " . (getenv('PG_NAME') ?: 'НЕ УСТАНОВЛЕНО') . "\n\n";

echo "2. Проверка доступности PostgreSQL сервера...\n";

// Проверяем, запущен ли PostgreSQL
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5432';

echo "   Проверяем подключение к $host:$port...\n";

$connection = @fsockopen($host, $port, $errno, $errstr, 5);
if ($connection) {
    echo "   ✅ PostgreSQL сервер доступен на $host:$port\n";
    fclose($connection);
} else {
    echo "   ❌ PostgreSQL сервер недоступен на $host:$port\n";
    echo "   Ошибка: $errstr ($errno)\n";
    echo "\n🔧 РЕШЕНИЕ:\n";
    echo "   1. Убедитесь, что PostgreSQL установлен и запущен:\n";
    echo "      sudo systemctl status postgresql\n";
    echo "      sudo systemctl start postgresql\n";
    echo "   2. Проверьте настройки в /etc/postgresql/*/main/postgresql.conf:\n";
    echo "      listen_addresses = 'localhost'\n";
    echo "      port = 5432\n";
    echo "   3. Проверьте настройки в /etc/postgresql/*/main/pg_hba.conf:\n";
    echo "      local   all             all                                     md5\n";
    echo "      host    all             all             127.0.0.1/32            md5\n\n";
    return;
}

echo "\n3. Проверка аутентификации пользователя...\n";

$user = getenv('PG_USER') ?: 'mi_core_user';
$password = getenv('PG_PASSWORD') ?: '';
$dbname = getenv('PG_NAME') ?: 'mi_core_db';

if (!$password) {
    echo "   ❌ Пароль не установлен в переменных окружения\n";
    echo "\n🔧 РЕШЕНИЕ:\n";
    echo "   Убедитесь, что в .env файле установлен PG_PASSWORD\n\n";
    return;
}

// Пробуем подключиться к PostgreSQL
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    echo "   Пробуем подключиться как пользователь '$user' к базе '$dbname'...\n";
    
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    echo "   ✅ Подключение успешно!\n";
    
    // Проверяем версию PostgreSQL
    $result = $pdo->query('SELECT version()')->fetch();
    echo "   PostgreSQL версия: " . $result['version'] . "\n";
    
    // Проверяем количество таблиц
    $result = $pdo->query("SELECT count(*) as count FROM information_schema.tables WHERE table_schema = 'public'")->fetch();
    echo "   Количество таблиц в базе: " . $result['count'] . "\n";
    
    // Проверяем, есть ли таблица inventory
    $result = $pdo->query("SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name = 'inventory') as exists")->fetch();
    if ($result['exists']) {
        echo "   ✅ Таблица 'inventory' найдена\n";
        
        // Проверяем количество записей
        $result = $pdo->query("SELECT count(*) as count FROM inventory LIMIT 1")->fetch();
        echo "   Записей в таблице inventory: " . $result['count'] . "\n";
    } else {
        echo "   ⚠️  Таблица 'inventory' не найдена\n";
        echo "   Возможно, нужно выполнить миграцию базы данных\n";
    }
    
} catch (PDOException $e) {
    echo "   ❌ Ошибка подключения: " . $e->getMessage() . "\n";
    
    $errorCode = $e->getCode();
    $errorMessage = $e->getMessage();
    
    echo "\n🔧 ДИАГНОСТИКА ОШИБКИ:\n";
    
    if (strpos($errorMessage, 'fe_sendauth: no password supplied') !== false) {
        echo "   ПРОБЛЕМА: Не передан пароль для аутентификации\n";
        echo "   РЕШЕНИЕ:\n";
        echo "   1. Проверьте, что пароль установлен в .env файле:\n";
        echo "      PG_PASSWORD=PostgreSQL_MDM_2025_SecurePass!\n";
        echo "   2. Убедитесь, что файл .env загружается правильно\n";
        echo "   3. Проверьте права доступа к файлу .env (должен быть 600)\n";
        
    } elseif (strpos($errorMessage, 'password authentication failed') !== false) {
        echo "   ПРОБЛЕМА: Неверный пароль пользователя\n";
        echo "   РЕШЕНИЕ:\n";
        echo "   1. Проверьте пароль в .env файле\n";
        echo "   2. Сбросьте пароль пользователя в PostgreSQL:\n";
        echo "      sudo -u postgres psql\n";
        echo "      ALTER USER mi_core_user PASSWORD 'PostgreSQL_MDM_2025_SecurePass!';\n";
        
    } elseif (strpos($errorMessage, 'database') !== false && strpos($errorMessage, 'does not exist') !== false) {
        echo "   ПРОБЛЕМА: База данных не существует\n";
        echo "   РЕШЕНИЕ:\n";
        echo "   1. Создайте базу данных:\n";
        echo "      sudo -u postgres createdb -O mi_core_user mi_core_db\n";
        echo "   2. Или через psql:\n";
        echo "      sudo -u postgres psql\n";
        echo "      CREATE DATABASE mi_core_db OWNER mi_core_user;\n";
        
    } elseif (strpos($errorMessage, 'role') !== false && strpos($errorMessage, 'does not exist') !== false) {
        echo "   ПРОБЛЕМА: Пользователь не существует\n";
        echo "   РЕШЕНИЕ:\n";
        echo "   1. Создайте пользователя:\n";
        echo "      sudo -u postgres createuser -P mi_core_user\n";
        echo "   2. Или через psql:\n";
        echo "      sudo -u postgres psql\n";
        echo "      CREATE USER mi_core_user WITH PASSWORD 'PostgreSQL_MDM_2025_SecurePass!';\n";
        echo "      GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;\n";
        
    } else {
        echo "   ОБЩИЕ РЕШЕНИЯ:\n";
        echo "   1. Проверьте статус PostgreSQL: sudo systemctl status postgresql\n";
        echo "   2. Перезапустите PostgreSQL: sudo systemctl restart postgresql\n";
        echo "   3. Проверьте логи: sudo tail -f /var/log/postgresql/postgresql-*.log\n";
    }
}

echo "\n4. Проверка конфигурации продакшена...\n";

// Проверяем, загружается ли production_db_override.php
if (file_exists(__DIR__ . '/config/production_db_override.php')) {
    echo "   ✅ Файл production_db_override.php найден\n";
    
    // Проверяем функцию getProductionPgConnection
    if (function_exists('getProductionPgConnection')) {
        echo "   ✅ Функция getProductionPgConnection доступна\n";
        
        try {
            require_once __DIR__ . '/config/production_db_override.php';
            $prodPdo = getProductionPgConnection();
            echo "   ✅ Продакшн подключение работает!\n";
        } catch (Exception $e) {
            echo "   ❌ Ошибка продакшн подключения: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ❌ Функция getProductionPgConnection не найдена\n";
    }
} else {
    echo "   ❌ Файл production_db_override.php не найден\n";
}

echo "\n🏁 ДИАГНОСТИКА ЗАВЕРШЕНА\n";
echo "========================\n";

// Предложения по решению
echo "\n💡 БЫСТРЫЕ РЕШЕНИЯ:\n";
echo "1. Установка и настройка PostgreSQL (если не установлен):\n";
echo "   sudo apt update && sudo apt install postgresql postgresql-contrib\n";
echo "   sudo systemctl start postgresql\n";
echo "   sudo systemctl enable postgresql\n\n";

echo "2. Создание пользователя и базы данных:\n";
echo "   sudo -u postgres psql << EOF\n";
echo "   CREATE USER mi_core_user WITH PASSWORD 'PostgreSQL_MDM_2025_SecurePass!';\n";
echo "   CREATE DATABASE mi_core_db OWNER mi_core_user;\n";
echo "   GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;\n";
echo "   \\q\n";
echo "   EOF\n\n";

echo "3. Проверка подключения:\n";
echo "   psql -h localhost -U mi_core_user -d mi_core_db\n\n";

echo "4. После исправления запустите тесты:\n";
echo "   php run_production_tests.php\n\n";

?>