<?php
require_once 'config.php';

echo "<h2>🔍 Проверка данных в базе</h2>";

try {
    $pdo = getDatabaseConnection();
    
    // Проверяем product_cross_reference
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM product_cross_reference");
    $total = $stmt->fetch()['total'];
    echo "<p><strong>Всего записей в product_cross_reference:</strong> $total</p>";
    
    if ($total > 0) {
        // Проверяем статусы синхронизации
        $stmt = $pdo->query("
            SELECT sync_status, COUNT(*) as count 
            FROM product_cross_reference 
            GROUP BY sync_status
        ");
        
        echo "<h3>Статусы синхронизации:</h3>";
        while ($row = $stmt->fetch()) {
            echo "<p>{$row['sync_status']}: {$row['count']} записей</p>";
        }
        
        // Проверяем качество данных
        $stmt = $pdo->query("
            SELECT 
                COUNT(CASE WHEN real_product_name IS NOT NULL AND real_product_name != '' THEN 1 END) as with_names,
                COUNT(CASE WHEN brand IS NOT NULL AND brand != '' THEN 1 END) as with_brands,
                COUNT(*) as total
            FROM product_cross_reference
        ");
        
        $quality = $stmt->fetch();
        echo "<h3>Качество данных:</h3>";
        echo "<p>С реальными названиями: {$quality['with_names']} из {$quality['total']}</p>";
        echo "<p>С брендами: {$quality['with_brands']} из {$quality['total']}</p>";
        
    } else {
        echo "<p><strong>❌ Таблица пустая! Нужно запустить синхронизацию.</strong></p>";
        echo "<p>Запустите: <code>php sync-real-product-names-v2.php</code></p>";
    }
    
} catch (Exception $e) {
    echo "<p><strong>❌ Ошибка:</strong> " . $e->getMessage() . "</p>";
}
?>