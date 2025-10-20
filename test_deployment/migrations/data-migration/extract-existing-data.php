<?php

/**
 * Скрипт извлечения существующих данных для миграции в MDM систему
 */

require_once __DIR__ . '/../../config.php';

class DataExtractor {
    private $db;
    private $logFile;
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->logFile = __DIR__ . '/logs/extraction_' . date('Y-m-d_H-i-s') . '.log';
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
     * Извлечение товаров из таблицы products
     */
    public function extractProducts() {
        $this->log("Начинаем извлечение товаров из таблицы products...");
        
        try {
            $sql = "
                SELECT 
                    id,
                    name,
                    brand,
                    category,
                    sku,
                    barcode,
                    description,
                    price,
                    created_at,
                    updated_at,
                    'internal' as source
                FROM products 
                WHERE status = 'active'
                ORDER BY id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $count = count($products);
            
            $this->log("Извлечено $count товаров из внутренней системы");
            
            // Сохраняем в JSON файл
            $outputFile = __DIR__ . '/extracted-data/internal_products.json';
            $this->ensureDirectoryExists(dirname($outputFile));
            file_put_contents($outputFile, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            return $products;
            
        } catch (Exception $e) {
            $this->log("Ошибка при извлечении товаров: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Извлечение данных Ozon
     */
    public function extractOzonData() {
        $this->log("Начинаем извлечение данных Ozon...");
        
        try {
            $sql = "
                SELECT 
                    offer_id,
                    product_id,
                    name,
                    category_id,
                    brand,
                    barcode,
                    price,
                    stock,
                    created_at,
                    updated_at,
                    'ozon' as source
                FROM ozon_products 
                WHERE status = 'active'
                ORDER BY product_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $count = count($products);
            
            $this->log("Извлечено $count товаров из Ozon");
            
            // Сохраняем в JSON файл
            $outputFile = __DIR__ . '/extracted-data/ozon_products.json';
            $this->ensureDirectoryExists(dirname($outputFile));
            file_put_contents($outputFile, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            return $products;
            
        } catch (Exception $e) {
            $this->log("Ошибка при извлечении данных Ozon: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Извлечение данных Wildberries
     */
    public function extractWildberriesData() {
        $this->log("Начинаем извлечение данных Wildberries...");
        
        try {
            $sql = "
                SELECT 
                    nm_id,
                    sku,
                    name,
                    brand,
                    subject,
                    price,
                    stock,
                    created_at,
                    updated_at,
                    'wildberries' as source
                FROM wb_products 
                WHERE status = 'active'
                ORDER BY nm_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $count = count($products);
            
            $this->log("Извлечено $count товаров из Wildberries");
            
            // Сохраняем в JSON файл
            $outputFile = __DIR__ . '/extracted-data/wb_products.json';
            $this->ensureDirectoryExists(dirname($outputFile));
            file_put_contents($outputFile, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            return $products;
            
        } catch (Exception $e) {
            $this->log("Ошибка при извлечении данных Wildberries: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Анализ качества данных
     */
    public function analyzeDataQuality($products, $source) {
        $this->log("Анализируем качество данных для источника: $source");
        
        $stats = [
            'total_products' => count($products),
            'empty_names' => 0,
            'empty_brands' => 0,
            'empty_categories' => 0,
            'duplicate_names' => 0,
            'unique_brands' => [],
            'unique_categories' => []
        ];
        
        $namesSeen = [];
        
        foreach ($products as $product) {
            // Проверяем пустые поля
            if (empty($product['name'])) {
                $stats['empty_names']++;
            }
            
            if (empty($product['brand'])) {
                $stats['empty_brands']++;
            }
            
            $category = $product['category'] ?? $product['subject'] ?? '';
            if (empty($category)) {
                $stats['empty_categories']++;
            }
            
            // Проверяем дубликаты названий
            $name = strtolower(trim($product['name']));
            if (isset($namesSeen[$name])) {
                $stats['duplicate_names']++;
            } else {
                $namesSeen[$name] = true;
            }
            
            // Собираем уникальные бренды и категории
            if (!empty($product['brand'])) {
                $stats['unique_brands'][$product['brand']] = true;
            }
            
            if (!empty($category)) {
                $stats['unique_categories'][$category] = true;
            }
        }
        
        $stats['unique_brands'] = array_keys($stats['unique_brands']);
        $stats['unique_categories'] = array_keys($stats['unique_categories']);
        
        // Сохраняем статистику
        $statsFile = __DIR__ . "/extracted-data/{$source}_stats.json";
        file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Статистика для $source:");
        $this->log("- Всего товаров: " . $stats['total_products']);
        $this->log("- Пустые названия: " . $stats['empty_names']);
        $this->log("- Пустые бренды: " . $stats['empty_brands']);
        $this->log("- Пустые категории: " . $stats['empty_categories']);
        $this->log("- Дубликаты названий: " . $stats['duplicate_names']);
        $this->log("- Уникальных брендов: " . count($stats['unique_brands']));
        $this->log("- Уникальных категорий: " . count($stats['unique_categories']));
        
        return $stats;
    }
    
    /**
     * Создание сводного отчета
     */
    public function createSummaryReport($allStats) {
        $this->log("Создаем сводный отчет...");
        
        $report = [
            'extraction_date' => date('Y-m-d H:i:s'),
            'sources' => $allStats,
            'totals' => [
                'total_products' => 0,
                'total_empty_names' => 0,
                'total_empty_brands' => 0,
                'total_empty_categories' => 0,
                'all_brands' => [],
                'all_categories' => []
            ]
        ];
        
        foreach ($allStats as $source => $stats) {
            $report['totals']['total_products'] += $stats['total_products'];
            $report['totals']['total_empty_names'] += $stats['empty_names'];
            $report['totals']['total_empty_brands'] += $stats['empty_brands'];
            $report['totals']['total_empty_categories'] += $stats['empty_categories'];
            
            $report['totals']['all_brands'] = array_merge(
                $report['totals']['all_brands'], 
                $stats['unique_brands']
            );
            
            $report['totals']['all_categories'] = array_merge(
                $report['totals']['all_categories'], 
                $stats['unique_categories']
            );
        }
        
        $report['totals']['all_brands'] = array_unique($report['totals']['all_brands']);
        $report['totals']['all_categories'] = array_unique($report['totals']['all_categories']);
        
        // Сохраняем отчет
        $reportFile = __DIR__ . '/extracted-data/summary_report.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Сводный отчет создан: $reportFile");
        
        return $report;
    }
    
    private function ensureDirectoryExists($directory) {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
    
    /**
     * Основной метод извлечения всех данных
     */
    public function extractAllData() {
        $this->log("=== Начинаем извлечение всех данных ===");
        
        $allStats = [];
        
        try {
            // Извлекаем данные из всех источников
            $internalProducts = $this->extractProducts();
            $allStats['internal'] = $this->analyzeDataQuality($internalProducts, 'internal');
            
            $ozonProducts = $this->extractOzonData();
            $allStats['ozon'] = $this->analyzeDataQuality($ozonProducts, 'ozon');
            
            $wbProducts = $this->extractWildberriesData();
            $allStats['wildberries'] = $this->analyzeDataQuality($wbProducts, 'wildberries');
            
            // Создаем сводный отчет
            $summaryReport = $this->createSummaryReport($allStats);
            
            $this->log("=== Извлечение данных завершено успешно ===");
            $this->log("Всего товаров извлечено: " . $summaryReport['totals']['total_products']);
            
            return $summaryReport;
            
        } catch (Exception $e) {
            $this->log("Критическая ошибка при извлечении данных: " . $e->getMessage());
            throw $e;
        }
    }
}

// Запуск извлечения данных
if (php_sapi_name() === 'cli') {
    try {
        $extractor = new DataExtractor();
        $report = $extractor->extractAllData();
        
        echo "\n=== ОТЧЕТ О ИЗВЛЕЧЕНИИ ДАННЫХ ===\n";
        echo "Дата: " . $report['extraction_date'] . "\n";
        echo "Всего товаров: " . $report['totals']['total_products'] . "\n";
        echo "Уникальных брендов: " . count($report['totals']['all_brands']) . "\n";
        echo "Уникальных категорий: " . count($report['totals']['all_categories']) . "\n";
        echo "\nДанные сохранены в директории: migrations/data-migration/extracted-data/\n";
        
    } catch (Exception $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>