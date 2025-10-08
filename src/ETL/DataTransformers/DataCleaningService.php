<?php

namespace MDM\ETL\DataTransformers;

use Exception;
use PDO;

/**
 * Сервис очистки данных
 * Предоставляет специализированные методы для очистки и стандартизации данных
 */
class DataCleaningService
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }
    
    /**
     * Массовая очистка данных из таблицы извлеченных данных
     * 
     * @param array $filters Фильтры для обработки
     * @return array Статистика очистки
     */
    public function cleanExtractedData(array $filters = []): array
    {
        $stats = [
            'processed' => 0,
            'cleaned' => 0,
            'errors' => 0,
            'start_time' => microtime(true)
        ];
        
        try {
            // Получаем данные для очистки
            $data = $this->getExtractedDataForCleaning($filters);
            
            $transformer = new ProductDataTransformer($this->pdo);
            
            foreach ($data as $item) {
                try {
                    $stats['processed']++;
                    
                    // Трансформируем данные
                    $cleanedItem = $transformer->transform($item);
                    
                    // Сохраняем очищенные данные
                    $this->updateExtractedData($item['id'], $cleanedItem);
                    
                    $stats['cleaned']++;
                    
                    // Логируем прогресс каждые 100 записей
                    if ($stats['processed'] % 100 === 0) {
                        $this->log('INFO', "Обработано записей: {$stats['processed']}");
                    }
                    
                } catch (Exception $e) {
                    $stats['errors']++;
                    
                    $this->log('ERROR', 'Ошибка очистки записи', [
                        'id' => $item['id'],
                        'sku' => $item['external_sku'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $stats['duration'] = microtime(true) - $stats['start_time'];
            
            $this->log('INFO', 'Массовая очистка данных завершена', $stats);
            
            return $stats;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка массовой очистки данных', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Очистка дублирующихся записей
     * 
     * @return array Статистика удаления дублей
     */
    public function removeDuplicates(): array
    {
        $stats = [
            'total_records' => 0,
            'duplicates_found' => 0,
            'duplicates_removed' => 0,
            'errors' => 0
        ];
        
        try {
            // Подсчитываем общее количество записей
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data");
            $stats['total_records'] = $stmt->fetchColumn();
            
            // Находим дубликаты по source + external_sku
            $duplicatesQuery = "
                SELECT source, external_sku, COUNT(*) as count, 
                       GROUP_CONCAT(id ORDER BY created_at DESC) as ids
                FROM etl_extracted_data 
                GROUP BY source, external_sku 
                HAVING COUNT(*) > 1
            ";
            
            $stmt = $this->pdo->query($duplicatesQuery);
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats['duplicates_found'] = count($duplicates);
            
            foreach ($duplicates as $duplicate) {
                try {
                    $ids = explode(',', $duplicate['ids']);
                    
                    // Оставляем самую новую запись, удаляем остальные
                    $keepId = array_shift($ids); // Первый ID - самый новый
                    
                    if (!empty($ids)) {
                        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                        $deleteStmt = $this->pdo->prepare("DELETE FROM etl_extracted_data WHERE id IN ($placeholders)");
                        $deleteStmt->execute($ids);
                        
                        $stats['duplicates_removed'] += count($ids);
                        
                        $this->log('INFO', 'Удалены дубликаты', [
                            'source' => $duplicate['source'],
                            'sku' => $duplicate['external_sku'],
                            'kept_id' => $keepId,
                            'removed_ids' => $ids
                        ]);
                    }
                    
                } catch (Exception $e) {
                    $stats['errors']++;
                    
                    $this->log('ERROR', 'Ошибка удаления дубликата', [
                        'duplicate' => $duplicate,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->log('INFO', 'Удаление дубликатов завершено', $stats);
            
            return $stats;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка удаления дубликатов', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Валидация качества данных
     * 
     * @return array Отчет о качестве данных
     */
    public function validateDataQuality(): array
    {
        $report = [
            'total_records' => 0,
            'quality_issues' => [],
            'quality_score' => 0,
            'recommendations' => []
        ];
        
        try {
            // Общее количество записей
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data");
            $report['total_records'] = $stmt->fetchColumn();
            
            if ($report['total_records'] === 0) {
                return $report;
            }
            
            // Проверяем различные аспекты качества данных
            $report['quality_issues'] = [
                'missing_names' => $this->checkMissingNames(),
                'missing_brands' => $this->checkMissingBrands(),
                'missing_categories' => $this->checkMissingCategories(),
                'invalid_prices' => $this->checkInvalidPrices(),
                'encoding_issues' => $this->checkEncodingIssues(),
                'duplicate_names' => $this->checkDuplicateNames(),
                'suspicious_data' => $this->checkSuspiciousData()
            ];
            
            // Рассчитываем общий балл качества
            $report['quality_score'] = $this->calculateQualityScore($report);
            
            // Генерируем рекомендации
            $report['recommendations'] = $this->generateRecommendations($report);
            
            $this->log('INFO', 'Валидация качества данных завершена', [
                'total_records' => $report['total_records'],
                'quality_score' => $report['quality_score']
            ]);
            
            return $report;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка валидации качества данных', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Стандартизация брендов
     * 
     * @return array Статистика стандартизации
     */
    public function standardizeBrands(): array
    {
        $stats = [
            'processed' => 0,
            'standardized' => 0,
            'new_mappings' => 0
        ];
        
        try {
            // Получаем уникальные бренды
            $stmt = $this->pdo->query("
                SELECT DISTINCT source_brand, COUNT(*) as count 
                FROM etl_extracted_data 
                WHERE source_brand IS NOT NULL AND source_brand != '' 
                GROUP BY source_brand 
                ORDER BY count DESC
            ");
            
            $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $brandMappings = $this->loadBrandMappings();
            
            foreach ($brands as $brandData) {
                $stats['processed']++;
                $originalBrand = $brandData['source_brand'];
                
                // Ищем стандартизированный вариант
                $standardBrand = $this->findStandardBrand($originalBrand, $brandMappings);
                
                if ($standardBrand && $standardBrand !== $originalBrand) {
                    // Обновляем все записи с этим брендом
                    $updateStmt = $this->pdo->prepare("
                        UPDATE etl_extracted_data 
                        SET source_brand = ? 
                        WHERE source_brand = ?
                    ");
                    $updateStmt->execute([$standardBrand, $originalBrand]);
                    
                    $stats['standardized']++;
                    
                    $this->log('INFO', 'Стандартизирован бренд', [
                        'original' => $originalBrand,
                        'standard' => $standardBrand,
                        'records_updated' => $brandData['count']
                    ]);
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка стандартизации брендов', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Получение данных для очистки
     * 
     * @param array $filters Фильтры
     * @return array Данные для очистки
     */
    private function getExtractedDataForCleaning(array $filters): array
    {
        $sql = "SELECT * FROM etl_extracted_data WHERE 1=1";
        $params = [];
        
        if (!empty($filters['source'])) {
            $sql .= " AND source = ?";
            $params[] = $filters['source'];
        }
        
        if (!empty($filters['updated_after'])) {
            $sql .= " AND updated_at >= ?";
            $params[] = $filters['updated_after'];
        }
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Обновление очищенных данных
     * 
     * @param int $id ID записи
     * @param array $cleanedData Очищенные данные
     */
    private function updateExtractedData(int $id, array $cleanedData): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE etl_extracted_data 
            SET source_name = ?, 
                source_brand = ?, 
                source_category = ?, 
                price = ?, 
                description = ?, 
                attributes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $cleanedData['source_name'],
            $cleanedData['source_brand'],
            $cleanedData['source_category'],
            $cleanedData['price'],
            $cleanedData['description'],
            json_encode($cleanedData['attributes'] ?? [], JSON_UNESCAPED_UNICODE),
            $id
        ]);
    }
    
    /**
     * Проверка отсутствующих названий
     */
    private function checkMissingNames(): array
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count, 
                   ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM etl_extracted_data), 2) as percentage
            FROM etl_extracted_data 
            WHERE source_name IS NULL OR source_name = ''
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверка отсутствующих брендов
     */
    private function checkMissingBrands(): array
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count, 
                   ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM etl_extracted_data), 2) as percentage
            FROM etl_extracted_data 
            WHERE source_brand IS NULL OR source_brand = '' OR source_brand = 'Неизвестный бренд'
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверка отсутствующих категорий
     */
    private function checkMissingCategories(): array
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count, 
                   ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM etl_extracted_data), 2) as percentage
            FROM etl_extracted_data 
            WHERE source_category IS NULL OR source_category = '' OR source_category = 'Без категории'
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверка некорректных цен
     */
    private function checkInvalidPrices(): array
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count, 
                   ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM etl_extracted_data), 2) as percentage
            FROM etl_extracted_data 
            WHERE price IS NULL OR price <= 0 OR price > 1000000
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверка проблем с кодировкой
     */
    private function checkEncodingIssues(): array
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count, 
                   ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM etl_extracted_data), 2) as percentage
            FROM etl_extracted_data 
            WHERE source_name LIKE '%Ð%' OR source_name LIKE '%Ñ%' OR source_name LIKE '%â%'
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверка дублирующихся названий
     */
    private function checkDuplicateNames(): array
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count
            FROM (
                SELECT source_name, COUNT(*) as cnt 
                FROM etl_extracted_data 
                WHERE source_name IS NOT NULL AND source_name != ''
                GROUP BY source_name 
                HAVING cnt > 1
            ) as duplicates
        ");
        
        $count = $stmt->fetchColumn();
        
        return [
            'count' => $count,
            'percentage' => $count > 0 ? round($count * 100.0 / $this->getTotalRecords(), 2) : 0
        ];
    }
    
    /**
     * Проверка подозрительных данных
     */
    private function checkSuspiciousData(): array
    {
        $issues = [];
        
        // Слишком короткие названия
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM etl_extracted_data 
            WHERE LENGTH(source_name) < 3
        ");
        $issues['short_names'] = $stmt->fetchColumn();
        
        // Слишком длинные названия
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM etl_extracted_data 
            WHERE LENGTH(source_name) > 200
        ");
        $issues['long_names'] = $stmt->fetchColumn();
        
        // Названия только из цифр
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM etl_extracted_data 
            WHERE source_name REGEXP '^[0-9]+$'
        ");
        $issues['numeric_names'] = $stmt->fetchColumn();
        
        return $issues;
    }
    
    /**
     * Расчет общего балла качества данных
     */
    private function calculateQualityScore(array $report): float
    {
        $totalRecords = $report['total_records'];
        
        if ($totalRecords === 0) {
            return 0;
        }
        
        $issues = $report['quality_issues'];
        
        // Веса для различных проблем качества
        $weights = [
            'missing_names' => 0.3,
            'missing_brands' => 0.2,
            'missing_categories' => 0.2,
            'invalid_prices' => 0.15,
            'encoding_issues' => 0.1,
            'duplicate_names' => 0.05
        ];
        
        $totalDeduction = 0;
        
        foreach ($weights as $issue => $weight) {
            if (isset($issues[$issue]['percentage'])) {
                $totalDeduction += $issues[$issue]['percentage'] * $weight;
            }
        }
        
        return max(0, 100 - $totalDeduction);
    }
    
    /**
     * Генерация рекомендаций по улучшению качества данных
     */
    private function generateRecommendations(array $report): array
    {
        $recommendations = [];
        $issues = $report['quality_issues'];
        
        if ($issues['missing_names']['percentage'] > 5) {
            $recommendations[] = "Высокий процент товаров без названий ({$issues['missing_names']['percentage']}%). Проверьте настройки извлечения данных.";
        }
        
        if ($issues['missing_brands']['percentage'] > 20) {
            $recommendations[] = "Много товаров без брендов ({$issues['missing_brands']['percentage']}%). Рассмотрите возможность извлечения брендов из названий.";
        }
        
        if ($issues['missing_categories']['percentage'] > 30) {
            $recommendations[] = "Много товаров без категорий ({$issues['missing_categories']['percentage']}%). Настройте автоматическое определение категорий.";
        }
        
        if ($issues['invalid_prices']['percentage'] > 10) {
            $recommendations[] = "Проблемы с ценами у {$issues['invalid_prices']['percentage']}% товаров. Проверьте логику извлечения цен.";
        }
        
        if ($issues['encoding_issues']['percentage'] > 1) {
            $recommendations[] = "Обнаружены проблемы с кодировкой у {$issues['encoding_issues']['percentage']}% товаров. Запустите исправление кодировки.";
        }
        
        if ($report['quality_score'] < 80) {
            $recommendations[] = "Общий балл качества данных низкий ({$report['quality_score']}%). Рекомендуется запустить полную очистку данных.";
        }
        
        return $recommendations;
    }
    
    /**
     * Загрузка маппингов брендов
     */
    private function loadBrandMappings(): array
    {
        // В реальной системе это можно загружать из базы данных
        return [
            'apple' => 'Apple',
            'эппл' => 'Apple',
            'samsung' => 'Samsung',
            'самсунг' => 'Samsung',
            'xiaomi' => 'Xiaomi',
            'сяоми' => 'Xiaomi',
            'huawei' => 'Huawei',
            'хуавей' => 'Huawei'
        ];
    }
    
    /**
     * Поиск стандартного бренда
     */
    private function findStandardBrand(string $brand, array $mappings): ?string
    {
        $brandLower = mb_strtolower($brand, 'UTF-8');
        
        // Прямое соответствие
        if (isset($mappings[$brandLower])) {
            return $mappings[$brandLower];
        }
        
        // Поиск по частичному совпадению
        foreach ($mappings as $pattern => $standardBrand) {
            if (stripos($brand, $pattern) !== false) {
                return $standardBrand;
            }
        }
        
        return null;
    }
    
    /**
     * Получение общего количества записей
     */
    private function getTotalRecords(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data");
        return $stmt->fetchColumn();
    }
    
    /**
     * Логирование сообщений
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[Data Cleaning Service] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('data_cleaning', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
    }
}