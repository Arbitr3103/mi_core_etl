<?php

namespace MDM\Services;

use MDM\Models\MasterProduct;
use MDM\Repositories\MasterProductRepository;
use MDM\Repositories\SkuMappingRepository;

/**
 * ProductMatchingOrchestrator - Оркестратор процесса сопоставления товаров
 * 
 * Координирует работу всех компонентов движка автоматического сопоставления:
 * - MatchingEngine для поиска совпадений
 * - MatchingScoreService для оценки и принятия решений
 * - DataEnrichmentService для обогащения данных
 */
class ProductMatchingOrchestrator
{
    private MatchingEngine $matchingEngine;
    private MatchingScoreService $scoreService;
    private DataEnrichmentService $enrichmentService;
    private MasterProductRepository $masterProductRepository;
    private SkuMappingRepository $skuMappingRepository;

    /**
     * Конструктор
     */
    public function __construct(
        MatchingEngine $matchingEngine,
        MatchingScoreService $scoreService,
        DataEnrichmentService $enrichmentService,
        MasterProductRepository $masterProductRepository,
        SkuMappingRepository $skuMappingRepository
    ) {
        $this->matchingEngine = $matchingEngine;
        $this->scoreService = $scoreService;
        $this->enrichmentService = $enrichmentService;
        $this->masterProductRepository = $masterProductRepository;
        $this->skuMappingRepository = $skuMappingRepository;
    }

    /**
     * Обработать новый товар: сопоставление, оценка, обогащение
     * 
     * @param array $productData Данные нового товара
     * @return array Результат обработки
     */
    public function processNewProduct(array $productData): array
    {
        // 1. Обогащение данных из внешних источников
        $enrichmentResult = $this->enrichmentService->enrichProductData($productData);
        $enrichedProductData = $enrichmentResult['enriched_data'];

        // 2. Получение всех мастер-продуктов для сопоставления
        $masterProducts = $this->masterProductRepository->findAll();

        // 3. Поиск потенциальных совпадений
        $matches = $this->matchingEngine->findMatches($enrichedProductData, $masterProducts);

        // 4. Оценка совпадений и принятие решений
        $scoringResults = $this->scoreService->processMatchingResult($enrichedProductData, $matches);

        // 5. Определение финального решения
        $finalDecision = $this->makeFinalDecision($scoringResults);

        return [
            'product_data' => $enrichedProductData,
            'enrichment_result' => $enrichmentResult,
            'matches' => $scoringResults,
            'final_decision' => $finalDecision,
            'processing_summary' => $this->generateProcessingSummary($enrichmentResult, $scoringResults, $finalDecision)
        ];
    }

    /**
     * Принять финальное решение на основе результатов оценки
     * 
     * @param array $scoringResults Результаты оценки совпадений
     * @return array Финальное решение
     */
    private function makeFinalDecision(array $scoringResults): array
    {
        if (empty($scoringResults)) {
            return [
                'action' => MatchingScoreService::DECISION_CREATE_NEW,
                'master_product_id' => null,
                'confidence_score' => 0.0,
                'reasoning' => 'Не найдено потенциальных совпадений'
            ];
        }

        // Берем лучшее совпадение
        $bestMatch = $scoringResults[0];

        return [
            'action' => $bestMatch['decision'],
            'master_product_id' => $bestMatch['master_product_id'],
            'confidence_score' => $bestMatch['confidence_score'],
            'reasoning' => $bestMatch['reasoning']
        ];
    }

