<?php
/**
 * Тест для проверки таблицы regions
 */

try {
    $host = 'localhost';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'your_password_here';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "=== ТЕСТ ТАБЛИЦЫ REGIONS ===\n\n";
    
    // 1. Структура таблицы regions
    echo "1. СТРУКТУРА ТАБЛИЦЫ regions:\n";
    $stmt = $pdo->query("DESCRIBE regions");
    $columns = $stmt->fetchAll();
    foreach ($columns as $column) {
        echo "- {$column['Field']} ({$column['Type']})\n";
    }
    echo "\n";
    
    // 2. Все записи из regions
    echo "2. ВСЕ ЗАПИСИ ИЗ regions:\n";
    $stmt = $pdo->query("SELECT * FROM regions ORDER BY name");
    $regions = $stmt->fetchAll();
    foreach ($regions as $region) {
        echo "ID: {$region['id']}, Name: {$region['name']}\n";
    }
    echo "\n";
    
    // 3. Проверяем связь regions с v_car_applicability
    echo "3. СВЯЗЬ regions С v_car_applicability:\n";
    $stmt = $pdo->query("
        SELECT DISTINCT 
            r.id,
            r.name,
            COUNT(*) as count
        FROM regions r
        INNER JOIN v_car_applicability v ON r.id = v.region_id
        GROUP BY r.id, r.name
        ORDER BY r.name
    ");
    $linked = $stmt->fetchAll();
    foreach ($linked as $link) {
        echo "ID: {$link['id']}, Name: {$link['name']}, Count: {$link['count']}\n";
    }
    echo "\n";
    
    // 4. Тест API запроса
    echo "4. ТЕСТ API ЗАПРОСА:\n";
    $stmt = $pdo->query("
        SELECT DISTINCT 
            r.id,
            r.name
        FROM regions r
        INNER JOIN v_car_applicability v ON r.id = v.region_id
        WHERE r.name IS NOT NULL AND r.name != '' AND r.name != 'NULL'
          AND r.name != 'легковые'
        ORDER BY r.name ASC
        LIMIT 10
    ");
    $apiTest = $stmt->fetchAll();
    foreach ($apiTest as $country) {
        echo "ID: {$country['id']}, Name: {$country['name']}\n";
    }
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}
?>