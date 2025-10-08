<?php

namespace MDM\Services;

use MDM\Models\MasterProduct;

/**
 * MatchingScoreService - Система оценки совпадений
 * 
 * Реализует алгоритмы расчета confidence score, пороговые значения
 * для автоматического принятия решений и логирование результатов.
 */
class MatchingScoreService
{
    /**
     * Пороговые значения для принятия решений
     */
    const THRESHOLD_AUTO_ACCEPT = 0.90;    // Автоматическое принятие
    const THRESHOLD_MANUAL_REVIEW = 0.70;  // Ручная проверка
    const THRESHOLD_AUTO_REJECT = 0.30;    // Автоматическое отклонение

    /**
     * Типы решений
     */
    const DECISION_AUTO_ACCEPT = 'auto_accept';
    const DECISION_MANUAL_REVIEW = 'manual_review';
    const DECISION_AUTO_REJECT = 'auto_reject';
    const DECISION_CREATE_NEW = 'create_new';

    /**
     * Весовые коэффициенты для различных типов совпадений
     */
    private array $scoreWeights = [
        'exact_match' => 1.0,          // Точное совпадение (SKU, штрихкод)
        'name_similarity' => 0.4,      // Схожесть названий
        'brand_match' => 0.3,          // Совпадение бренда
        'category_match' => 0.2,       // Совпадение категории
        'attributes_similarity' => 0.1  // Схожесть атрибутов
    ];

    /**
     * Настройки пороговых значений
     */
    private array $thresholds = [
        'auto_accept' => self::THRESHOLD_AUTO_ACCEPT,
        'manual_review' => self::THRESHOLD_MANUAL_REVIEW,
        'auto_reject' => self::THRESHOLD_AUTO_REJECT
    ];

    /**
     * Логгер для записи результатов сопоставления
     */
    private ?object $logger = null;

