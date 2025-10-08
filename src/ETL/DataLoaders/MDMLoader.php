<?php

namespace MDM\ETL\DataLoaders;

use Exception;
use PDO;

/**
 * Загрузчик данных в MDM систему
 * Обрабатывает сопоставление и загрузку очищенных данных в мастер-таблицы
 */
class MDMLoader
{
    private PDO $pdo;
    private array $config;
    private MatchingEngine $matchingEngine;
    private MasterDataManager $masterDataManager;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
        
        $this->matchingEngine = new MatchingEngine($pdo, $config['matching'] ?? []);
        $this->masterDataManager = new MasterDataManager($pdo, $config['master_data'] ?? []);
    }
    
    /**
     * Загрузка данных в MDM систему
     * 
     * @param array $filters Фильтры для обработки данных
     * @return array Статистика загрузки
     */
    public function loadToMDM(array $filters = []): array
    {
        $stats = [
            'processed' => 0,
            'matched' => 0,
            'new_masters' => 0,
            'updated_masters' => 0,
            'errors' => 0,
            'start_time' => microtime(true)
        ];
        
        try {
            $this->log('INFO', 'Начало загрузки данных в MDM систему', $filters);
            
            // Получаем данные для загрузки
            $extractedData = $this->getExtractedDataForLoading($filters);
            
            $this->pdo->beginTransaction();
            
            foreach ($extractedData as $item) {
                try {
                    $stats['processed']++;
                    
                    // Пытаемся найти соответствие в мастер-данных
                    $matchResult = $this->matchingEngine->findMatches($item);
                    
                    if ($matchResult['best_match']) {
                        // Найдено соответствие - обновляем существующий Master ID
                        $this->updateExistingMaster($matchResult['best_match'], $item);
                        $stats['matched']++;
                        $stats['updated_masters']++;
                        
                        $this->log('DEBUG', 'Товар сопоставлен с существующим Master ID', [
                            'sku' => $item['external_sku'],
                            'master_id' => $matchResult['best_match']['master_id'],
                            'confidence' => $matchResult['confidence_score']
                        ]);
                        
                    } else {
                        // Соответствие не найдено - создаем новый Master ID
                        $masterId = $this->createNewMaster($item);
                        $stats['new_masters']++;
                        
                        $this->log('DEBUG', 'Создан новый Master ID', [
                            'sku' => $item['external_sku'],
                            'master_id' => $masterId
                        ]);
                    }
                    
                    // Создаем или обновляем маппинг SKU
                    $this->createOrUpdateSkuMapping($item, $matchResult);
                    
                    // Отмечаем запись как обработанную
                    $this->markAsProcessed($item['id']);
                    
                    // Логируем прогресс каждые 100 записей
                    if ($stats['processed'] % 100 === 0) {
                        $this->log('INFO', "Обработано записей: {$stats['processed']}");
                    }
                    
                } catch (Exception $e) {
                    $stats['errors']++;
                    
                    $this->log('ERROR', 'Ошибка загрузки записи в MDM', [
                        'id' => $item['id'],
                        'sku' => $item['external_sku'],
                        'error' => $e->getMessage()
                    ]);
                    
                    // Записываем ошибку в очередь повторных попыток
                    $this->addToRetryQueue($item, $e->getMessage());
                }
            }
            
            $this->pdo->commit();
            
            $stats['duration'] = microtime(true) - $stats['start_time'];
            
            $this->log('INFO', 'Загрузка в MDM систему завершена', $stats);
            
            return $stats;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            $this->log('ERROR', 'Критическая ошибка загрузки в MDM', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Обработка записей, требующих ручной верификации
     * 
     * @return array Список записей для ручной проверки
     */
    public function getPendingVerification(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT sm.*, mp.canonical_name, mp.canonical_brand, mp.canonical_category
                FROM sku_mapping sm
                LEFT JOIN master_products mp ON sm.master_id = mp.master_id
                WHERE sm.verification_status = 'pending'
                ORDER BY sm.confidence_score DESC, sm.created_at ASC
                LIMIT 100
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения записей для верификации', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Подтверждение сопоставления
     * 
     * @param int $mappingId ID маппинга
     * @param bool $approved Подтверждено ли сопоставление
     * @param string|null $newMasterId Новый Master ID если сопоставление отклонено
     */
    public function confirmMapping(int $mappingId, bool $approved, ?string $newMasterId = null): void
    {
        try {
            $this->pdo->beginTransaction();
            
            if ($approved) {
                // Подтверждаем сопоставление
                $stmt = $this->pdo->prepare("
                    UPDATE sku_mapping 
                    SET verification_status = 'manual', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$mappingId]);
                
                $this->log('INFO', 'Сопоставление подтверждено', ['mapping_id' => $mappingId]);
                
            } else {
                // Отклоняем сопоставление
                if ($newMasterId) {
                    // Обновляем на новый Master ID
                    $stmt = $this->pdo->prepare("
                        UPDATE sku_mapping 
                        SET master_id = ?, verification_status = 'manual', updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$newMasterId, $mappingId]);
                } else {
                    // Создаем новый Master ID
                    $mappingData = $this->getMappingData($mappingId);
                    $newMasterId = $this->createNewMasterFromMapping($mappingData);
                    
                    $stmt = $this->pdo->prepare("
                        UPDATE sku_mapping 
                        SET master_id = ?, verification_status = 'manual', updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$newMasterId, $mappingId]);
                }
                
                $this->log('INFO', 'Сопоставление отклонено и исправлено', [
                    'mapping_id' => $mappingId,
                    'new_master_id' => $newMasterId
                ]);
            }
            
            $this->pdo->commit();
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            $this->log('ERROR', 'Ошибка подтверждения сопоставления', [
                'mapping_id' => $mappingId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Получение статистики загрузки
     * 
     * @return array Статистика
     */
    public function getLoadingStats(): array
    {
        try {
            $stats = [];
            
            // Общая статистика по маппингам
            $stmt = $this->pdo->query("
                SELECT 
                    verification_status,
                    COUNT(*) as count,
                    ROUND(AVG(confidence_score), 2) as avg_confidence
                FROM sku_mapping 
                GROUP BY verification_status
            ");
            $stats['mappings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Статистика по источникам
            $stmt = $this->pdo->query("
                SELECT 
                    source,
                    COUNT(*) as total_mappings,
                    COUNT(DISTINCT master_id) as unique_masters,
                    ROUND(AVG(confidence_score), 2) as avg_confidence
                FROM sku_mapping 
                GROUP BY source
            ");
            $stats['by_source'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Статистика по мастер-данным
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_masters,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_masters,
                    COUNT(CASE WHEN canonical_brand = 'Неизвестный бренд' THEN 1 END) as unknown_brands,
                    COUNT(CASE WHEN canonical_category = 'Без категории' THEN 1 END) as no_category
                FROM master_products
            ");
            $stats['masters'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Статистика качества данных
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN confidence_score >= 0.9 THEN 1 END) as high_confidence,
                    COUNT(CASE WHEN confidence_score BETWEEN 0.7 AND 0.89 THEN 1 END) as medium_confidence,
                    COUNT(CASE WHEN confidence_score < 0.7 THEN 1 END) as low_confidence
                FROM sku_mapping
            ");
            $stats['quality'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения статистики загрузки', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Получение данных для загрузки из таблицы извлеченных данных
     * 
     * @param array $filters Фильтры
     * @return array Данные для загрузки
     */
    private function getExtractedDataForLoading(array $filters): array
    {
        $sql = "
            SELECT ed.* 
            FROM etl_extracted_data ed
            LEFT JOIN sku_mapping sm ON ed.source = sm.source AND ed.external_sku = sm.external_sku
            WHERE sm.id IS NULL
        ";
        $params = [];
        
        if (!empty($filters['source'])) {
            $sql .= " AND ed.source = ?";
            $params[] = $filters['source'];
        }
        
        if (!empty($filters['updated_after'])) {
            $sql .= " AND ed.updated_at >= ?";
            $params[] = $filters['updated_after'];
        }
        
        $sql .= " ORDER BY ed.created_at ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Обновление существующего мастер-продукта
     * 
     * @param array $masterData Данные мастер-продукта
     * @param array $newData Новые данные
     */
    private function updateExistingMaster(array $masterData, array $newData): void
    {
        // Обогащаем мастер-данные новой информацией
        $updatedData = $this->masterDataManager->enrichMasterData($masterData, $newData);
        
        if ($updatedData) {
            $stmt = $this->pdo->prepare("
                UPDATE master_products 
                SET canonical_name = ?, 
                    canonical_brand = ?, 
                    canonical_category = ?, 
                    description = ?, 
                    attributes = ?,
                    updated_at = NOW()
                WHERE master_id = ?
            ");
            
            $stmt->execute([
                $updatedData['canonical_name'],
                $updatedData['canonical_brand'],
                $updatedData['canonical_category'],
                $updatedData['description'],
                json_encode($updatedData['attributes'], JSON_UNESCAPED_UNICODE),
                $masterData['master_id']
            ]);
        }
    }
    
    /**
     * Создание нового мастер-продукта
     * 
     * @param array $data Данные товара
     * @return string ID нового мастер-продукта
     */
    private function createNewMaster(array $data): string
    {
        $masterId = $this->masterDataManager->generateMasterId();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO master_products 
            (master_id, canonical_name, canonical_brand, canonical_category, description, attributes, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        
        $stmt->execute([
            $masterId,
            $data['source_name'],
            $data['source_brand'],
            $data['source_category'],
            $data['description'],
            json_encode($data['attributes'] ?? [], JSON_UNESCAPED_UNICODE)
        ]);
        
        return $masterId;
    }
    
    /**
     * Создание или обновление маппинга SKU
     * 
     * @param array $data Данные товара
     * @param array $matchResult Результат сопоставления
     */
    private function createOrUpdateSkuMapping(array $data, array $matchResult): void
    {
        $masterId = $matchResult['best_match']['master_id'] ?? $this->getLastCreatedMasterId();
        $confidenceScore = $matchResult['confidence_score'] ?? 1.0;
        $verificationStatus = $this->determineVerificationStatus($confidenceScore);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO sku_mapping 
            (master_id, external_sku, source, source_name, source_brand, source_category, 
             confidence_score, verification_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            master_id = VALUES(master_id),
            source_name = VALUES(source_name),
            source_brand = VALUES(source_brand),
            source_category = VALUES(source_category),
            confidence_score = VALUES(confidence_score),
            verification_status = VALUES(verification_status),
            updated_at = NOW()
        ");
        
        $stmt->execute([
            $masterId,
            $data['external_sku'],
            $data['source'],
            $data['source_name'],
            $data['source_brand'],
            $data['source_category'],
            $confidenceScore,
            $verificationStatus
        ]);
    }
    
    /**
     * Определение статуса верификации на основе уверенности сопоставления
     * 
     * @param float $confidenceScore Балл уверенности
     * @return string Статус верификации
     */
    private function determineVerificationStatus(float $confidenceScore): string
    {
        if ($confidenceScore >= 0.9) {
            return 'auto';
        } elseif ($confidenceScore >= 0.7) {
            return 'pending';
        } else {
            return 'pending';
        }
    }
    
    /**
     * Получение ID последнего созданного мастер-продукта
     * 
     * @return string Master ID
     */
    private function getLastCreatedMasterId(): string
    {
        $stmt = $this->pdo->query("
            SELECT master_id 
            FROM master_products 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        return $stmt->fetchColumn();
    }
    
    /**
     * Отметка записи как обработанной
     * 
     * @param int $id ID записи
     */
    private function markAsProcessed(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE etl_extracted_data 
            SET updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
    
    /**
     * Добавление записи в очередь повторных попыток
     * 
     * @param array $data Данные записи
     * @param string $errorMessage Сообщение об ошибке
     */
    private function addToRetryQueue(array $data, string $errorMessage): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_retry_queue 
                (source, operation_type, operation_data, error_message, next_retry_at)
                VALUES (?, 'load', ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ");
            
            $stmt->execute([
                $data['source'],
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $errorMessage
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка добавления в очередь повторных попыток', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение данных маппинга
     * 
     * @param int $mappingId ID маппинга
     * @return array Данные маппинга
     */
    private function getMappingData(int $mappingId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM sku_mapping WHERE id = ?
        ");
        $stmt->execute([$mappingId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Создание нового мастер-продукта из данных маппинга
     * 
     * @param array $mappingData Данные маппинга
     * @return string ID нового мастер-продукта
     */
    private function createNewMasterFromMapping(array $mappingData): string
    {
        $masterId = $this->masterDataManager->generateMasterId();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO master_products 
            (master_id, canonical_name, canonical_brand, canonical_category, status)
            VALUES (?, ?, ?, ?, 'active')
        ");
        
        $stmt->execute([
            $masterId,
            $mappingData['source_name'],
            $mappingData['source_brand'],
            $mappingData['source_category']
        ]);
        
        return $masterId;
    }
    
    /**
     * Логирование операций загрузки
     * 
     * @param string $level Уровень лога
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[MDM Loader] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('mdm_loader', ?, ?, ?, NOW())
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

/**
 * Движок сопоставления товаров
 */
class MatchingEngine
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }
    
    /**
     * Поиск соответствий для товара
     * 
     * @param array $item Данные товара
     * @return array Результат сопоставления
     */
    public function findMatches(array $item): array
    {
        $candidates = $this->findCandidates($item);
        
        if (empty($candidates)) {
            return [
                'best_match' => null,
                'confidence_score' => 0,
                'candidates' => []
            ];
        }
        
        // Рассчитываем баллы для каждого кандидата
        $scoredCandidates = [];
        foreach ($candidates as $candidate) {
            $score = $this->calculateMatchScore($item, $candidate);
            $scoredCandidates[] = [
                'candidate' => $candidate,
                'score' => $score
            ];
        }
        
        // Сортируем по баллу
        usort($scoredCandidates, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $bestMatch = $scoredCandidates[0] ?? null;
        $confidenceThreshold = $this->config['confidence_threshold'] ?? 0.7;
        
        return [
            'best_match' => $bestMatch && $bestMatch['score'] >= $confidenceThreshold ? $bestMatch['candidate'] : null,
            'confidence_score' => $bestMatch ? $bestMatch['score'] : 0,
            'candidates' => array_slice($scoredCandidates, 0, 5) // Топ 5 кандидатов
        ];
    }
    
    /**
     * Поиск кандидатов для сопоставления
     * 
     * @param array $item Данные товара
     * @return array Кандидаты
     */
    private function findCandidates(array $item): array
    {
        $candidates = [];
        
        // Поиск по точному совпадению названия
        $exactMatches = $this->findByExactName($item['source_name']);
        $candidates = array_merge($candidates, $exactMatches);
        
        // Поиск по бренду и категории
        if (!empty($item['source_brand'])) {
            $brandMatches = $this->findByBrandAndCategory($item['source_brand'], $item['source_category']);
            $candidates = array_merge($candidates, $brandMatches);
        }
        
        // Поиск по похожим названиям
        $similarMatches = $this->findBySimilarName($item['source_name']);
        $candidates = array_merge($candidates, $similarMatches);
        
        // Удаляем дубликаты
        $uniqueCandidates = [];
        $seenIds = [];
        
        foreach ($candidates as $candidate) {
            if (!in_array($candidate['master_id'], $seenIds)) {
                $uniqueCandidates[] = $candidate;
                $seenIds[] = $candidate['master_id'];
            }
        }
        
        return $uniqueCandidates;
    }
    
    /**
     * Поиск по точному совпадению названия
     */
    private function findByExactName(string $name): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM master_products 
            WHERE canonical_name = ? AND status = 'active'
            LIMIT 5
        ");
        $stmt->execute([$name]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Поиск по бренду и категории
     */
    private function findByBrandAndCategory(string $brand, ?string $category): array
    {
        if ($category) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM master_products 
                WHERE canonical_brand = ? AND canonical_category = ? AND status = 'active'
                LIMIT 10
            ");
            $stmt->execute([$brand, $category]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT * FROM master_products 
                WHERE canonical_brand = ? AND status = 'active'
                LIMIT 10
            ");
            $stmt->execute([$brand]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Поиск по похожим названиям
     */
    private function findBySimilarName(string $name): array
    {
        // Используем FULLTEXT поиск или LIKE для поиска похожих названий
        $words = explode(' ', $name);
        $searchTerms = array_slice($words, 0, 3); // Берем первые 3 слова
        
        if (empty($searchTerms)) {
            return [];
        }
        
        $likeConditions = [];
        $params = [];
        
        foreach ($searchTerms as $term) {
            if (strlen($term) > 2) {
                $likeConditions[] = "canonical_name LIKE ?";
                $params[] = "%$term%";
            }
        }
        
        if (empty($likeConditions)) {
            return [];
        }
        
        $sql = "
            SELECT * FROM master_products 
            WHERE (" . implode(' OR ', $likeConditions) . ") AND status = 'active'
            LIMIT 20
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Расчет балла соответствия
     */
    private function calculateMatchScore(array $item, array $candidate): float
    {
        $scores = [];
        
        // Сравнение названий (40% веса)
        $nameScore = $this->calculateNameSimilarity($item['source_name'], $candidate['canonical_name']);
        $scores['name'] = $nameScore * 0.4;
        
        // Сравнение брендов (30% веса)
        $brandScore = $this->calculateBrandSimilarity($item['source_brand'], $candidate['canonical_brand']);
        $scores['brand'] = $brandScore * 0.3;
        
        // Сравнение категорий (20% веса)
        $categoryScore = $this->calculateCategorySimilarity($item['source_category'], $candidate['canonical_category']);
        $scores['category'] = $categoryScore * 0.2;
        
        // Сравнение атрибутов (10% веса)
        $attributesScore = $this->calculateAttributesSimilarity($item['attributes'] ?? [], $candidate['attributes'] ?? []);
        $scores['attributes'] = $attributesScore * 0.1;
        
        return array_sum($scores);
    }
    
    /**
     * Расчет схожести названий
     */
    private function calculateNameSimilarity(string $name1, string $name2): float
    {
        if (empty($name1) || empty($name2)) {
            return 0;
        }
        
        // Нормализуем названия
        $name1 = mb_strtolower(trim($name1), 'UTF-8');
        $name2 = mb_strtolower(trim($name2), 'UTF-8');
        
        // Точное совпадение
        if ($name1 === $name2) {
            return 1.0;
        }
        
        // Levenshtein distance
        $maxLen = max(mb_strlen($name1, 'UTF-8'), mb_strlen($name2, 'UTF-8'));
        if ($maxLen === 0) {
            return 0;
        }
        
        $distance = levenshtein($name1, $name2);
        $similarity = 1 - ($distance / $maxLen);
        
        return max(0, $similarity);
    }
    
    /**
     * Расчет схожести брендов
     */
    private function calculateBrandSimilarity(?string $brand1, ?string $brand2): float
    {
        if (empty($brand1) || empty($brand2)) {
            return 0;
        }
        
        $brand1 = mb_strtolower(trim($brand1), 'UTF-8');
        $brand2 = mb_strtolower(trim($brand2), 'UTF-8');
        
        return $brand1 === $brand2 ? 1.0 : 0;
    }
    
    /**
     * Расчет схожести категорий
     */
    private function calculateCategorySimilarity(?string $category1, ?string $category2): float
    {
        if (empty($category1) || empty($category2)) {
            return 0;
        }
        
        $category1 = mb_strtolower(trim($category1), 'UTF-8');
        $category2 = mb_strtolower(trim($category2), 'UTF-8');
        
        return $category1 === $category2 ? 1.0 : 0;
    }
    
    /**
     * Расчет схожести атрибутов
     */
    private function calculateAttributesSimilarity(array $attrs1, array $attrs2): float
    {
        if (empty($attrs1) || empty($attrs2)) {
            return 0;
        }
        
        $commonKeys = array_intersect(array_keys($attrs1), array_keys($attrs2));
        
        if (empty($commonKeys)) {
            return 0;
        }
        
        $matches = 0;
        foreach ($commonKeys as $key) {
            if ($attrs1[$key] === $attrs2[$key]) {
                $matches++;
            }
        }
        
        return $matches / count($commonKeys);
    }
}

/**
 * Менеджер мастер-данных
 */
class MasterDataManager
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }
    
    /**
     * Генерация нового Master ID
     * 
     * @return string Новый Master ID
     */
    public function generateMasterId(): string
    {
        $prefix = $this->config['master_id_prefix'] ?? 'PROD';
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return $prefix . '_' . $timestamp . '_' . $random;
    }
    
    /**
     * Обогащение мастер-данных новой информацией
     * 
     * @param array $masterData Существующие мастер-данные
     * @param array $newData Новые данные
     * @return array|null Обновленные данные или null если обновление не требуется
     */
    public function enrichMasterData(array $masterData, array $newData): ?array
    {
        $updated = false;
        $enrichedData = $masterData;
        
        // Обогащаем описание если оно пустое или новое более подробное
        if (empty($masterData['description']) && !empty($newData['description'])) {
            $enrichedData['description'] = $newData['description'];
            $updated = true;
        } elseif (!empty($newData['description']) && 
                  strlen($newData['description']) > strlen($masterData['description'] ?? '')) {
            $enrichedData['description'] = $newData['description'];
            $updated = true;
        }
        
        // Обогащаем атрибуты
        $masterAttributes = json_decode($masterData['attributes'] ?? '{}', true) ?: [];
        $newAttributes = $newData['attributes'] ?? [];
        
        foreach ($newAttributes as $key => $value) {
            if (!isset($masterAttributes[$key]) && !empty($value)) {
                $masterAttributes[$key] = $value;
                $updated = true;
            }
        }
        
        if ($updated) {
            $enrichedData['attributes'] = $masterAttributes;
            return $enrichedData;
        }
        
        return null;
    }
}