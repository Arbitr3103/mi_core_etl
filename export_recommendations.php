<?php
/**
 * Экспорт рекомендаций в CSV формат
 * Использование: php export_recommendations.php
 */

require_once 'config_replenishment.php';

echo "=== ЭКСПОРТ РЕКОМЕНДАЦИЙ В CSV ===\n";

try {
    $pdo = getDbConnection();
    
    // Получаем все рекомендации
    $stmt = $pdo->query("
        SELECT 
            product_name as 'Название товара',
            sku as 'SKU',
            ROUND(ads, 2) as 'ADS (продаж в день)',
            current_stock as 'Текущий запас',
            target_stock as 'Целевой запас',
            recommended_quantity as 'Рекомендация к пополнению',
            CASE 
                WHEN recommended_quantity > 100 THEN 'ВЫСОКИЙ'
                WHEN recommended_quantity > 50 THEN 'СРЕДНИЙ'
                ELSE 'НИЗКИЙ'
            END as 'Приоритет',
            calculation_date as 'Дата расчета'
        FROM replenishment_recommendations 
        WHERE calculation_date = CURDATE()
            AND recommended_quantity > 0
        ORDER BY recommended_quantity DESC
    ");
    
    $filename = "replenishment_recommendations_" . date('Y-m-d') . ".csv";
    $file = fopen($filename, 'w');
    
    // Записываем заголовки
    $headers = [
        'Название товара',
        'SKU', 
        'ADS (продаж в день)',
        'Текущий запас',
        'Целевой запас',
        'Рекомендация к пополнению',
        'Приоритет',
        'Дата расчета'
    ];
    
    fputcsv($file, $headers, ';');
    
    // Записываем данные
    $count = 0;
    while ($row = $stmt->fetch()) {
        fputcsv($file, array_values($row), ';');
        $count++;
    }
    
    fclose($file);
    
    echo "✅ Экспорт завершен успешно!\n";
    echo "📁 Файл: $filename\n";
    echo "📊 Записей: $count\n";
    echo "💡 Откройте файл в Excel или Google Sheets\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>