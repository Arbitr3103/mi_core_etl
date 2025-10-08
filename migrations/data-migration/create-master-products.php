<?php

/**
 * Скрипт создания мастер-данных товаров из извлеченных данных
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/classes/MatchingEngine.php';

class MasterProductCreator {
    private $db;
    private $matchingEngine;
    private $logFile;
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->matchingEngine = new MatchingEngine($this->db);
        $this->logFile = __DIR__ . '/logs/master_creation_' . date('Y-m-d_H-i-s') . '.log';
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
     * Загрузка извлеченных данных
     */
    private function loadExtractedData() {
        $this->log("Загружаем извлеченные данные...");
        
        $dataDir = __DIR__ . '/extracted-data/';
        $allProducts = [];
        
        $files = [
            'internal' => 'internal_products.json',
            'ozon' => 'ozon_products.json',
            'wildberries' => 'wb_products.json'
        ];
        
        foreach ($files as $source => $filename) {
            $filepath = $dataDir . $filename;
            if (file_exists($filepath)) {
                $data = json_decode(file_get_contents($filepath), true);
                $this->log("Загружено " . count($data) . " товаров из $source");
                $allProducts[$source] = $data;
            } else {
                $this->log("Файл не найден: $filepath");
            }
        }
        
        return $allProducts;
    }
    
    /**
     * Стандартизация названия товара
     */
    private function standardizeName($name) {
        // Удаляем лишние пробелы
        $name = trim(preg_replace('/\s+/', ' ', $name));
        
        // Приводим к правильному регистру
        $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        
        // Исправляем типичные ошибки
        $replacements = [
            'Кг' => 'кг',
            'Г' => 'г',
            'Мл' => 'мл',
            'Л' => 'л',
            'Шт' => 'шт',
            'См' => 'см',
            'М' => 'м'
        ];
        
        foreach ($replacements as $search => $replace) {
            $name = str_replace($search, $replace, $name);
        }
        
        return $name;
    }
    
    /**
     * Стандартизация бренда
     */
    private function standardizeBrand($brand) {
        if (empty($brand)) {
            return 'Неизвестный бренд';
        }
        
        $brand = trim($brand);
        
        // Приводим к правильному регистру
        $brand = mb_convert_case($brand, MB_CASE_TITLE, 'UTF-8');
        
        // Исправляем известные варианты написания брендов
        $brandMappings = [
            'этоново' => 'ЭТОНОВО',
            'Этоново' => 'ЭТОНОВО',
            'ETОНOVO' => 'ЭТОНОВО',
            'heinz' => 'Heinz',
            'HEINZ' => 'Heinz',
            'coca-cola' => 'Coca-Cola',
            'COCA-COLA' => 'Coca-Cola'
        ];
        
        $lowerBrand = mb_strtolower($brand);
        if (isset($brandMappings[$lowerBrand])) {
            return $brandMappings[$lowerBrand];
        }
        
        return $brand;
    }
    
    /**
     * Стандартизация категории
     */
    private function standardizeCategory($category) {
        if (empty($category)) {
            return 'Без категории';
        }
        
        $category = trim($category);
        
        // Приводим к правильному регистру
        $category = mb_convert_case($category, MB_CASE_TITLE, 'UTF-8');
        
        // Маппинг категорий
        $categoryMappings = [
            'смеси для выпечки' => 'Смеси для выпечки',
            'Смеси Для Выпечки' => 'Смеси для выпечки',
            'продукты питания' => 'Продукты питания',
            'Продукты Питания' => 'Продукты питания',
            'напитки' => 'Напитки',
            'Напитки' => 'Напитки'
        ];
        
        $lowerCategory = mb_strtolower($category);
        if (isset($categoryMappings[$lowerCategory])) {
            return $categoryMappings[$lowerCategory];
        }
        
        return $category;
    }
    
    /**
     * Генерация Master ID
     */
    private function generateMasterId() {
        return 'PROD_' . strtoupper(uniqid());
    }
    
    /**
     * Создание мастер-продукта из товара
     */
    private function createMasterProduct($product, $source) {
        $masterId = $this->generateMasterId();
        
        // Стандартизируем данные
        $canonicalName = $this->standardizeName($product['name']);
        $canonicalBrand = $this->standardizeBrand($product['brand'] ?? '');
        
        // Определяем категорию в зависимости от источника
        $category = '';
        if ($source === 'wildberries') {
            $category = $product['subject'] ?? '';
        } else {
            $category = $product['category'] ?? '';
        }
        $canonicalCategory = $this->standardizeCategory($category);
        
        // Создаем описание
        $description = $product['description'] ?? '';
        if (empty($description)) {
            $description = "Товар $canonicalName от бренда $canonicalBrand";
        }
        
        // Дополнительные атрибуты
        $attributes = [
            'source_created' => $source,
            'original_name' => $product['name'],
            'original_brand' => $product['brand'] ?? '',
            'original_category' => $category
        ];
        
        // Добавляем специфичные для источника атрибуты
        if ($source === 'ozon') {
            $attributes['ozon_product_id'] = $product['product_id'] ?? '';
            $attributes['ozon_category_id'] = $product['category_id'] ?? '';
        } elseif ($source === 'wildberries') {
            $attributes['wb_nm_id'] = $product['nm_id'] ?? '';
        }
        
        return [
            'master_id' => $masterId,
            'canonical_name' => $canonicalName,
            'canonical_brand' => $canonicalBrand,
            'canonical_category' => $canonicalCategory,
            'description' => $description,
            'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE),
            'status' => 'active'
        ];
    }
    
    /**
     * Поиск существующего мастер-продукта
     */
    private function findExistingMasterProduct($product, $source) {
        // Ищем по точному совпадению названия и бренда
        $canonicalName = $this->standardizeName($product['name']);
        $canonicalBrand = $this->standardizeBrand($product['brand'] ?? '');
        
        $sql = "
            SELECT master_id, canonical_name, canonical_brand 
            FROM master_products 
            WHERE canonical_name = ? AND canonical_brand = ?
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$canonicalName, $canonicalBrand]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Сохранение мастер-продукта в базу данных
     */
    private function saveMasterProduct($masterProduct) {
        $sql = "
            INSERT INTO master_products 
            (master_id, canonical_name, canonical_brand, canonical_category, description, attributes, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $masterProduct['master_id'],
            $masterProduct['canonical_name'],
            $masterProduct['canonical_brand'],
            $masterProduct['canonical_category'],
            $masterProduct['description'],
            $masterProduct['attributes'],
            $masterProduct['status']
        ]);
    }
    
    /**
     * Создание связи SKU с мастер-продуктом
     */
    private function createSkuMapping($masterId, $product, $source) {
        // Определяем внешний SKU в зависимости от источника
        $externalSku = '';
        $sourceName = $product['name'];
        $sourceBrand = $product['brand'] ?? '';
        $sourceCategory = '';
        
        switch ($source) {
            case 'internal':
                $externalSku = $product['sku'] ?? $product['id'];
                $sourceCategory = $product['category'] ?? '';
                break;
            case 'ozon':
                $externalSku = $product['offer_id'] ?? $product['product_id'];
                $sourceCategory = $product['category_id'] ?? '';
                break;
            case 'wildberries':
                $externalSku = $product['sku'] ?? $product['nm_id'];
                $sourceCategory = $product['subject'] ?? '';
                break;
        }
        
        $sql = "
            INSERT INTO sku_mapping 
            (master_id, external_sku, source, source_name, source_brand, source_category, confidence_score, verification_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $masterId,
            $externalSku,
            $source,
            $sourceName,
            $sourceBrand,
            $sourceCategory,
            1.0, // Точное совпадение при создании
            'auto'
        ]);
    }
    
    /**
     * Обработка всех товаров из источника
     */
    private function processSourceProducts($products, $source) {
        $this->log("Обрабатываем " . count($products) . " товаров из источника: $source");
        
        $stats = [
            'processed' => 0,
            'new_masters' => 0,
            'existing_masters' => 0,
            'errors' => 0
        ];
        
        foreach ($products as $product) {
            try {
                $stats['processed']++;
                
                // Ищем существующий мастер-продукт
                $existingMaster = $this->findExistingMasterProduct($product, $source);
                
                if ($existingMaster) {
                    // Используем существующий мастер-продукт
                    $masterId = $existingMaster['master_id'];
                    $stats['existing_masters']++;
                    $this->log("Найден существующий мастер-продукт: $masterId для товара: " . $product['name']);
                } else {
                    // Создаем новый мастер-продукт
                    $masterProduct = $this->createMasterProduct($product, $source);
                    
                    if ($this->saveMasterProduct($masterProduct)) {
                        $masterId = $masterProduct['master_id'];
                        $stats['new_masters']++;
                        $this->log("Создан новый мастер-продукт: $masterId для товара: " . $product['name']);
                    } else {
                        throw new Exception("Не удалось сохранить мастер-продукт");
                    }
                }
                
                // Создаем связь SKU
                if (!$this->createSkuMapping($masterId, $product, $source)) {
                    throw new Exception("Не удалось создать связь SKU");
                }
                
                // Выводим прогресс каждые 100 товаров
                if ($stats['processed'] % 100 === 0) {
                    $this->log("Обработано: " . $stats['processed'] . " товаров");
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log("Ошибка при обработке товара " . ($product['name'] ?? 'неизвестно') . ": " . $e->getMessage());
            }
        }
        
        return $stats;
    }
    
    /**
     * Основной метод создания мастер-данных
     */
    public function createMasterProducts() {
        $this->log("=== Начинаем создание мастер-данных ===");
        
        try {
            // Загружаем извлеченные данные
            $allProducts = $this->loadExtractedData();
            
            $totalStats = [
                'processed' => 0,
                'new_masters' => 0,
                'existing_masters' => 0,
                'errors' => 0
            ];
            
            // Обрабатываем каждый источник
            foreach ($allProducts as $source => $products) {
                $this->log("--- Обработка источника: $source ---");
                
                $sourceStats = $this->processSourceProducts($products, $source);
                
                // Суммируем статистику
                foreach ($sourceStats as $key => $value) {
                    $totalStats[$key] += $value;
                }
                
                $this->log("Статистика для $source:");
                $this->log("- Обработано: " . $sourceStats['processed']);
                $this->log("- Новых мастер-продуктов: " . $sourceStats['new_masters']);
                $this->log("- Использовано существующих: " . $sourceStats['existing_masters']);
                $this->log("- Ошибок: " . $sourceStats['errors']);
            }
            
            // Сохраняем итоговую статистику
            $statsFile = __DIR__ . '/extracted-data/master_creation_stats.json';
            file_put_contents($statsFile, json_encode($totalStats, JSON_PRETTY_PRINT));
            
            $this->log("=== Создание мастер-данных завершено ===");
            $this->log("Итоговая статистика:");
            $this->log("- Всего обработано товаров: " . $totalStats['processed']);
            $this->log("- Создано новых мастер-продуктов: " . $totalStats['new_masters']);
            $this->log("- Использовано существующих мастер-продуктов: " . $totalStats['existing_masters']);
            $this->log("- Ошибок: " . $totalStats['errors']);
            
            return $totalStats;
            
        } catch (Exception $e) {
            $this->log("Критическая ошибка при создании мастер-данных: " . $e->getMessage());
            throw $e;
        }
    }
}

// Запуск создания мастер-данных
if (php_sapi_name() === 'cli') {
    try {
        $creator = new MasterProductCreator();
        $stats = $creator->createMasterProducts();
        
        echo "\n=== ОТЧЕТ О СОЗДАНИИ МАСТЕР-ДАННЫХ ===\n";
        echo "Всего обработано товаров: " . $stats['processed'] . "\n";
        echo "Создано новых мастер-продуктов: " . $stats['new_masters'] . "\n";
        echo "Использовано существующих: " . $stats['existing_masters'] . "\n";
        echo "Ошибок: " . $stats['errors'] . "\n";
        
        if ($stats['errors'] > 0) {
            echo "\nВНИМАНИЕ: Обнаружены ошибки. Проверьте лог-файл для деталей.\n";
        }
        
    } catch (Exception $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>