<?php

/**
 * Скрипт исправления товаров без названий через MDM систему
 */

require_once __DIR__ . '/../config.php';

class ProductNameFixer {
    private $db;
    private $logFile;
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->logFile = __DIR__ . '/logs/product_name_fix_' . date('Y-m-d_H-i-s') . '.log';
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
     * Поиск товаров без названий или с некорректными названиями
     */
    public function findProblemsProducts() {
        $this->log("Поиск товаров с проблемными названиями...");
        
        // Ищем товары без названий или с названиями типа "Товар артикул XXX"
        $sql = "
            SELECT 
                id,
                master_id,
                sku_ozon as sku,
                product_name,
                name,
                brand,
                category,
                barcode
            FROM product_master 
            WHERE 
                (product_name IS NULL OR product_name = '' OR product_name LIKE 'Товар артикул%')
                AND (name IS NULL OR name = '' OR name LIKE 'Товар артикул%')
            ORDER BY id
        ";
        
        $stmt = $this->db->query($sql);
        $problemProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->log("Найдено " . count($problemProducts) . " товаров с проблемными названиями");
        
        return $problemProducts;
    }
    
    /**
     * Поиск названия товара в существующих данных по SKU
     */
    public function findProductNameInExistingData($sku) {
        // Ищем в таблице product_master другие товары с похожими данными
        $sql = "
            SELECT 
                product_name,
                name,
                brand,
                category
            FROM product_master 
            WHERE (sku_ozon = ? OR sku_wb = ?)
            AND (product_name IS NOT NULL AND product_name != '' AND product_name NOT LIKE 'Товар артикул%')
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$sku, $sku]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Поиск названия товара по штрихкоду
     */
    public function findProductNameByBarcode($barcode) {
        if (empty($barcode)) {
            return null;
        }
        
        // Ищем товары с тем же штрихкодом, но с названиями
        $sql = "
            SELECT 
                product_name,
                name,
                brand,
                category
            FROM product_master 
            WHERE barcode = ?
            AND (product_name IS NOT NULL AND product_name != '' AND product_name NOT LIKE 'Товар артикул%')
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$barcode]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Генерация названия товара на основе доступных данных
     */
    public function generateProductName($product) {
        $sku = $product['sku'];
        $brand = $product['brand'] ?? '';
        $category = $product['category'] ?? '';
        $barcode = $product['barcode'] ?? '';
        
        // Пытаемся найти в существующих данных по SKU
        $existingData = $this->findProductNameInExistingData($sku);
        if ($existingData && !empty($existingData['product_name'])) {
            return [
                'name' => $existingData['product_name'],
                'brand' => $existingData['brand'] ?: $brand,
                'source' => 'existing_data',
                'confidence' => 0.9
            ];
        }
        
        // Пытаемся найти по штрихкоду
        $barcodeData = $this->findProductNameByBarcode($barcode);
        if ($barcodeData && !empty($barcodeData['product_name'])) {
            return [
                'name' => $barcodeData['product_name'],
                'brand' => $barcodeData['brand'] ?: $brand,
                'source' => 'barcode_match',
                'confidence' => 0.8
            ];
        }
        
        // Генерируем название на основе доступных данных
        $generatedName = '';
        
        if (!empty($brand)) {
            $generatedName .= $brand . ' ';
        }
        
        if (!empty($category)) {
            $generatedName .= $category . ' ';
        }
        
        if (!empty($barcode)) {
            $generatedName .= 'штрихкод ' . $barcode;
        } else {
            $generatedName .= 'артикул ' . $sku;
        }
        
        return [
            'name' => trim($generatedName),
            'brand' => $brand,
            'source' => 'generated',
            'confidence' => 0.3
        ];
    }
    