    /**
     * Конструктор
     * 
     * @param object|null $logger Логгер для записи результатов
     */
    public function __construct(?object $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Рассчитать confidence score для совпадения
     * 
     * @param array $matchDetails Детали совпадения из MatchingEngine
     * @return float Оценка уверенности от 0 до 1
     */
    public function calculateConfidenceScore(array $matchDetails): float
    {
        $totalScore = 0.0;
        $totalWeight = 0.0;

        // Точное совпадение имеет максимальный приоритет
        if ($matchDetails['exact_sku_match'] || $matchDetails['exact_barcode_match']) {
            return 1.0;
        }

        // Схожесть названий
        if (isset($matchDetails['name_similarity']) && $matchDetails['name_similarity'] > 0) {
            $nameScore = $this->adjustNameSimilarityScore($matchDetails['name_similarity']);
            $totalScore += $nameScore * $this->scoreWeights['name_similarity'];
            $totalWeight += $this->scoreWeights['name_similarity'];
        }

        // Совпадение бренда и категории
        if (isset($matchDetails['brand_category_match']) && $matchDetails['brand_category_match'] > 0) {
            $brandCategoryScore = $matchDetails['brand_category_match'];
            
            // Разделяем на бренд и категорию для более точной оценки
            $brandScore = $this->calculateBrandScore($matchDetails);
            $categoryScore = $this->calculateCategoryScore($matchDetails);
            
            if ($brandScore > 0) {
                $totalScore += $brandScore * $this->scoreWeights['brand_match'];
                $totalWeight += $this->scoreWeights['brand_match'];
            }
            
            if ($categoryScore > 0) {
                $totalScore += $categoryScore * $this->scoreWeights['category_match'];
                $totalWeight += $this->scoreWeights['category_match'];
            }
        }

        // Схожесть атрибутов (если доступна)
        if (isset($matchDetails['attributes_similarity']) && $matchDetails['attributes_similarity'] > 0) {
            $totalScore += $matchDetails['attributes_similarity'] * $this->scoreWeights['attributes_similarity'];
            $totalWeight += $this->scoreWeights['attributes_similarity'];
        }

        $confidenceScore = $totalWeight > 0 ? $totalScore / $totalWeight : 0.0;

        // Применяем дополнительные корректировки
        $confidenceScore = $this->applyScoreAdjustments($confidenceScore, $matchDetails);

        return min(1.0, max(0.0, $confidenceScore));
    }

    /**
     * Определить решение на основе confidence score
     * 
     * @param float $confidenceScore Оценка уверенности
     * @param array $matchDetails Детали совпадения
     * @return string Тип решения
     */
    public function makeDecision(float $confidenceScore, array $matchDetails = []): string
    {
        if ($confidenceScore >= $this->thresholds['auto_accept']) {
            return self::DECISION_AUTO_ACCEPT;
        }
        
        if ($confidenceScore >= $this->thresholds['manual_review']) {
            return self::DECISION_MANUAL_REVIEW;
        }
        
        if ($confidenceScore >= $this->thresholds['auto_reject']) {
            return self::DECISION_AUTO_REJECT;
        }
        
        return self::DECISION_CREATE_NEW;
    }

    /**
     * Обработать результат сопоставления с логированием
     * 
     * @param array $productData Данные товара
     * @param array $matches Найденные совпадения
     * @return array Результат обработки с решениями
     */
    public function processMatchingResult(array $productData, array $matches): array
    {
        $results = [];
        
        foreach ($matches as $match) {
            $confidenceScore = $this->calculateConfidenceScore($match['match_details']);
            $decision = $this->makeDecision($confidenceScore, $match['match_details']);
            
            $result = [
                'master_product_id' => $match['master_product']->getMasterId(),
                'confidence_score' => $confidenceScore,
                'decision' => $decision,
                'match_details' => $match['match_details'],
                'reasoning' => $this->generateReasoning($confidenceScore, $match['match_details'])
            ];
            
            $results[] = $result;
            
            // Логирование результата
            $this->logMatchingResult($productData, $result);
        }
        
        // Сортировка по убыванию confidence score
        usort($results, function($a, $b) {
            return $b['confidence_score'] <=> $a['confidence_score'];
        });
        
        return $results;
    }

    /**
     * Скорректировать оценку схожести названий
     * 
     * @param float $nameSimilarity Базовая схожесть названий
     * @return float Скорректированная оценка
     */
    private function adjustNameSimilarityScore(float $nameSimilarity): float
    {
        // Применяем нелинейную функцию для повышения точности
        // Высокие значения схожести получают больший вес
        return pow($nameSimilarity, 1.5);
    }

    /**
     * Рассчитать оценку совпадения бренда
     * 
     * @param array $matchDetails Детали совпадения
     * @return float Оценка совпадения бренда
     */
    private function calculateBrandScore(array $matchDetails): float
    {
        $productBrand = strtolower(trim($matchDetails['product_brand'] ?? ''));
        $masterBrand = strtolower(trim($matchDetails['master_brand'] ?? ''));
        
        if (empty($productBrand) || empty($masterBrand)) {
            return 0.0;
        }
        
        // Точное совпадение
        if ($productBrand === $masterBrand) {
            return 1.0;
        }
        
        // Проверка на вхождение одного бренда в другой
        if (strpos($productBrand, $masterBrand) !== false || strpos($masterBrand, $productBrand) !== false) {
            return 0.8;
        }
        
        return 0.0;
    }

    /**
     * Рассчитать оценку совпадения категории
     * 
     * @param array $matchDetails Детали совпадения
     * @return float Оценка совпадения категории
     */
    private function calculateCategoryScore(array $matchDetails): float
    {
        // Для простоты используем точное совпадение
        // В будущем можно добавить иерархическое сравнение категорий
        return $matchDetails['brand_category_match'] ?? 0.0;
    }

    /**
     * Применить дополнительные корректировки к оценке
     * 
     * @param float $score Базовая оценка
     * @param array $matchDetails Детали совпадения
     * @return float Скорректированная оценка
     */
    private function applyScoreAdjustments(float $score, array $matchDetails): float
    {
        // Штраф за очень короткие названия (менее 3 символов)
        $productName = $matchDetails['product_name'] ?? '';
        if (strlen(trim($productName)) < 3) {
            $score *= 0.5;
        }
        
        // Бонус за совпадение и бренда, и категории
        if (($matchDetails['product_brand'] ?? '') !== '' && 
            ($matchDetails['master_brand'] ?? '') !== '' &&
            ($matchDetails['brand_category_match'] ?? 0) > 0.8) {
            $score *= 1.1;
        }
        
        return $score;
    }

    /**
     * Сгенерировать объяснение решения
     * 
     * @param float $confidenceScore Оценка уверенности
     * @param array $matchDetails Детали совпадения
     * @return string Объяснение решения
     */
    private function generateReasoning(float $confidenceScore, array $matchDetails): string
    {
        $reasons = [];
        
        if ($matchDetails['exact_sku_match']) {
            $reasons[] = 'Точное совпадение SKU';
        }
        
        if ($matchDetails['exact_barcode_match']) {
            $reasons[] = 'Точное совпадение штрихкода';
        }
        
        if (($matchDetails['name_similarity'] ?? 0) > 0.8) {
            $reasons[] = sprintf('Высокая схожесть названий (%.1f%%)', 
                ($matchDetails['name_similarity'] ?? 0) * 100);
        }
        
        if (($matchDetails['brand_category_match'] ?? 0) > 0.5) {
            $reasons[] = 'Совпадение бренда и/или категории';
        }
        
        if (empty($reasons)) {
            $reasons[] = sprintf('Общая оценка совпадения: %.1f%%', $confidenceScore * 100);
        }
        
        return implode('; ', $reasons);
    }

    /**
     * Логировать результат сопоставления
     * 
     * @param array $productData Данные товара
     * @param array $result Результат сопоставления
     */
    private function logMatchingResult(array $productData, array $result): void
    {
        if ($this->logger === null) {
            return;
        }
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'product_sku' => $productData['sku'] ?? 'unknown',
            'product_name' => $productData['name'] ?? 'unknown',
            'master_product_id' => $result['master_product_id'],
            'confidence_score' => $result['confidence_score'],
            'decision' => $result['decision'],
            'reasoning' => $result['reasoning']
        ];
        
        // Предполагаем, что логгер имеет метод info()
        if (method_exists($this->logger, 'info')) {
            $this->logger->info('Matching result', $logData);
        }
    }

