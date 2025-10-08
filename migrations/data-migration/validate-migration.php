<?php

/**
 * Скрипт валидации результатов миграции данных в MDM систему
 */

require_once __DIR__ . '/../../config.php';

class MigrationValidator {
    private $db;
    private $logFile;
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->logFile = __DIR__ . '/logs/validation_' . date('Y-m-d_H-i-s') . '.log';
        $this->createLogDirectory();
    }
    
    private function createLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    /**
     * Проверка целостности данных
     */
    public function validateDataIntegrity() {
        $this->log("=== Проверка целостности данных ===");
        
        $issues = [];
        
        // 1. Проверяем уникальность Master ID
        $sql = "
            SELECT master_id, COUNT(*) as count 
            FROM master_products 
            GROUP BY master_id 
            HAVING count > 1
        ";
        $stmt = $this->db->query($sql);
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($duplicates)) {
            $issues[] = "Найдены дублирующиеся Master ID: " . count($duplicates);
            foreach ($duplicates as $dup) {
                $this->log("Дублирующийся Master ID: " . $dup['master_id'] . " (количество: " . $dup['count'] . ")");
            }
        } else {
            $this->log("✓ Все Master ID уникальны");
        }
        
        // 2. Проверяем связи SKU mapping
        $sql = "
            SELECT COUNT(*) as orphaned_skus
            FROM sku_mapping sm
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE mp.master_id IS NULL
        ";
        $stmt = $this->db->query($sql);
        $orphanedSkus = $stmt->fetchColumn();
        
        if ($orphanedSkus > 0) {
            $issues[] = "Найдены SKU без связанных мастер-продуктов: $orphanedSkus";
        } else {
            $this->log("✓ Все SKU связаны с мастер-продуктами");
        }
        
        // 3. Проверяем уникальность внешних SKU по источникам
        $sql = "
            SELECT source, external_sku, COUNT(*) as count
            FROM sku_mapping
            GROUP BY source, external_sku
            HAVING count > 1
        ";
        $stmt = $this->db->query($sql);
        $duplicateSkus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($duplicateSkus)) {
            $issues[] = "Найдены дублирующиеся внешние SKU: " . count($duplicateSkus);
            foreach ($duplicateSkus as $dup) {
                $this->log("Дублирующийся SKU: " . $dup['external_sku'] . " в источнике " . $dup['source']);
            }
        } else {
            $this->log("✓ Все внешние SKU уникальны в рамках источников");
        }
        
        return $issues;
    }
    
    /**
     * Проверка качества данных
     */
    public function validateDataQuality() {
        $this->log("=== Проверка качества данных ===");
        
        $qualityIssues = [];
        
        // 1. Проверяем пустые названия
        $sql = "SELECT COUNT(*) FROM master_products WHERE canonical_name = '' OR canonical_name IS NULL";
        $emptyNames = $this->db->query($sql)->fetchColumn();
        
        if ($emptyNames > 0) {
            $qualityIssues[] = "Мастер-продукты с пустыми названиями: $emptyNames";
        } else {
            $this->log("✓ Все мастер-продукты имеют названия");
        }
        
        // 2. Проверяем товары с "Неизвестный бренд"
        $sql = "SELECT COUNT(*) FROM master_products WHERE canonical_brand = 'Неизвестный бренд'";
        $unknownBrands = $this->db->query($sql)->fetchColumn();
        
        $this->log("Товары с неизвестным брендом: $unknownBrands");
        if ($unknownBrands > 0) {
            $qualityIssues[] = "Товары с неизвестным брендом: $unknownBrands";
        }
        
        // 3. Проверяем товары с "Без категории"
        $sql = "SELECT COUNT(*) FROM master_products WHERE canonical_category = 'Без категории'";
        $noCategory = $this->db->query($sql)->fetchColumn();
        
        $this->log("Товары без категории: $noCategory");
        if ($noCategory > 0) {
            $qualityIssues[] = "Товары без категории: $noCategory";
        }
        
        // 4. Проверяем очень короткие названия (менее 5 символов)
        $sql = "SELECT COUNT(*) FROM master_products WHERE LENGTH(canonical_name) < 5";
        $shortNames = $this->db->query($sql)->fetchColumn();
        
        if ($shortNames > 0) {
            $qualityIssues[] = "Товары с очень короткими названиями: $shortNames";
        }
        
        // 5. Проверяем потенциальные дубликаты по названию и бренду
        $sql = "
            SELECT canonical_name, canonical_brand, COUNT(*) as count
            FROM master_products
            GROUP BY canonical_name, canonical_brand
            HAVING count > 1
        ";
        $stmt = $this->db->query($sql);
        $potentialDuplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($potentialDuplicates)) {
            $qualityIssues[] = "Потенциальные дубликаты по названию и бренду: " . count($potentialDuplicates);
            foreach ($potentialDuplicates as $dup) {
                $this->log("Потенциальный дубликат: " . $dup['canonical_name'] . " (" . $dup['canonical_brand'] . ") - " . $dup['count'] . " раз");
            }
        } else {
            $this->log("✓ Дубликаты по названию и бренду не найдены");
        }
        
        return $qualityIssues;
    }
    
    /**
     * Проверка покрытия данных
     */
    public function validateDataCoverage() {
        $this->log("=== Проверка покрытия данных ===");
        
        $coverage = [];
        
        // Общая статистика
        $sql = "SELECT COUNT(*) FROM master_products";
        $totalMasterProducts = $this->db->query($sql)->fetchColumn();
        
        $sql = "SELECT COUNT(*) FROM sku_mapping";
        $totalSkuMappings = $this->db->query($sql)->fetchColumn();
        
        $this->log("Всего мастер-продуктов: $totalMasterProducts");
        $this->log("Всего SKU связей: $totalSkuMappings");
        
        // Покрытие по источникам
        $sql = "
            SELECT 
                source,
                COUNT(*) as sku_count,
                COUNT(DISTINCT master_id) as unique_masters
            FROM sku_mapping
            GROUP BY source
        ";
        $stmt = $this->db->query($sql);
        $sourceStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->log("Покрытие по источникам:");
        foreach ($sourceStats as $stat) {
            $this->log("- {$stat['source']}: {$stat['sku_count']} SKU, {$stat['unique_masters']} уникальных мастер-продуктов");
        }
        
        // Проверяем товары без связей
        $sql = "
            SELECT COUNT(*) as orphaned_masters
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE sm.master_id IS NULL
        ";
        $orphanedMasters = $this->db->query($sql)->fetchColumn();
        
        if ($orphanedMasters > 0) {
            $coverage[] = "Мастер-продукты без SKU связей: $orphanedMasters";
        } else {
            $this->log("✓ Все мастер-продукты имеют SKU связи");
        }
        
        return [
            'total_masters' => $totalMasterProducts,
            'total_skus' => $totalSkuMappings,
            'source_stats' => $sourceStats,
            'issues' => $coverage
        ];
    }
    
    /**
     * Проверка производительности
     */
    public function validatePerformance() {
        $this->log("=== Проверка производительности ===");
        
        $performanceIssues = [];
        
        // Тест запроса поиска мастер-продукта по SKU
        $startTime = microtime(true);
        
        $sql = "
            SELECT mp.*, sm.external_sku, sm.source
            FROM master_products mp
            JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE sm.external_sku = ?
            LIMIT 1
        ";
        
        // Берем случайный SKU для теста
        $randomSkuSql = "SELECT external_sku FROM sku_mapping ORDER BY RAND() LIMIT 1";
        $randomSku = $this->db->query($randomSkuSql)->fetchColumn();
        
        if ($randomSku) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$randomSku]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $queryTime = (microtime(true) - $startTime) * 1000; // в миллисекундах
            
            $this->log("Время запроса поиска по SKU: {$queryTime}ms");
            
            if ($queryTime > 200) {
                $performanceIssues[] = "Медленный запрос поиска по SKU: {$queryTime}ms";
            } else {
                $this->log("✓ Производительность поиска по SKU в норме");
            }
        }
        
        // Тест запроса получения всех товаров бренда
        $startTime = microtime(true);
        
        $sql = "SELECT COUNT(*) FROM master_products WHERE canonical_brand = ?";
        $randomBrandSql = "SELECT canonical_brand FROM master_products WHERE canonical_brand != 'Неизвестный бренд' ORDER BY RAND() LIMIT 1";
        $randomBrand = $this->db->query($randomBrandSql)->fetchColumn();
        
        if ($randomBrand) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$randomBrand]);
            $count = $stmt->fetchColumn();
            
            $queryTime = (microtime(true) - $startTime) * 1000;
            
            $this->log("Время запроса поиска по бренду: {$queryTime}ms (найдено товаров: $count)");
            
            if ($queryTime > 100) {
                $performanceIssues[] = "Медленный запрос поиска по бренду: {$queryTime}ms";
            } else {
                $this->log("✓ Производительность поиска по бренду в норме");
            }
        }
        
        return $performanceIssues;
    }
    
    /**
     * Создание отчета о валидации
     */
    public function createValidationReport($integrityIssues, $qualityIssues, $coverage, $performanceIssues) {
        $this->log("=== Создание отчета валидации ===");
        
        $report = [
            'validation_date' => date('Y-m-d H:i:s'),
            'data_integrity' => [
                'status' => empty($integrityIssues) ? 'PASS' : 'FAIL',
                'issues' => $integrityIssues
            ],
            'data_quality' => [
                'status' => empty($qualityIssues) ? 'PASS' : 'WARNING',
                'issues' => $qualityIssues
            ],
            'data_coverage' => [
                'total_masters' => $coverage['total_masters'],
                'total_skus' => $coverage['total_skus'],
                'source_stats' => $coverage['source_stats'],
                'issues' => $coverage['issues']
            ],
            'performance' => [
                'status' => empty($performanceIssues) ? 'PASS' : 'WARNING',
                'issues' => $performanceIssues
            ],
            'overall_status' => 'PASS'
        ];
        
        // Определяем общий статус
        if (!empty($integrityIssues)) {
            $report['overall_status'] = 'FAIL';
        } elseif (!empty($qualityIssues) || !empty($performanceIssues)) {
            $report['overall_status'] = 'WARNING';
        }
        
        // Сохраняем отчет
        $reportFile = __DIR__ . '/extracted-data/validation_report.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Отчет валидации сохранен: $reportFile");
        
        return $report;
    }
    
    /**
     * Основной метод валидации
     */
    public function validateMigration() {
        $this->log("=== Начинаем валидацию миграции ===");
        
        try {
            // Проверяем целостность данных
            $integrityIssues = $this->validateDataIntegrity();
            
            // Проверяем качество данных
            $qualityIssues = $this->validateDataQuality();
            
            // Проверяем покрытие данных
            $coverage = $this->validateDataCoverage();
            
            // Проверяем производительность
            $performanceIssues = $this->validatePerformance();
            
            // Создаем отчет
            $report = $this->createValidationReport($integrityIssues, $qualityIssues, $coverage, $performanceIssues);
            
            $this->log("=== Валидация миграции завершена ===");
            $this->log("Общий статус: " . $report['overall_status']);
            
            return $report;
            
        } catch (Exception $e) {
            $this->log("Критическая ошибка при валидации: " . $e->getMessage());
            throw $e;
        }
    }
}