    /**
     * Обновление названия товара
     */
    public function updateProductName($productId, $newName, $newBrand = null) {
        $sql = "UPDATE product_master SET product_name = ?, name = ?";
        $params = [$newName, $newName];
        
        if ($newBrand) {
            $sql .= ", brand = ?";
            $params[] = $newBrand;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $productId;
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Основной процесс исправления названий
     */
    public function fixProductNames() {
        $this->log("=== Начинаем исправление названий товаров ===");
        
        $problemProducts = $this->findProblemsProducts();
        
        if (empty($problemProducts)) {
            $this->log("Товары с проблемными названиями не найдены");
            return;
        }
        
        $fixed = 0;
        $failed = 0;
        
        foreach ($problemProducts as $product) {
            try {
                $this->log("Обрабатываем товар ID: {$product['id']}, SKU: {$product['sku']}");
                
                $nameData = $this->generateProductName($product);
                
                if ($this->updateProductName($product['id'], $nameData['name'], $nameData['brand'])) {
                    $this->log("✓ Обновлено: '{$nameData['name']}' (источник: {$nameData['source']}, уверенность: {$nameData['confidence']})");
                    $fixed++;
                } else {
                    $this->log("✗ Не удалось обновить товар ID: {$product['id']}");
                    $failed++;
                }
                
            } catch (Exception $e) {
                $this->log("✗ Ошибка при обработке товара ID: {$product['id']} - " . $e->getMessage());
                $failed++;
            }
        }
        
        $this->log("=== Исправление завершено ===");
        $this->log("Исправлено: $fixed товаров");
        $this->log("Ошибок: $failed");
        
        return [
            'total' => count($problemProducts),
            'fixed' => $fixed,
            'failed' => $failed
        ];
    }
    
    /**
     * Создание отчета о качестве данных
     */
    public function createDataQualityReport() {
        $this->log("Создаем отчет о качестве данных...");
        
        // Общая статистика товаров
        $totalProducts = $this->db->query("SELECT COUNT(*) FROM product_master")->fetchColumn();
        
        // Товары без названий
        $noNameProducts = $this->db->query("
            SELECT COUNT(*) FROM product_master 
            WHERE (product_name IS NULL OR product_name = '' OR product_name LIKE 'Товар артикул%')
            AND (name IS NULL OR name = '' OR name LIKE 'Товар артикул%')
        ")->fetchColumn();
        
        // Товары без брендов
        $noBrandProducts = $this->db->query("
            SELECT COUNT(*) FROM product_master 
            WHERE brand IS NULL OR brand = ''
        ")->fetchColumn();
        
        // Товары без категорий
        $noCategoryProducts = $this->db->query("
            SELECT COUNT(*) FROM product_master 
            WHERE category IS NULL OR category = ''
        ")->fetchColumn();
        
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_products' => $totalProducts,
            'quality_metrics' => [
                'products_without_names' => $noNameProducts,
                'products_without_brands' => $noBrandProducts,
                'products_without_categories' => $noCategoryProducts,
                'name_completeness' => round((($totalProducts - $noNameProducts) / $totalProducts) * 100, 2),
                'brand_completeness' => round((($totalProducts - $noBrandProducts) / $totalProducts) * 100, 2),
                'category_completeness' => round((($totalProducts - $noCategoryProducts) / $totalProducts) * 100, 2)
            ]
        ];
        
        $reportFile = __DIR__ . '/logs/data_quality_report_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Отчет о качестве данных сохранен: $reportFile");
        
        return $report;
    }
}

// Запуск исправления
if (php_sapi_name() === 'cli') {
    try {
        $fixer = new ProductNameFixer();
        
        // Создаем отчет до исправления
        echo "=== ОТЧЕТ О КАЧЕСТВЕ ДАННЫХ (ДО ИСПРАВЛЕНИЯ) ===\n";
        $beforeReport = $fixer->createDataQualityReport();
        echo "Всего товаров: " . $beforeReport['total_products'] . "\n";
        echo "Товары без названий: " . $beforeReport['quality_metrics']['products_without_names'] . "\n";
        echo "Полнота названий: " . $beforeReport['quality_metrics']['name_completeness'] . "%\n\n";
        
        // Исправляем названия
        $result = $fixer->fixProductNames();
        
        // Создаем отчет после исправления
        echo "\n=== ОТЧЕТ О КАЧЕСТВЕ ДАННЫХ (ПОСЛЕ ИСПРАВЛЕНИЯ) ===\n";
        $afterReport = $fixer->createDataQualityReport();
        echo "Всего товаров: " . $afterReport['total_products'] . "\n";
        echo "Товары без названий: " . $afterReport['quality_metrics']['products_without_names'] . "\n";
        echo "Полнота названий: " . $afterReport['quality_metrics']['name_completeness'] . "%\n";
        
        echo "\n=== РЕЗУЛЬТАТ ИСПРАВЛЕНИЯ ===\n";
        echo "Обработано товаров: " . $result['total'] . "\n";
        echo "Исправлено: " . $result['fixed'] . "\n";
        echo "Ошибок: " . $result['failed'] . "\n";
        
        if ($result['fixed'] > 0) {
            echo "\n✅ Исправление названий товаров завершено успешно!\n";
            echo "Рекомендуется обновить дашборд для отображения исправленных данных.\n";
        }
        
    } catch (Exception $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>