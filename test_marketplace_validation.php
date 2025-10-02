<?php
/**
 * Test script for MarketplaceValidator class
 * Demonstrates data validation and quality reporting functionality
 * 
 * Requirements: 6.1, 6.2, 6.4 - Testing data validation for marketplace classification
 */

require_once 'config.php';
require_once 'src/classes/MarketplaceValidator.php';

try {
    // Подключение к базе данных
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    echo "=== MARKETPLACE DATA VALIDATION TEST ===\n";
    echo "Connected to database: " . DB_NAME . "\n";
    echo "Test started at: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Создаем экземпляр валидатора
    $validator = new MarketplaceValidator($pdo);
    
    // Определяем период для тестирования (последние 30 дней)
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-30 days'));
    
    echo "Testing period: {$startDate} to {$endDate}\n\n";
    
    // ===================================================================
    // ТЕСТ 1: Валидация SKU назначений
    // ===================================================================
    
    echo "=== TEST 1: SKU ASSIGNMENTS VALIDATION ===\n";
    
    $skuValidation = $validator->validateSkuAssignments();
    
    echo "Total products: " . $skuValidation['total_products'] . "\n";
    echo "Valid products: " . $skuValidation['valid_products'] . "\n";
    echo "Validity percentage: " . $skuValidation['validity_percentage'] . "%\n\n";
    
    echo "Statistics:\n";
    foreach ($skuValidation['statistics'] as $key => $value) {
        echo "  {$key}: {$value}\n";
    }
    echo "\n";
    
    echo "Issues found:\n";
    foreach ($skuValidation['issues'] as $issueType => $issues) {
        echo "  {$issueType}: " . count($issues) . " cases\n";
        if (count($issues) > 0 && count($issues) <= 3) {
            foreach ($issues as $issue) {
                echo "    - Product ID {$issue['product_id']}: {$issue['product_name']}\n";
            }
        }
    }
    echo "\n";
    
    // ===================================================================
    // ТЕСТ 2: Проверка консистентности данных
    // ===================================================================
    
    echo "=== TEST 2: DATA CONSISTENCY CHECK ===\n";
    
    $consistencyCheck = $validator->validateDataConsistency($startDate, $endDate);
    
    echo "Period: {$consistencyCheck['period']['start']} to {$consistencyCheck['period']['end']}\n";
    echo "Is consistent: " . ($consistencyCheck['is_consistent'] ? 'YES' : 'NO') . "\n\n";
    
    echo "Total stats:\n";
    foreach ($consistencyCheck['total_stats'] as $key => $value) {
        echo "  {$key}: {$value}\n";
    }
    echo "\n";
    
    echo "Marketplace breakdown:\n";
    foreach ($consistencyCheck['marketplace_breakdown'] as $marketplace => $stats) {
        echo "  {$marketplace}:\n";
        foreach ($stats as $key => $value) {
            echo "    {$key}: {$value}\n";
        }
    }
    echo "\n";
    
    if (!empty($consistencyCheck['consistency_issues'])) {
        echo "Consistency issues:\n";
        foreach ($consistencyCheck['consistency_issues'] as $issue) {
            echo "  - {$issue['type']}: difference of {$issue['difference']} (severity: {$issue['severity']})\n";
        }
        echo "\n";
    }
    
    // ===================================================================
    // ТЕСТ 3: Краткая сводка качества данных
    // ===================================================================
    
    echo "=== TEST 3: QUALITY SUMMARY ===\n";
    
    $qualitySummary = $validator->getQualitySummary($startDate, $endDate);
    
    echo "Overall quality score: " . $qualitySummary['overall_quality_score'] . "/100\n";
    echo "Quality rating: " . $qualitySummary['quality_rating'] . "\n";
    echo "Classification rate: " . $qualitySummary['classification_rate'] . "%\n";
    echo "SKU validity rate: " . $qualitySummary['sku_validity_rate'] . "%\n";
    echo "Data consistency: " . ($qualitySummary['data_consistency'] ? 'CONSISTENT' : 'INCONSISTENT') . "\n";
    echo "Total orders: " . $qualitySummary['total_orders'] . "\n";
    echo "Recommendations count: " . $qualitySummary['recommendations_count'] . "\n\n";
    
    // ===================================================================
    // ТЕСТ 4: Полный отчет о качестве данных
    // ===================================================================
    
    echo "=== TEST 4: FULL DATA QUALITY REPORT ===\n";
    
    $fullReport = $validator->generateDataQualityReport($startDate, $endDate);
    
    echo "Report generated at: " . $fullReport['generated_at'] . "\n";
    echo "Overall quality score: " . $fullReport['overall_quality_score'] . "/100\n";
    echo "Quality rating: " . $fullReport['quality_rating'] . "\n\n";
    
    echo "Quality scores breakdown:\n";
    foreach ($fullReport['quality_scores'] as $metric => $score) {
        echo "  {$metric}: {$score}/100\n";
    }
    echo "\n";
    
    echo "Recommendations:\n";
    foreach ($fullReport['recommendations'] as $i => $recommendation) {
        echo "  " . ($i + 1) . ". [{$recommendation['priority']}] {$recommendation['title']}\n";
        echo "     {$recommendation['description']}\n";
        if (!empty($recommendation['actions'])) {
            echo "     Actions:\n";
            foreach ($recommendation['actions'] as $action) {
                echo "       - {$action}\n";
            }
        }
        echo "\n";
    }
    
    // ===================================================================
    // ТЕСТ 5: Экспорт отчета в JSON
    // ===================================================================
    
    echo "=== TEST 5: EXPORT REPORT TO JSON ===\n";
    
    $jsonFilename = 'marketplace_validation_test_' . date('Y-m-d_H-i-s') . '.json';
    $exportSuccess = $validator->exportReportToJson($fullReport, $jsonFilename);
    
    if ($exportSuccess) {
        echo "Report successfully exported to: {$jsonFilename}\n";
        echo "File size: " . number_format(filesize($jsonFilename)) . " bytes\n";
    } else {
        echo "Failed to export report to JSON\n";
    }
    echo "\n";
    
    // ===================================================================
    // ТЕСТ 6: Тестирование с конкретным клиентом
    // ===================================================================
    
    echo "=== TEST 6: CLIENT-SPECIFIC VALIDATION ===\n";
    
    // Получаем первого доступного клиента для тестирования
    $clientStmt = $pdo->query("SELECT id, name FROM clients LIMIT 1");
    $testClient = $clientStmt->fetch();
    
    if ($testClient) {
        echo "Testing with client: {$testClient['name']} (ID: {$testClient['id']})\n";
        
        $clientQualitySummary = $validator->getQualitySummary($startDate, $endDate, $testClient['id']);
        
        echo "Client-specific quality summary:\n";
        echo "  Overall quality score: " . $clientQualitySummary['overall_quality_score'] . "/100\n";
        echo "  Quality rating: " . $clientQualitySummary['quality_rating'] . "\n";
        echo "  Total orders: " . $clientQualitySummary['total_orders'] . "\n";
        echo "  Classification rate: " . $clientQualitySummary['classification_rate'] . "%\n";
    } else {
        echo "No clients found in database for client-specific testing\n";
    }
    echo "\n";
    
    // ===================================================================
    // РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ
    // ===================================================================
    
    echo "=== TEST RESULTS SUMMARY ===\n";
    echo "All tests completed successfully!\n";
    echo "Test finished at: " . date('Y-m-d H:i:s') . "\n";
    
    // Определяем общий статус качества данных
    $overallStatus = 'UNKNOWN';
    if ($fullReport['overall_quality_score'] >= 95) {
        $overallStatus = 'EXCELLENT';
    } elseif ($fullReport['overall_quality_score'] >= 85) {
        $overallStatus = 'GOOD';
    } elseif ($fullReport['overall_quality_score'] >= 70) {
        $overallStatus = 'FAIR';
    } else {
        $overallStatus = 'POOR';
    }
    
    echo "\nOVERALL DATA QUALITY STATUS: {$overallStatus}\n";
    
    if ($fullReport['overall_quality_score'] < 85) {
        echo "\nACTION REQUIRED: Data quality is below recommended threshold (85%)\n";
        echo "Please review the recommendations above and implement necessary improvements.\n";
    } else {
        echo "\nDATA QUALITY IS ACCEPTABLE: No immediate action required.\n";
        echo "Continue monitoring data quality regularly.\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>