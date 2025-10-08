<?php

namespace MDM\Workers;

use MDM\Services\MatchingService;
use MDM\Services\ProductsService;
use Exception;

/**
 * Воркер для асинхронного сопоставления товаров
 */
class ProductMatchingWorker extends BaseWorker
{
    private MatchingService $matchingService;
    private ProductsService $productsService;
    
    public function __construct($queueService, MatchingService $matchingService, ProductsService $productsService, array $config = [])
    {
        parent::__construct($queueService, $config);
        $this->matchingService = $matchingService;
        $this->productsService = $productsService;
    }
    
    /**
     * Получить поддерживаемые типы задач
     */
    protected function getSupportedJobTypes(): array
    {
        return [
            'product_matching',
            'bulk_product_matching',
            'product_enrichment',
            'similarity_calculation'
        ];
    }
    
    /**
     * Обработать задачу
     */
    protected function processJob(array $job): array
    {
        switch ($job['type']) {
            case 'product_matching':
                return $this->processProductMatching($job['data']);
                
            case 'bulk_product_matching':
                return $this->processBulkProductMatching($job['data']);
                
            case 'product_enrichment':
                return $this->processProductEnrichment($job['data']);
                
            case 'similarity_calculation':
                return $this->processSimilarityCalculation($job['data']);
                
            default:
                throw new Exception("Unsupported job type: {$job['type']}");
        }
    }
    
    /**
     * Обработать сопоставление одного товара
     */
    private function processProductMatching(array $data): array
    {
        if (!isset($data['external_sku']) || !isset($data['source'])) {
            throw new Exception("Missing required fields: external_sku, source");
        }
        
        $externalSku = $data['external_sku'];
        $source = $data['source'];
        $productData = $data['product_data'] ?? [];
        
        $this->log("Matching product: {$externalSku} from {$source}");
        
        // Найти похожие товары
        $matches = $this->matchingService->findMatches($productData);
        
        $result = [
            'external_sku' => $externalSku,
            'source' => $source,
            'matches_found' => count($matches),
            'matches' => $matches
        ];
        
        // Если найдено точное совпадение с высокой уверенностью, автоматически связать
        if (!empty($matches)) {
            $bestMatch = $matches[0];
            if ($bestMatch['confidence_score'] >= 0.9) {
                $mappingResult = $this->matchingService->createMapping(
                    $bestMatch['master_id'],
                    $externalSku,
                    $source,
                    $productData,
                    $bestMatch['confidence_score'],
                    'auto'
                );
                
                $result['auto_mapped'] = true;
                $result['master_id'] = $bestMatch['master_id'];
                $result['mapping_id'] = $mappingResult['mapping_id'];
                
                $this->log("Auto-mapped {$externalSku} to {$bestMatch['master_id']} with confidence {$bestMatch['confidence_score']}");
            } else {
                $result['auto_mapped'] = false;
                $result['requires_manual_review'] = true;
                
                $this->log("Product {$externalSku} requires manual review (best confidence: {$bestMatch['confidence_score']})");
            }
        } else {
            // Создать новый Master ID
            $masterProduct = $this->productsService->createMasterProduct($productData);
            $mappingResult = $this->matchingService->createMapping(
                $masterProduct['master_id'],
                $externalSku,
                $source,
                $productData,
                1.0,
                'auto'
            );
            
            $result['new_master_created'] = true;
            $result['master_id'] = $masterProduct['master_id'];
            $result['mapping_id'] = $mappingResult['mapping_id'];
            
            $this->log("Created new master product {$masterProduct['master_id']} for {$externalSku}");
        }
        
        return $result;
    }
    
