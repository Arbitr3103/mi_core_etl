<?php
/**
 * Простой тест API без включения файла
 */

echo "🧪 Простой тест Ozon API\n";
echo "=======================\n\n";

try {
    // Подключаем классы напрямую
    require_once 'src/classes/OzonDataCache.php';
    require_once 'src/classes/OzonAnalyticsAPI.php';
    
    echo "✅ Классы подключены успешно\n";
    
    // Подключение к БД
    $host = '127.0.0.1';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'secure_password_123';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к БД установлено\n";
    
    // Создаем экземпляр API
    $clientId = '26100';
    $apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';
    
    $ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
    echo "✅ OzonAnalyticsAPI создан\n";
    
    // Тестируем аутентификацию
    $authResult = $ozonAPI->authenticate();
    echo "✅ Аутентификация: $authResult\n";
    
    echo "\n🎯 API готов к работе!\n";
    echo "Теперь можно запускать: php load_ozon_september_2025.php\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
?>