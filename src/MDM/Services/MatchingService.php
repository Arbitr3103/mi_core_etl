<?php

namespace MDM\Services;

/**
 * Matching Service for MDM System
 * Handles product matching algorithms and similarity calculations
 */
class MatchingService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Find similar products for a given SKU mapping
     */
    public function findSimilarProducts(int $skuMappingId, int $limit = 10): array
    {
        // Get SKU mapping details
        $sql = "SELECT * FROM sku_mapping WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$skuMappingId]);
        $skuMapping = $stmt->fetch();

        if (!$skuMapping) {
            throw new Exception('SKU mapping not found');
        }

        $similarProducts = [];

        // 1. Exact name matches
        $exactMatches = $this->findExactMatches($skuMapping);
        $similarProducts = array_merge($similarProducts, $exactMatches);

        // 2. Fuzzy name matches
        if (count($similarProducts) < $limit) {
            $fuzzyMatches = $this->findFuzzyMatches($skuMapping, $limit - count($similarProducts));
            $similarProducts = array_merge($similarProducts, $fuzzyMatches);
        }

        // 3. Brand + category matches
        if (count($similarProducts) < $limit) {
            $brandCategoryMatches = $this->findBrandCategoryMatches($skuMapping, $limit - count($similarProducts));
            $similarProducts = array_merge($similarProducts, $brandCategoryMatches);
        }

        // Remove duplicates and sort by score
        $uniqueProducts = [];
        $seenMasterIds = [];
        
        foreach ($similarProducts as $product) {
            if (!in_array($product['master_id'], $seenMasterIds)) {
                $uniqueProducts[] = $product;
                $seenMasterIds[] = $product['master_id'];
            }
        }

        // Sort by match score descending
        usort($uniqueProducts, function($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        return array_slice($uniqueProducts, 0, $limit);
    }

    /**
     * Find exact name matches
     */
    private function findExactMatches(array $skuMapping): array
    {
        $sql = "
            SELECT 
                master_id,
                canonical_name,
                canonical_brand,
                canonical_category,
                description,
                0.95 as match_score,
                'exact_name' as match_type
            FROM master_products 
            WHERE status = 'active'
            AND LOWER(TRIM(canonical_name)) = LOWER(TRIM(?))
            LIMIT 5
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$skuMapping['source_name']]);
        
        return $stmt->fetchAll();
    }

    /**
     * Find fuzzy name matches using similarity algorithms
     */
    private function findFuzzyMatches(array $skuMapping, int $limit): array
    {
        // Get all active master products for comparison
        $sql = "
            SELECT 
                master_id,
                canonical_name,
                canonical_brand,
                canonical_category,
                description
            FROM master_products 
            WHERE status = 'active'
            ORDER BY created_at DESC
            LIMIT 1000
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $masterProducts = $stmt->fetchAll();

        $matches = [];
        $sourceName = mb_strtolower(trim($skuMapping['source_name']));

        foreach ($masterProducts as $product) {
            $masterName = mb_strtolower(trim($product['canonical_name']));
            
            // Calculate similarity score
            $similarity = $this->calculateNameSimilarity($sourceName, $masterName);
            
            if ($similarity >= 0.6) { // Minimum threshold
                $matches[] = array_merge($product, [
                    'match_score' => $similarity,
                    'match_type' => 'fuzzy_name'
                ]);
            }
        }

        // Sort by similarity score
        usort($matches, function($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        return array_slice($matches, 0, $limit);
    }

    /**
     * Find matches based on brand and category
     */
    private function findBrandCategoryMatches(array $skuMapping, int $limit): array
    {
        $conditions = [];
        $params = [];

        if (!empty($skuMapping['source_brand'])) {
            $conditions[] = "LOWER(canonical_brand) = LOWER(?)";
            $params[] = $skuMapping['source_brand'];
        }

        if (!empty($skuMapping['source_category'])) {
            $conditions[] = "LOWER(canonical_category) = LOWER(?)";
            $params[] = $skuMapping['source_category'];
        }

        if (empty($conditions)) {
            return [];
        }

        $whereClause = implode(' OR ', $conditions);
        
        $sql = "
            SELECT 
                master_id,
                canonical_name,
                canonical_brand,
                canonical_category,
                description,
                (
                    CASE 
                        WHEN LOWER(canonical_brand) = LOWER(?) AND LOWER(canonical_category) = LOWER(?) THEN 0.8
                        WHEN LOWER(canonical_brand) = LOWER(?) THEN 0.6
                        WHEN LOWER(canonical_category) = LOWER(?) THEN 0.5
                        ELSE 0.3
                    END
                ) as match_score,
                'brand_category' as match_type
            FROM master_products 
            WHERE status = 'active'
            AND ({$whereClause})
            ORDER BY match_score DESC
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $executeParams = [
            $skuMapping['source_brand'] ?? '',
            $skuMapping['source_category'] ?? '',
            $skuMapping['source_brand'] ?? '',
            $skuMapping['source_category'] ?? ''
        ];
        $executeParams = array_merge($executeParams, $params, [$limit]);
        
        $stmt->execute($executeParams);
        
        return $stmt->fetchAll();
    }

    /**
     * Calculate name similarity using multiple algorithms
     */
    private function calculateNameSimilarity(string $name1, string $name2): float
    {
        // Normalize names
        $name1 = $this->normalizeName($name1);
        $name2 = $this->normalizeName($name2);

        // If names are identical after normalization
        if ($name1 === $name2) {
            return 1.0;
        }

        // Calculate different similarity metrics
        $levenshtein = $this->calculateLevenshteinSimilarity($name1, $name2);
        $jaccard = $this->calculateJaccardSimilarity($name1, $name2);
        $wordOverlap = $this->calculateWordOverlapSimilarity($name1, $name2);

        // Weighted average of different metrics
        $similarity = ($levenshtein * 0.4) + ($jaccard * 0.3) + ($wordOverlap * 0.3);

        return round($similarity, 3);
    }

    /**
     * Normalize product name for comparison
     */
    private function normalizeName(string $name): string
    {
        // Convert to lowercase
        $name = mb_strtolower($name);
        
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Remove common words that don't add meaning
        $stopWords = ['для', 'из', 'на', 'по', 'с', 'в', 'и', 'или', 'но', 'а', 'то', 'что', 'шт', 'штук', 'набор'];
        $words = explode(' ', $name);
        $words = array_filter($words, function($word) use ($stopWords) {
            return !in_array(trim($word), $stopWords) && strlen(trim($word)) > 1;
        });
        
        return trim(implode(' ', $words));
    }

    /**
     * Calculate Levenshtein similarity
     */
    private function calculateLevenshteinSimilarity(string $str1, string $str2): float
    {
        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $maxLen);
    }

    /**
     * Calculate Jaccard similarity based on character n-grams
     */
    private function calculateJaccardSimilarity(string $str1, string $str2): float
    {
        $ngrams1 = $this->getNgrams($str1, 2);
        $ngrams2 = $this->getNgrams($str2, 2);
        
        $intersection = count(array_intersect($ngrams1, $ngrams2));
        $union = count(array_unique(array_merge($ngrams1, $ngrams2)));
        
        return $union > 0 ? $intersection / $union : 0;
    }

    /**
     * Calculate word overlap similarity
     */
    private function calculateWordOverlapSimilarity(string $str1, string $str2): float
    {
        $words1 = array_unique(explode(' ', $str1));
        $words2 = array_unique(explode(' ', $str2));
        
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));
        
        return $union > 0 ? $intersection / $union : 0;
    }

    /**
     * Generate n-grams from string
     */
    private function getNgrams(string $str, int $n): array
    {
        $ngrams = [];
        $len = mb_strlen($str);
        
        for ($i = 0; $i <= $len - $n; $i++) {
            $ngrams[] = mb_substr($str, $i, $n);
        }
        
        return $ngrams;
    }

    /**
     * Calculate overall match confidence score
     */
    public function calculateMatchConfidence(array $skuMapping, array $masterProduct): float
    {
        $scores = [];

        // Name similarity (40% weight)
        if (!empty($skuMapping['source_name']) && !empty($masterProduct['canonical_name'])) {
            $nameSimilarity = $this->calculateNameSimilarity(
                $skuMapping['source_name'],
                $masterProduct['canonical_name']
            );
            $scores['name'] = $nameSimilarity * 0.4;
        }

        // Brand match (30% weight)
        if (!empty($skuMapping['source_brand']) && !empty($masterProduct['canonical_brand'])) {
            $brandMatch = mb_strtolower($skuMapping['source_brand']) === mb_strtolower($masterProduct['canonical_brand']) ? 1.0 : 0.0;
            $scores['brand'] = $brandMatch * 0.3;
        }

        // Category match (20% weight)
        if (!empty($skuMapping['source_category']) && !empty($masterProduct['canonical_category'])) {
            $categoryMatch = mb_strtolower($skuMapping['source_category']) === mb_strtolower($masterProduct['canonical_category']) ? 1.0 : 0.0;
            $scores['category'] = $categoryMatch * 0.2;
        }

        // Source reliability (10% weight)
        $sourceReliability = $this->getSourceReliability($skuMapping['source']);
        $scores['source'] = $sourceReliability * 0.1;

        return array_sum($scores);
    }

    /**
     * Get source reliability score
     */
    private function getSourceReliability(string $source): float
    {
        $reliabilityMap = [
            'ozon' => 0.9,
            'wildberries' => 0.9,
            'internal' => 1.0,
            'manual' => 1.0
        ];

        return $reliabilityMap[$source] ?? 0.7;
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}