    /**
     * Обработать массовое сопоставление товаров
     */
    private function processBulkProductMatching(array $data): array
    {
        if (!isset($data['products']) || !is_array($data['products'])) {
            throw new Exception("Missing or invalid products array");
        }
        
        $products = $data['products'];
        $batchSize = $data['batch_size'] ?? 50;
        
        $results = [
            'total_products' => count($products),
            'processed' => 0,
            'auto_mapped' => 0,
            'manual_review' => 0,
            'new_masters' => 0,
            'errors' => 0,
            'details' => []
        ];
        
        $this->log("Processing bulk matching for " . count($products) . " products");
        
        foreach (array_chunk($products, $batchSize) as $batch) {
            foreach ($batch as $product) {
                try {
                    $result = $this->processProductMatching($product);
                    
                    $results['processed']++;
                    
                    if ($result['auto_mapped'] ?? false) {
                        $results['auto_mapped']++;
                    } elseif ($result['requires_manual_review'] ?? false) {
                        $results['manual_review']++;
                    } elseif ($result['new_master_created'] ?? false) {
                        $results['new_masters']++;
                    }
                    
                    $results['details'][] = $result;
                    
                } catch (Exception $e) {
                    $results['errors']++;
                    $results['details'][] = [
                        'external_sku' => $product['external_sku'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                    
                    $this->log("Error processing product: " . $e->getMessage());
                }
            }
            
            // Небольшая пауза между батчами
            usleep(100000); // 0.1 секунды
        }
        
        $this->log("Bulk matching completed: {$results['processed']} processed, {$results['auto_mapped']} auto-mapped, {$results['errors']} errors");
        
        return $results;
    }
    
    /**
     * Обработать обогащение данных товара
     */
    private function processProductEnrichment(array $data): array
    {
        if (!isset($data['master_id'])) {
            throw new Exception("Missing required field: master_id");
        }
        
        $masterId = $data['master_id'];
        $enrichmentSources = $data['sources'] ?? ['external_api', 'similar_products'];
        
        $this->log("Enriching product data for master_id: {$masterId}");
        
        $result = [
            'master_id' => $masterId,
            'enriched_fields' => [],
            'sources_used' => []
        ];
        
        // Получить текущие данные товара
        $masterProduct = $this->productsService->getMasterProduct($masterId);
        if (!$masterProduct) {
            throw new Exception("Master product not found: {$masterId}");
        }
        
        // Обогащение из внешних API
        if (in_array('external_api', $enrichmentSources)) {
            $enrichedData = $this->enrichFromExternalAPI($masterProduct);
            if (!empty($enrichedData)) {
                $result['enriched_fields'] = array_merge($result['enriched_fields'], array_keys($enrichedData));
                $result['sources_used'][] = 'external_api';
                
                // Обновить мастер-данные
                $this->productsService->updateMasterProduct($masterId, $enrichedData);
            }
        }
        
        // Обогащение из похожих товаров
        if (in_array('similar_products', $enrichmentSources)) {
            $enrichedData = $this->enrichFromSimilarProducts($masterProduct);
            if (!empty($enrichedData)) {
                $result['enriched_fields'] = array_merge($result['enriched_fields'], array_keys($enrichedData));
                $result['sources_used'][] = 'similar_products';
                
                // Обновить мастер-данные
                $this->productsService->updateMasterProduct($masterId, $enrichedData);
            }
        }
        
        $result['enriched_fields'] = array_unique($result['enriched_fields']);
        
        $this->log("Enrichment completed for {$masterId}: " . implode(', ', $result['enriched_fields']));
        
        return $result;
    }
    
    /**
     * Обработать расчет схожести товаров
     */
    private function processSimilarityCalculation(array $data): array
    {
        if (!isset($data['master_id'])) {
            throw new Exception("Missing required field: master_id");
        }
        
        $masterId = $data['master_id'];
        $limit = $data['limit'] ?? 10;
        
        $this->log("Calculating similarity for master_id: {$masterId}");
        
        // Получить товар
        $masterProduct = $this->productsService->getMasterProduct($masterId);
        if (!$masterProduct) {
            throw new Exception("Master product not found: {$masterId}");
        }
        
        // Найти похожие товары
        $similarProducts = $this->matchingService->findSimilarProducts($masterProduct, $limit);
        
        $result = [
            'master_id' => $masterId,
            'similar_products_count' => count($similarProducts),
            'similar_products' => $similarProducts
        ];
        
        $this->log("Found " . count($similarProducts) . " similar products for {$masterId}");
        
        return $result;
    }
    
    /**
     * Обогащение данных из внешнего API
     */
    private function enrichFromExternalAPI(array $masterProduct): array
    {
        // Заглушка для обогащения из внешнего API
        // В реальной реализации здесь будут вызовы к внешним сервисам
        
        $enrichedData = [];
        
        // Пример: если отсутствует описание, попытаться получить его
        if (empty($masterProduct['description'])) {
            // Здесь был бы вызов к внешнему API
            $enrichedData['description'] = "Обогащенное описание из внешнего API";
        }
        
        // Пример: обогащение атрибутов
        if (empty($masterProduct['attributes'])) {
            $enrichedData['attributes'] = json_encode([
                'weight' => 'unknown',
                'dimensions' => 'unknown'
            ]);
        }
        
        return $enrichedData;
    }
    
    /**
     * Обогащение данных из похожих товаров
     */
    private function enrichFromSimilarProducts(array $masterProduct): array
    {
        $enrichedData = [];
        
        // Найти похожие товары с заполненными данными
        $similarProducts = $this->matchingService->findSimilarProducts($masterProduct, 5);
        
        foreach ($similarProducts as $similar) {
            // Если у текущего товара нет категории, взять из похожего
            if (empty($masterProduct['canonical_category']) && !empty($similar['canonical_category'])) {
                $enrichedData['canonical_category'] = $similar['canonical_category'];
            }
            
            // Если нет описания, взять из похожего
            if (empty($masterProduct['description']) && !empty($similar['description'])) {
                $enrichedData['description'] = $similar['description'];
            }
        }
        
        return $enrichedData;
    }
}