    /**
     * Установить пороговые значения
     * 
     * @param array $thresholds Новые пороговые значения
     */
    public function setThresholds(array $thresholds): void
    {
        $this->thresholds = array_merge($this->thresholds, $thresholds);
    }

    /**
     * Получить текущие пороговые значения
     * 
     * @return array Пороговые значения
     */
    public function getThresholds(): array
    {
        return $this->thresholds;
    }

    /**
     * Установить весовые коэффициенты
     * 
     * @param array $weights Новые весовые коэффициенты
     */
    public function setScoreWeights(array $weights): void
    {
        $this->scoreWeights = array_merge($this->scoreWeights, $weights);
    }

    /**
     * Получить текущие весовые коэффициенты
     * 
     * @return array Весовые коэффициенты
     */
    public function getScoreWeights(): array
    {
        return $this->scoreWeights;
    }

    /**
     * Получить статистику по принятым решениям
     * 
     * @param array $results Массив результатов сопоставления
     * @return array Статистика решений
     */
    public function getDecisionStatistics(array $results): array
    {
        $stats = [
            self::DECISION_AUTO_ACCEPT => 0,
            self::DECISION_MANUAL_REVIEW => 0,
            self::DECISION_AUTO_REJECT => 0,
            self::DECISION_CREATE_NEW => 0
        ];
        
        foreach ($results as $result) {
            $decision = $result['decision'] ?? self::DECISION_CREATE_NEW;
            if (isset($stats[$decision])) {
                $stats[$decision]++;
            }
        }
        
        $total = array_sum($stats);
        $percentages = [];
        
        foreach ($stats as $decision => $count) {
            $percentages[$decision] = $total > 0 ? ($count / $total) * 100 : 0;
        }
        
        return [
            'counts' => $stats,
            'percentages' => $percentages,
            'total' => $total
        ];
    }
}