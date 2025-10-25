<?php
/**
 * WarehouseNormalizer - Сервис для нормализации названий складов
 * 
 * Реализует нормализацию названий складов из Analytics API,
 * автоматические правила нормализации (РФЦ, МРФЦ), обработку дубликатов
 * и опечаток, логирование нераспознанных значений.
 * 
 * Requirements: 14.1, 14.2, 14.3, 14.4
 * Task: 4.3 Создать WarehouseNormalizer сервис
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

class WarehouseNormalizer {
    // Normalization confidence levels
    const CONFIDENCE_EXACT = 1.0;
    const CONFIDENCE_RULE_BASED = 0.9;
    const CONFIDENCE_FUZZY = 0.8;
    const CONFIDENCE_MANUAL = 1.0;
    const CONFIDENCE_AUTO_DETECTED = 0.7;
    
    // Match types
    const MATCH_EXACT = 'exact';
    const MATCH_RULE_BASED = 'rule_based';
    const MATCH_FUZZY = 'fuzzy';
    const MATCH_MANUAL = 'manual';
    const MATCH_AUTO_DETECTED = 'auto_detected';
    
    // Source types
    const SOURCE_API = 'api';
    const SOURCE_UI_REPORT = 'ui_report';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_AUTO_DETECTED = 'auto_detected';
    
    private ?PDO $pdo;
    private array $normalizationRules;
    private array $autoRules;
    private array $fuzzyMatchCache;
    private array $unrecognizedLog;
    
    /**
     * Constructor
     * 
     * @param PDO|null $pdo Database connection for storing normalization rules
     */
    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo;
        $this->normalizationRules = [];
        $this->fuzzyMatchCache = [];
        $this->unrecognizedLog = [];
        
        $this->initializeAutoRules();
        
        if ($this->pdo) {
            $this->createNormalizationTableIfNotExists();
            $this->loadNormalizationRules();
        }
    } 
   
    /**
     * Normalize warehouse name from Analytics API
     * 
     * @param string $warehouseName Original warehouse name
     * @param string $sourceType Source type (api, ui_report, manual)
     * @return NormalizationResult Normalization result with confidence and details
     */
    public function normalize(string $warehouseName, string $sourceType = self::SOURCE_API): NormalizationResult {
        // Clean input
        $originalName = trim($warehouseName);
        $cleanedName = $this->cleanWarehouseName($originalName);
        
        if (empty($cleanedName)) {
            return new NormalizationResult(
                $originalName,
                $originalName,
                self::MATCH_EXACT,
                0.0,
                $sourceType,
                ['error' => 'Empty warehouse name after cleaning']
            );
        }
        
        // Try exact match first
        $exactMatch = $this->findExactMatch($cleanedName, $sourceType);
        if ($exactMatch) {
            $this->updateUsageStatistics($exactMatch['id']);
            return new NormalizationResult(
                $originalName,
                $exactMatch['normalized_name'],
                self::MATCH_EXACT,
                self::CONFIDENCE_EXACT,
                $sourceType,
                ['rule_id' => $exactMatch['id'], 'from_database' => true]
            );
        }
        
        // Try rule-based normalization
        $ruleBasedResult = $this->applyAutoRules($cleanedName);
        if ($ruleBasedResult['success']) {
            $normalizedName = $ruleBasedResult['normalized'];
            
            // Save new rule for future use
            $ruleId = $this->saveNormalizationRule(
                $originalName,
                $normalizedName,
                $sourceType,
                self::CONFIDENCE_RULE_BASED,
                self::MATCH_RULE_BASED
            );
            
            return new NormalizationResult(
                $originalName,
                $normalizedName,
                self::MATCH_RULE_BASED,
                self::CONFIDENCE_RULE_BASED,
                $sourceType,
                [
                    'rule_id' => $ruleId,
                    'applied_rules' => $ruleBasedResult['applied_rules'],
                    'auto_created' => true
                ]
            );
        }
        
        // Try fuzzy matching
        $fuzzyMatch = $this->findFuzzyMatch($cleanedName, $sourceType);
        if ($fuzzyMatch) {
            $this->updateUsageStatistics($fuzzyMatch['rule_id']);
            return new NormalizationResult(
                $originalName,
                $fuzzyMatch['normalized_name'],
                self::MATCH_FUZZY,
                $fuzzyMatch['confidence'],
                $sourceType,
                [
                    'rule_id' => $fuzzyMatch['rule_id'],
                    'similarity_score' => $fuzzyMatch['similarity'],
                    'from_fuzzy_match' => true
                ]
            );
        }
        
        // No match found - log as unrecognized and return cleaned version
        $this->logUnrecognizedWarehouse($originalName, $cleanedName, $sourceType);
        
        return new NormalizationResult(
            $originalName,
            $this->generateFallbackNormalization($cleanedName),
            self::MATCH_AUTO_DETECTED,
            self::CONFIDENCE_AUTO_DETECTED,
            $sourceType,
            ['unrecognized' => true, 'needs_review' => true]
        );
    }
    
    /**
     * Batch normalize multiple warehouse names
     * 
     * @param array $warehouseNames Array of warehouse names
     * @param string $sourceType Source type
     * @return array Array of NormalizationResult objects
     */
    public function normalizeBatch(array $warehouseNames, string $sourceType = self::SOURCE_API): array {
        $results = [];
        $startTime = microtime(true);
        
        foreach ($warehouseNames as $index => $warehouseName) {
            $results[$index] = $this->normalize($warehouseName, $sourceType);
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000);
        
        // Log batch normalization statistics
        $this->logBatchNormalization($warehouseNames, $results, $sourceType, $executionTime);
        
        return $results;
    }
    
    /**
     * Add manual normalization rule
     * 
     * @param string $originalName Original warehouse name
     * @param string $normalizedName Normalized warehouse name
     * @param string $sourceType Source type
     * @return int Rule ID
     */
    public function addManualRule(string $originalName, string $normalizedName, string $sourceType = self::SOURCE_MANUAL): int {
        return $this->saveNormalizationRule(
            $originalName,
            $normalizedName,
            $sourceType,
            self::CONFIDENCE_MANUAL,
            self::MATCH_MANUAL
        );
    }
    
    /**
     * Get normalization statistics
     * 
     * @param int $days Number of days to look back
     * @return array Normalization statistics
     */
    public function getNormalizationStatistics(int $days = 7): array {
        if (!$this->pdo) {
            return [];
        }
        
        $stats = [
            'total_rules' => 0,
            'active_rules' => 0,
            'usage_stats' => [],
            'confidence_distribution' => [],
            'source_distribution' => [],
            'unrecognized_count' => 0
        ];
        
        // Get total and active rules count
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_rules,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rules
            FROM warehouse_normalization
        ");
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_rules'] = (int)$counts['total_rules'];
        $stats['active_rules'] = (int)$counts['active_rules'];
        
        // Get usage statistics
        $stmt = $this->pdo->prepare("
            SELECT 
                match_type,
                COUNT(*) as count,
                AVG(confidence_score) as avg_confidence,
                SUM(usage_count) as total_usage
            FROM warehouse_normalization 
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY match_type
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        $stats['usage_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get confidence distribution
        $stmt = $this->pdo->query("
            SELECT 
                CASE 
                    WHEN confidence_score = 1.0 THEN 'Perfect (1.0)'
                    WHEN confidence_score >= 0.9 THEN 'High (0.9-0.99)'
                    WHEN confidence_score >= 0.8 THEN 'Medium (0.8-0.89)'
                    WHEN confidence_score >= 0.7 THEN 'Low (0.7-0.79)'
                    ELSE 'Very Low (<0.7)'
                END as confidence_range,
                COUNT(*) as count
            FROM warehouse_normalization 
            WHERE is_active = 1
            GROUP BY confidence_range
            ORDER BY MIN(confidence_score) DESC
        ");
        $stats['confidence_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get source distribution
        $stmt = $this->pdo->query("
            SELECT 
                source_type,
                COUNT(*) as count,
                AVG(confidence_score) as avg_confidence
            FROM warehouse_normalization 
            WHERE is_active = 1
            GROUP BY source_type
            ORDER BY count DESC
        ");
        $stats['source_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    /**
     * Get unrecognized warehouses that need manual review
     * 
     * @param int $limit Maximum number of results
     * @return array Unrecognized warehouse names
     */
    public function getUnrecognizedWarehouses(int $limit = 50): array {
        if (!$this->pdo) {
            return array_slice($this->unrecognizedLog, 0, $limit);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                original_name,
                normalized_name,
                source_type,
                confidence_score,
                usage_count,
                created_at,
                last_used_at
            FROM warehouse_normalization 
            WHERE match_type = ? AND confidence_score < 0.8
            ORDER BY usage_count DESC, created_at DESC
            LIMIT ?
        ");
        $stmt->execute([self::MATCH_AUTO_DETECTED, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Clean warehouse name by removing extra spaces and standardizing format
     * 
     * @param string $name Original warehouse name
     * @return string Cleaned warehouse name
     */
    private function cleanWarehouseName(string $name): string {
        // Remove extra whitespace
        $cleaned = trim(preg_replace('/\s+/', ' ', $name));
        
        // Convert to uppercase for consistency
        $cleaned = mb_strtoupper($cleaned, 'UTF-8');
        
        // Remove special characters except hyphens and underscores
        $cleaned = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $cleaned);
        
        // Standardize common abbreviations
        $abbreviations = [
            'РЕГИОНАЛЬНЫЙ ФУЛФИЛМЕНТ ЦЕНТР' => 'РФЦ',
            'МУЛЬТИРЕГИОНАЛЬНЫЙ ФУЛФИЛМЕНТ ЦЕНТР' => 'МРФЦ',
            'ФУЛФИЛМЕНТ ЦЕНТР' => 'ФЦ',
            'РАСПРЕДЕЛИТЕЛЬНЫЙ ЦЕНТР' => 'РЦ',
            'ЛОГИСТИЧЕСКИЙ ЦЕНТР' => 'ЛЦ',
            'СКЛАД' => 'СКЛАД'
        ];
        
        foreach ($abbreviations as $full => $abbr) {
            $cleaned = str_replace($full, $abbr, $cleaned);
        }
        
        return trim($cleaned);
    }
    
    /**
     * Initialize automatic normalization rules
     */
    private function initializeAutoRules(): void {
        $this->autoRules = [
            // РФЦ patterns
            'rfc_patterns' => [
                '/^РФЦ\s+(.+)$/' => 'РФЦ_$1',
                '/^(.+)\s+РФЦ$/' => 'РФЦ_$1',
                '/^РФЦ[-_\s]*(.+)$/' => 'РФЦ_$1'
            ],
            
            // МРФЦ patterns
            'mrfc_patterns' => [
                '/^МРФЦ\s+(.+)$/' => 'МРФЦ_$1',
                '/^(.+)\s+МРФЦ$/' => 'МРФЦ_$1',
                '/^МРФЦ[-_\s]*(.+)$/' => 'МРФЦ_$1'
            ],
            
            // General warehouse patterns
            'warehouse_patterns' => [
                '/^СКЛАД\s+(.+)$/' => 'СКЛАД_$1',
                '/^(.+)\s+СКЛАД$/' => 'СКЛАД_$1',
                '/^ФЦ\s+(.+)$/' => 'ФЦ_$1',
                '/^(.+)\s+ФЦ$/' => 'ФЦ_$1'
            ],
            
            // City name standardization
            'city_patterns' => [
                '/САНКТ[-\s]*ПЕТЕРБУРГ/' => 'САНКТ_ПЕТЕРБУРГ',
                '/СПБ/' => 'САНКТ_ПЕТЕРБУРГ',
                '/ЕКАТЕРИНБУРГ/' => 'ЕКАТЕРИНБУРГ',
                '/НОВОСИБИРСК/' => 'НОВОСИБИРСК',
                '/НИЖНИЙ\s+НОВГОРОД/' => 'НИЖНИЙ_НОВГОРОД',
                '/РОСТОВ[-\s]*НА[-\s]*ДОНУ/' => 'РОСТОВ_НА_ДОНУ'
            ],
            
            // Cleanup patterns
            'cleanup_patterns' => [
                '/\s+/' => '_',           // Replace spaces with underscores
                '/[-]+/' => '_',          // Replace hyphens with underscores
                '/[_]+/' => '_',          // Collapse multiple underscores
                '/^_+|_+$/' => ''         // Remove leading/trailing underscores
            ]
        ];
    }
    
    /**
     * Apply automatic normalization rules
     * 
     * @param string $name Cleaned warehouse name
     * @return array Result with success flag and normalized name
     */
    private function applyAutoRules(string $name): array {
        $normalized = $name;
        $appliedRules = [];
        
        // Apply pattern-based rules in order
        foreach ($this->autoRules as $category => $patterns) {
            foreach ($patterns as $pattern => $replacement) {
                if (preg_match($pattern, $normalized)) {
                    $oldValue = $normalized;
                    $normalized = preg_replace($pattern, $replacement, $normalized);
                    
                    if ($oldValue !== $normalized) {
                        $appliedRules[] = [
                            'category' => $category,
                            'pattern' => $pattern,
                            'replacement' => $replacement,
                            'before' => $oldValue,
                            'after' => $normalized
                        ];
                    }
                }
            }
        }
        
        // Final cleanup
        $normalized = trim($normalized, '_');
        
        return [
            'success' => !empty($appliedRules),
            'normalized' => $normalized,
            'applied_rules' => $appliedRules
        ];
    }
    
    /**
     * Find exact match in normalization rules
     * 
     * @param string $name Cleaned warehouse name
     * @param string $sourceType Source type
     * @return array|null Matching rule or null
     */
    private function findExactMatch(string $name, string $sourceType): ?array {
        if (!$this->pdo) {
            // Check in-memory rules
            $key = $name . '|' . $sourceType;
            return $this->normalizationRules[$key] ?? null;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT id, normalized_name, confidence_score, match_type
            FROM warehouse_normalization 
            WHERE original_name = ? AND source_type = ? AND is_active = 1
            ORDER BY confidence_score DESC, usage_count DESC
            LIMIT 1
        ");
        $stmt->execute([$name, $sourceType]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Find fuzzy match using similarity algorithms
     * 
     * @param string $name Cleaned warehouse name
     * @param string $sourceType Source type
     * @return array|null Fuzzy match result or null
     */
    private function findFuzzyMatch(string $name, string $sourceType): ?array {
        if (!$this->pdo) {
            return null;
        }
        
        // Check cache first
        $cacheKey = md5($name . $sourceType);
        if (isset($this->fuzzyMatchCache[$cacheKey])) {
            return $this->fuzzyMatchCache[$cacheKey];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                original_name,
                normalized_name,
                confidence_score,
                match_type
            FROM warehouse_normalization 
            WHERE source_type = ? AND is_active = 1
            ORDER BY usage_count DESC
            LIMIT 100
        ");
        $stmt->execute([$sourceType]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $bestMatch = null;
        $bestSimilarity = 0.0;
        $minSimilarity = 0.75; // Minimum similarity threshold
        
        foreach ($candidates as $candidate) {
            $similarity = $this->calculateSimilarity($name, $candidate['original_name']);
            
            if ($similarity > $bestSimilarity && $similarity >= $minSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = [
                    'rule_id' => $candidate['id'],
                    'normalized_name' => $candidate['normalized_name'],
                    'confidence' => min($similarity, (float)$candidate['confidence_score']),
                    'similarity' => $similarity
                ];
            }
        }
        
        // Cache the result
        $this->fuzzyMatchCache[$cacheKey] = $bestMatch;
        
        return $bestMatch;
    }
    
    /**
     * Calculate similarity between two strings using multiple algorithms
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score (0.0 to 1.0)
     */
    private function calculateSimilarity(string $str1, string $str2): float {
        // Levenshtein distance similarity
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $levenshteinSimilarity = 1.0 - (levenshtein($str1, $str2) / $maxLen);
        
        // Jaro-Winkler similarity (if available)
        $jaroSimilarity = 0.0;
        if (function_exists('jaro_winkler')) {
            $jaroSimilarity = jaro_winkler($str1, $str2);
        }
        
        // Substring similarity
        $substringSimilarity = $this->calculateSubstringSimilarity($str1, $str2);
        
        // Weighted average
        $weights = [
            'levenshtein' => 0.4,
            'jaro' => 0.3,
            'substring' => 0.3
        ];
        
        return ($levenshteinSimilarity * $weights['levenshtein']) +
               ($jaroSimilarity * $weights['jaro']) +
               ($substringSimilarity * $weights['substring']);
    }
    
    /**
     * Calculate substring-based similarity
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score (0.0 to 1.0)
     */
    private function calculateSubstringSimilarity(string $str1, string $str2): float {
        $words1 = explode('_', $str1);
        $words2 = explode('_', $str2);
        
        $commonWords = array_intersect($words1, $words2);
        $totalWords = array_unique(array_merge($words1, $words2));
        
        if (empty($totalWords)) {
            return 0.0;
        }
        
        return count($commonWords) / count($totalWords);
    }
    
    /**
     * Generate fallback normalization for unrecognized names
     * 
     * @param string $name Cleaned warehouse name
     * @return string Fallback normalized name
     */
    private function generateFallbackNormalization(string $name): string {
        // Apply basic cleanup rules
        $normalized = $name;
        
        // Replace spaces with underscores
        $normalized = str_replace(' ', '_', $normalized);
        
        // Remove consecutive underscores
        $normalized = preg_replace('/_+/', '_', $normalized);
        
        // Remove leading/trailing underscores
        $normalized = trim($normalized, '_');
        
        // Add prefix if it doesn't have one
        if (!preg_match('/^(РФЦ|МРФЦ|ФЦ|СКЛАД|РЦ|ЛЦ)_/', $normalized)) {
            $normalized = 'СКЛАД_' . $normalized;
        }
        
        return $normalized;
    }
    
    /**
     * Save normalization rule to database
     * 
     * @param string $originalName Original warehouse name
     * @param string $normalizedName Normalized warehouse name
     * @param string $sourceType Source type
     * @param float $confidence Confidence score
     * @param string $matchType Match type
     * @return int Rule ID
     */
    private function saveNormalizationRule(string $originalName, string $normalizedName, string $sourceType, float $confidence, string $matchType): int {
        if (!$this->pdo) {
            // Store in memory if no database
            $key = $originalName . '|' . $sourceType;
            $this->normalizationRules[$key] = [
                'id' => count($this->normalizationRules) + 1,
                'normalized_name' => $normalizedName,
                'confidence_score' => $confidence,
                'match_type' => $matchType
            ];
            return $this->normalizationRules[$key]['id'];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO warehouse_normalization (
                    original_name, normalized_name, source_type, 
                    confidence_score, match_type, usage_count, is_active
                ) VALUES (?, ?, ?, ?, ?, 1, 1)
                ON DUPLICATE KEY UPDATE
                    normalized_name = VALUES(normalized_name),
                    confidence_score = VALUES(confidence_score),
                    match_type = VALUES(match_type),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $originalName,
                $normalizedName,
                $sourceType,
                $confidence,
                $matchType
            ]);
            
            return (int)$this->pdo->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Failed to save normalization rule: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update usage statistics for a normalization rule
     * 
     * @param int $ruleId Rule ID
     */
    private function updateUsageStatistics(int $ruleId): void {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE warehouse_normalization 
                SET usage_count = usage_count + 1, 
                    last_used_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$ruleId]);
        } catch (Exception $e) {
            error_log("Failed to update usage statistics: " . $e->getMessage());
        }
    }
    
    /**
     * Log unrecognized warehouse name
     * 
     * @param string $originalName Original warehouse name
     * @param string $cleanedName Cleaned warehouse name
     * @param string $sourceType Source type
     */
    private function logUnrecognizedWarehouse(string $originalName, string $cleanedName, string $sourceType): void {
        $logEntry = [
            'original_name' => $originalName,
            'cleaned_name' => $cleanedName,
            'source_type' => $sourceType,
            'timestamp' => date('Y-m-d H:i:s'),
            'needs_review' => true
        ];
        
        $this->unrecognizedLog[] = $logEntry;
        
        // Also log to error log for monitoring
        error_log("Unrecognized warehouse name: '{$originalName}' (cleaned: '{$cleanedName}') from source: {$sourceType}");
    }
    
    /**
     * Log batch normalization statistics
     * 
     * @param array $warehouseNames Original warehouse names
     * @param array $results Normalization results
     * @param string $sourceType Source type
     * @param int $executionTime Execution time in milliseconds
     */
    private function logBatchNormalization(array $warehouseNames, array $results, string $sourceType, int $executionTime): void {
        $stats = [
            'total_processed' => count($warehouseNames),
            'exact_matches' => 0,
            'rule_based' => 0,
            'fuzzy_matches' => 0,
            'unrecognized' => 0,
            'avg_confidence' => 0.0,
            'execution_time_ms' => $executionTime
        ];
        
        $totalConfidence = 0.0;
        
        foreach ($results as $result) {
            $totalConfidence += $result->getConfidence();
            
            switch ($result->getMatchType()) {
                case self::MATCH_EXACT:
                    $stats['exact_matches']++;
                    break;
                case self::MATCH_RULE_BASED:
                    $stats['rule_based']++;
                    break;
                case self::MATCH_FUZZY:
                    $stats['fuzzy_matches']++;
                    break;
                case self::MATCH_AUTO_DETECTED:
                    $stats['unrecognized']++;
                    break;
            }
        }
        
        $stats['avg_confidence'] = $stats['total_processed'] > 0 
            ? round($totalConfidence / $stats['total_processed'], 3)
            : 0.0;
        
        error_log("Warehouse normalization batch completed: " . json_encode($stats));
    }
    
    /**
     * Load normalization rules from database
     */
    private function loadNormalizationRules(): void {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->query("
                SELECT original_name, normalized_name, source_type, 
                       confidence_score, match_type, id
                FROM warehouse_normalization 
                WHERE is_active = 1
                ORDER BY usage_count DESC
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['original_name'] . '|' . $row['source_type'];
                $this->normalizationRules[$key] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Failed to load normalization rules: " . $e->getMessage());
        }
    }
    
    /**
     * Create warehouse_normalization table if it doesn't exist
     */
    private function createNormalizationTableIfNotExists(): void {
        // This table should already exist from migration 006
        // But we'll ensure it exists with proper structure
        
        $sql = "
            CREATE TABLE IF NOT EXISTS warehouse_normalization (
                id SERIAL PRIMARY KEY,
                original_name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                source_type VARCHAR(20) NOT NULL,
                confidence_score DECIMAL(3,2) DEFAULT 1.0,
                match_type VARCHAR(20) DEFAULT 'exact',
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                created_by VARCHAR(100) DEFAULT 'system',
                cluster_name VARCHAR(100),
                warehouse_code VARCHAR(50),
                region VARCHAR(100),
                is_active BOOLEAN DEFAULT true,
                usage_count INTEGER DEFAULT 0,
                last_used_at TIMESTAMP WITH TIME ZONE,
                
                UNIQUE(original_name, source_type),
                CONSTRAINT norm_valid_source CHECK (
                    source_type IN ('api', 'ui_report', 'manual', 'auto_detected')
                ),
                CONSTRAINT norm_valid_confidence CHECK (
                    confidence_score >= 0.0 AND confidence_score <= 1.0
                ),
                CONSTRAINT norm_valid_match_type CHECK (
                    match_type IN ('exact', 'fuzzy', 'manual', 'rule_based', 'auto_detected')
                ),
                CONSTRAINT norm_non_empty_names CHECK (
                    LENGTH(TRIM(original_name)) > 0 AND LENGTH(TRIM(normalized_name)) > 0
                )
            )
        ";
        
        try {
            $this->pdo->exec($sql);
            
            // Create indexes if they don't exist
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_norm_original_name ON warehouse_normalization(original_name)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_norm_normalized_name ON warehouse_normalization(normalized_name)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_norm_source_type ON warehouse_normalization(source_type)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_norm_active ON warehouse_normalization(is_active)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_norm_confidence ON warehouse_normalization(confidence_score)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_norm_usage_count ON warehouse_normalization(usage_count)");
            
        } catch (Exception $e) {
            error_log("Failed to create warehouse_normalization table: " . $e->getMessage());
        }
    }
}

/**
 * Normalization result class
 */
class NormalizationResult {
    private string $originalName;
    private string $normalizedName;
    private string $matchType;
    private float $confidence;
    private string $sourceType;
    private array $metadata;
    
    public function __construct(string $originalName, string $normalizedName, string $matchType, float $confidence, string $sourceType, array $metadata = []) {
        $this->originalName = $originalName;
        $this->normalizedName = $normalizedName;
        $this->matchType = $matchType;
        $this->confidence = $confidence;
        $this->sourceType = $sourceType;
        $this->metadata = $metadata;
    }
    
    public function getOriginalName(): string { return $this->originalName; }
    public function getNormalizedName(): string { return $this->normalizedName; }
    public function getMatchType(): string { return $this->matchType; }
    public function getConfidence(): float { return $this->confidence; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getMetadata(): array { return $this->metadata; }
    
    public function isHighConfidence(): bool {
        return $this->confidence >= WarehouseNormalizer::CONFIDENCE_RULE_BASED;
    }
    
    public function needsReview(): bool {
        return $this->confidence < WarehouseNormalizer::CONFIDENCE_FUZZY || 
               isset($this->metadata['needs_review']);
    }
    
    public function wasAutoCreated(): bool {
        return isset($this->metadata['auto_created']) && $this->metadata['auto_created'];
    }
}