// Запуск валидации
if (php_sapi_name() === 'cli') {
    try {
        $validator = new MigrationValidator();
        $report = $validator->validateMigration();
        
        echo "\n=== ОТЧЕТ О ВАЛИДАЦИИ МИГРАЦИИ ===\n";
        echo "Дата валидации: " . $report['validation_date'] . "\n";
        echo "Общий статус: " . $report['overall_status'] . "\n\n";
        
        echo "Целостность данных: " . $report['data_integrity']['status'] . "\n";
        if (!empty($report['data_integrity']['issues'])) {
            foreach ($report['data_integrity']['issues'] as $issue) {
                echo "  - $issue\n";
            }
        }
        
        echo "\nКачество данных: " . $report['data_quality']['status'] . "\n";
        if (!empty($report['data_quality']['issues'])) {
            foreach ($report['data_quality']['issues'] as $issue) {
                echo "  - $issue\n";
            }
        }
        
        echo "\nПокрытие данных:\n";
        echo "  - Всего мастер-продуктов: " . $report['data_coverage']['total_masters'] . "\n";
        echo "  - Всего SKU связей: " . $report['data_coverage']['total_skus'] . "\n";
        
        echo "\nПроизводительность: " . $report['performance']['status'] . "\n";
        if (!empty($report['performance']['issues'])) {
            foreach ($report['performance']['issues'] as $issue) {
                echo "  - $issue\n";
            }
        }
        
        if ($report['overall_status'] === 'FAIL') {
            echo "\n❌ ВАЛИДАЦИЯ НЕ ПРОЙДЕНА. Необходимо исправить критические ошибки.\n";
            exit(1);
        } elseif ($report['overall_status'] === 'WARNING') {
            echo "\n⚠️  ВАЛИДАЦИЯ ПРОЙДЕНА С ПРЕДУПРЕЖДЕНИЯМИ. Рекомендуется исправить найденные проблемы.\n";
        } else {
            echo "\n✅ ВАЛИДАЦИЯ УСПЕШНО ПРОЙДЕНА.\n";
        }
        
    } catch (Exception $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>