    /**
     * Сгенерировать сводку обработки
     * 
     * @param array $enrichmentResult Результат обогащения
     * @param array $scoringResults Результаты оценки
     * @param array $finalDecision Финальное решение
     * @return array Сводка обработки
     */
    private function generateProcessingSummary(
        array $enrichmentResult,
        array $scoringResults,
        array $finalDecision
    ): array {
        return [
            'enrichment_status' => $enrichmentResult['overall_status'],
            'enrichment_sources_used' => count($enrichmentResult['enrichment_results']),
            'matches_found' => count($scoringResults),
            'best_confidence_score' => !empty($scoringResults) ? $scoringResults[0]['confidence_score'] : 0.0,
            'final_action' => $finalDecision['action'],
            'requires_manual_review' => $finalDecision['action'] === MatchingScoreService::DECISION_MANUAL_REVIEW,
            'processing_timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Пакетная обработка товаров
     * 
     * @param array $productsData Массив данных товаров
     * @return array Результаты обработки
     */
    public function processBatch(array $productsData): array
    {
        $results = [];
        $statistics = [
            'total_processed' => 0,
            'auto_accepted' => 0,
            'manual_review' => 0,
            'auto_rejected' => 0,
            'create_new' => 0,
            'processing_errors' => 0
        ];

        foreach ($productsData as $index => $productData) {
            try {
                $result = $this->processNewProduct($productData);
                $results[$index] = $result;
                
                $statistics['total_processed']++;
                $this->updateStatistics($statistics, $result['final_decision']['action']);
                
            } catch (\Exception $e) {
                $results[$index] = [
                    'error' => $e->getMessage(),
                    'product_data' => $productData
                ];
                $statistics['processing_errors']++;
            }
        }

        return [
            'results' => $results,
            'statistics' => $statistics,
            'batch_summary' => $this->generateBatchSummary($statistics)
        ];
    }

    /**
     * Обновить статистику обработки
     * 
     * @param array &$statistics Статистика (по ссылке)
     * @param string $decision Принятое решение
     */
    private function updateStatistics(array &$statistics, string $decision): void
    {
        switch ($decision) {
            case MatchingScoreService::DECISION_AUTO_ACCEPT:
                $statistics['auto_accepted']++;
                break;
            case MatchingScoreService::DECISION_MANUAL_REVIEW:
                $statistics['manual_review']++;
                break;
            case MatchingScoreService::DECISION_AUTO_REJECT:
                $statistics['auto_rejected']++;
                break;
            case MatchingScoreService::DECISION_CREATE_NEW:
                $statistics['create_new']++;
                break;
        }
    }

    /**
     * Сгенерировать сводку пакетной обработки
     * 
     * @param array $statistics Статистика обработки
     * @return array Сводка пакетной обработки
     */
    private function generateBatchSummary(array $statistics): array
    {
        $total = $statistics['total_processed'];
        
        return [
            'total_processed' => $total,
            'success_rate' => $total > 0 ? (($total - $statistics['processing_errors']) / $total) * 100 : 0,
            'auto_processing_rate' => $total > 0 ? (($statistics['auto_accepted'] + $statistics['create_new']) / $total) * 100 : 0,
            'manual_review_rate' => $total > 0 ? ($statistics['manual_review'] / $total) * 100 : 0,
            'processing_timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Получить товары, требующие ручной проверки
     * 
     * @param int $limit Максимальное количество товаров
     * @return array Товары для ручной проверки
     */
    public function getPendingManualReview(int $limit = 50): array
    {
        // В реальной реализации здесь будет запрос к базе данных
        // для получения товаров со статусом "pending_review"
        return $this->skuMappingRepository->findPendingReview($limit);
    }

    /**
     * Подтвердить сопоставление товара
     * 
     * @param string $externalSku Внешний SKU
     * @param string $masterId Master ID для связывания
     * @return bool Успешность операции
     */
    public function confirmMatching(string $externalSku, string $masterId): bool
    {
        try {
            // Обновляем статус сопоставления на "manual"
            return $this->skuMappingRepository->updateVerificationStatus(
                $externalSku,
                $masterId,
                'manual'
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Отклонить сопоставление и создать новый Master ID
     * 
     * @param string $externalSku Внешний SKU
     * @param array $productData Данные товара
     * @return string|null Новый Master ID или null при ошибке
     */
    public function rejectAndCreateNew(string $externalSku, array $productData): ?string
    {
        try {
            // Создаем новый мастер-продукт
            $masterProduct = new MasterProduct();
            $masterProduct->setCanonicalName($productData['name'] ?? '');
            $masterProduct->setCanonicalBrand($productData['brand'] ?? '');
            $masterProduct->setCanonicalCategory($productData['category'] ?? '');
            $masterProduct->setDescription($productData['description'] ?? '');
            $masterProduct->setAttributes($productData['attributes'] ?? []);

            $masterId = $this->masterProductRepository->save($masterProduct);

            if ($masterId) {
                // Создаем связь с новым Master ID
                $this->skuMappingRepository->create([
                    'master_id' => $masterId,
                    'external_sku' => $externalSku,
                    'source' => $productData['source'] ?? 'unknown',
                    'verification_status' => 'manual'
                ]);

                return $masterId;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}