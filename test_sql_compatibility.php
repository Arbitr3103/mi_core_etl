<?php
/**
 * Тестирование совместимости исправленных SQL запросов с MySQL ONLY_FULL_GROUP_BY
 * Протестировать все запросы на совместимость с MySQL ONLY_FULL_GROUP_BY
 */

require_once 'config.php';

class SQLCompatibilityTester {
    private $pdo;
    
    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            
            // Включаем ONLY_FULL_GROUP_BY для тестирования
            $this->pdo->exec("SET sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            
        } catch (PDOException $e) {
            die("Ошибка подключения к БД: " . $e->getMessage());
        }
    }
    
    public function testAllQueries() {
        echo "=== Тестирование совместимости SQL запросов ===\n\n";
        
        $tests = [
            'test_fixed_distinct_query',
            'test_subquery_pattern',
            'test_simple_distinct',
            'test_group_by_aggregation',
            'test_safe_join',
            'test_sync_products_query',
            'test_group_by_compatibility'
        ];
        
        $passed = 0;
        $total = count($tests);
        
        foreach ($tests as $test) {
            try {
                echo "Тестирование: $test... ";
                $this->$test();
                echo "✅ ПРОШЕЛ\n";
                $passed++;
            } catch (Exception $e) {
                echo "❌ ОШИБКА: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n=== Результаты тестирования ===\n";
        echo "Прошло: $passed/$total тестов\n";
        
        if ($passed === $total) {
            echo "🎉 Все запросы совместимы с ONLY_FULL_GROUP_BY!\n";
        } else {
            echo "⚠️  Некоторые запросы требуют доработки\n";
        }
    }
    
    private function test_fixed_distinct_query() {
        $sql = "
            SELECT DISTINCT 
                i.product_id, 
                MAX(i.quantity_present) as max_quantity
            FROM inventory_data i
            LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
            WHERE i.product_id != 0
            GROUP BY i.product_id
            ORDER BY max_quantity DESC
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        if (count($results) >= 0) {
            echo "(получено " . count($results) . " записей) ";
        }
    }
    
    private function test_subquery_pattern() {
        $sql = "
            SELECT product_id, product_name, quantity_present
            FROM (
                SELECT 
                    i.product_id,
                    COALESCE(dp.name, pcr.cached_name, CONCAT('Товар ID ', i.product_id)) as product_name,
                    i.quantity_present,
                    ROW_NUMBER() OVER (ORDER BY i.quantity_present DESC) as rn
                FROM inventory_data i
                LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
                LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
                WHERE i.product_id != 0
            ) ranked_products
            WHERE rn <= 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        echo "(получено " . count($results) . " записей) ";
    }
    
    private function test_simple_distinct() {
        $sql = "
            SELECT DISTINCT pcr.inventory_product_id
            FROM product_cross_reference pcr
            WHERE pcr.sync_status = 'pending'
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        echo "(получено " . count($results) . " записей) ";
    }
    
    private function test_group_by_aggregation() {
        $sql = "
            SELECT 
                pcr.inventory_product_id,
                COUNT(*) as record_count,
                MAX(pcr.last_successful_sync) as last_sync
            FROM product_cross_reference pcr
            JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            GROUP BY pcr.inventory_product_id
            HAVING record_count > 0
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        echo "(получено " . count($results) . " записей) ";
    }
    
    private function test_safe_join() {
        $sql = "
            SELECT 
                i.product_id,
                i.quantity_present,
                COALESCE(dp.name, pcr.cached_name, 'Неизвестный товар') as product_name,
                pcr.sync_status
            FROM inventory_data i
            LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
            WHERE i.product_id IS NOT NULL AND i.product_id != 0
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        echo "(получено " . count($results) . " записей) ";
    }
    
    private function test_sync_products_query() {
        $sql = "
            SELECT 
                pcr.id,
                pcr.inventory_product_id,
                pcr.ozon_product_id,
                pcr.cached_name,
                pcr.sync_status
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
            WHERE (
                dp.name IS NULL 
                OR dp.name LIKE 'Товар Ozon ID%'
                OR pcr.cached_name IS NULL
            )
            AND pcr.sync_status IN ('pending', 'failed')
            ORDER BY pcr.id
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        echo "(получено " . count($results) . " записей) ";
    }
    
    private function test_group_by_compatibility() {
        $sql = "
            SELECT 
                pcr.sync_status,
                COUNT(*) as count,
                COUNT(CASE WHEN pcr.cached_name IS NOT NULL THEN 1 END) as with_names,
                MAX(pcr.last_successful_sync) as latest_sync
            FROM product_cross_reference pcr
            GROUP BY pcr.sync_status
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        echo "(получено " . count($results) . " записей) ";
    }
}

// Запуск тестирования
if (php_sapi_name() === 'cli') {
    $tester = new SQLCompatibilityTester();
    $tester->testAllQueries();
} else {
    echo "<pre>";
    $tester = new SQLCompatibilityTester();
    $tester->testAllQueries();
    echo "</pre>";
}
?>