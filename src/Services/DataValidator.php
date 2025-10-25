<?php
/**
 * DataValidator - Сервис для валидации данных из Analytics API
 * 
 * Реализует валидацию данных, проверку целостности и качества,
 * detection аномалий в данных складов и качественные метрики.
 * 
 * Requirements: 4.1, 4.2, 4.3, 4.4
 * Task: 4.2 Создать DataValidator сервис (без CSV парсинга)
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

class DataValidator {
    // Validation thresholds
    const QUALITY_SCORE_EXCELLENT = 100;
    const QUALITY_SCORE_GOOD = 90;
    const QUALITY_SCORE_ACCEPTABLE = 80;
    const QUALITY_SCORE_POOR = 50;
    const QUALITY_SCORE_CRITICAL = 0;
    
    // Anomaly detection thresholds
    const STOCK_ANOMALY_THRESHOLD = 1000000; // Unrealistic stock levels
    const PRICE_ANOMALY_THRESHOLD = 1000000; // Unrealistic prices
    const DISCREPANCY_THRESHOLD = 0.10; // 10% discrepancy threshold
    const FRESHNESS_THRESHOLD_HOURS = 24; // Data freshness threshold
    
    // Validation statuses
    const STATUS_PASSED = 'passed';
    const STATUS_WARNING = 'warning';
    const STATUS_FAILED = 'failed';
    const STATUS_MANUAL_REVIEW = 'manual_review';
    const STATUS_RESOLVED = 'resolved';
    
    private ?PDO $pdo;
    private array $validationRules;
    private array $anomalyDetectors;
    private array $qualityMetrics;
    
    /**
     * Constructor
     * 
     * @param PDO|null $pdo Database connection for logging validation results
     */
    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo;
        $this->initializeValidationRules();
        $this->initializeAnomalyDetectors();
        $this->initializeQualityMetrics();
        
        if ($this->pdo) {
            $this->createValidationTablesIfNotExist();
        }
    }
    
    /**
     * Validate Analytics API data batch
     * 
     * @param array $data Array of stock records from Analytics API
     * @param string $batchId Batch identifier for tracking
     * @return ValidationResult Validation results with quality metrics
     */
    public function validateBatch(array $data, string $batchId): ValidationResult {
        $startTime = microtime(true);
        $validationBatchId = $this->generateValidationBatchId($batchId);
        
        $results = [
            'total_records' => count($data),
            'valid_records' => 0,
            'invalid_records' => 0,
            'warnings' => 0,
            'anomalies' => [],
            'quality_issues' => [],
            'validation_details' => []
        ];
        
        foreach ($data as $index => $record) {
            $recordResult = $this->validateRecord($record, $index, $validationBatchId);
            
            $results['validation_details'][] = $recordResult;
            
            switch ($recordResult->getStatus()) {
                case self::STATUS_PASSED:
                    $results['valid_records']++;
                    break;
                case self::STATUS_WARNING:
                    $results['valid_records']++;
                    $results['warnings']++;
                    break;
                case self::STATUS_FAILED:
                case self::STATUS_MANUAL_REVIEW:
                    $results['invalid_records']++;
                    break;
            }
            
            // Collect anomalies
            if ($recordResult->hasAnomalies()) {
                $results['anomalies'] = array_merge($results['anomalies'], $recordResult->getAnomalies());
            }
            
            // Collect quality issues
            if ($recordResult->hasQualityIssues()) {
                $results['quality_issues'] = array_merge($results['quality_issues'], $recordResult->getQualityIssues());
            }
        }
        
        // Calculate overall quality metrics
        $qualityScore = $this->calculateBatchQualityScore($results);
        $executionTime = round((microtime(true) - $startTime) * 1000);
        
        // Log validation results
        $this->logValidationBatch($validationBatchId, $batchId, $results, $qualityScore, $executionTime);
        
        return new ValidationResult(
            $validationBatchId,
            $results,
            $qualityScore,
            $executionTime,
            $this->generateQualityMetrics($results)
        );
    }
    
    /**
     * Validate individual record
     * 
     * @param array $record Stock record data
     * @param int $index Record index in batch
     * @param string $validationBatchId Validation batch identifier
     * @return RecordValidationResult Individual record validation result
     */
    public function validateRecord(array $record, int $index, string $validationBatchId): RecordValidationResult {
        $issues = [];
        $anomalies = [];
        $qualityIssues = [];
        $status = self::STATUS_PASSED;
        
        // Apply validation rules
        foreach ($this->validationRules as $rule) {
            $ruleResult = $rule($record, $index);
            
            if (!$ruleResult['passed']) {
                $issues[] = $ruleResult;
                
                if ($ruleResult['severity'] === 'critical') {
                    $status = self::STATUS_FAILED;
                } elseif ($ruleResult['severity'] === 'warning' && $status === self::STATUS_PASSED) {
                    $status = self::STATUS_WARNING;
                }
            }
        }
        
        // Apply anomaly detection
        foreach ($this->anomalyDetectors as $detector) {
            $anomalyResult = $detector($record, $index);
            
            if ($anomalyResult['detected']) {
                $anomalies[] = $anomalyResult;
                
                if ($anomalyResult['severity'] === 'critical') {
                    $status = self::STATUS_MANUAL_REVIEW;
                }
            }
        }
        
        // Apply quality checks
        foreach ($this->qualityMetrics as $metric) {
            $metricResult = $metric($record, $index);
            
            if ($metricResult['score'] < self::QUALITY_SCORE_ACCEPTABLE) {
                $qualityIssues[] = $metricResult;
            }
        }
        
        // Log individual record validation
        $this->logRecordValidation($validationBatchId, $record, $index, $status, $issues, $anomalies);
        
        return new RecordValidationResult(
            $index,
            $record,
            $status,
            $issues,
            $anomalies,
            $qualityIssues
        );
    }
    
    /**
     * Detect anomalies in warehouse stock data
     * 
     * @param array $data Array of stock records
     * @return array Detected anomalies
     */
    public function detectAnomalies(array $data): array {
        $anomalies = [];
        
        // Statistical analysis for anomaly detection
        $stockLevels = array_column($data, 'available_stock');
        $prices = array_column($data, 'price');
        
        // Calculate statistical measures
        $stockStats = $this->calculateStatistics($stockLevels);
        $priceStats = $this->calculateStatistics($prices);
        
        foreach ($data as $index => $record) {
            // Stock level anomalies
            if ($this->isOutlier($record['available_stock'] ?? 0, $stockStats)) {
                $anomalies[] = [
                    'type' => 'stock_outlier',
                    'record_index' => $index,
                    'sku' => $record['sku'] ?? 'unknown',
                    'warehouse' => $record['warehouse_name'] ?? 'unknown',
                    'value' => $record['available_stock'] ?? 0,
                    'expected_range' => [
                        'min' => $stockStats['q1'] - 1.5 * $stockStats['iqr'],
                        'max' => $stockStats['q3'] + 1.5 * $stockStats['iqr']
                    ],
                    'severity' => $this->determineAnomalySeverity($record['available_stock'] ?? 0, $stockStats),
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
            
            // Price anomalies
            if (isset($record['price']) && $this->isOutlier($record['price'], $priceStats)) {
                $anomalies[] = [
                    'type' => 'price_outlier',
                    'record_index' => $index,
                    'sku' => $record['sku'] ?? 'unknown',
                    'warehouse' => $record['warehouse_name'] ?? 'unknown',
                    'value' => $record['price'],
                    'expected_range' => [
                        'min' => $priceStats['q1'] - 1.5 * $priceStats['iqr'],
                        'max' => $priceStats['q3'] + 1.5 * $priceStats['iqr']
                    ],
                    'severity' => $this->determineAnomalySeverity($record['price'], $priceStats),
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
            
            // Business logic anomalies
            if ($this->hasBusinessLogicAnomalies($record)) {
                $anomalies[] = [
                    'type' => 'business_logic',
                    'record_index' => $index,
                    'sku' => $record['sku'] ?? 'unknown',
                    'warehouse' => $record['warehouse_name'] ?? 'unknown',
                    'issues' => $this->getBusinessLogicIssues($record),
                    'severity' => 'warning',
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        return $anomalies;
    }
    
    /**
     * Calculate quality metrics for Analytics API data
     * 
     * @param array $data Array of stock records
     * @return array Quality metrics
     */
    public function calculateQualityMetrics(array $data): array {
        $metrics = [
            'completeness' => $this->calculateCompleteness($data),
            'accuracy' => $this->calculateAccuracy($data),
            'consistency' => $this->calculateConsistency($data),
            'freshness' => $this->calculateFreshness($data),
            'validity' => $this->calculateValidity($data),
            'overall_score' => 0
        ];
        
        // Calculate weighted overall score
        $weights = [
            'completeness' => 0.25,
            'accuracy' => 0.30,
            'consistency' => 0.20,
            'freshness' => 0.15,
            'validity' => 0.10
        ];
        
        $metrics['overall_score'] = 0;
        foreach ($weights as $metric => $weight) {
            $metrics['overall_score'] += $metrics[$metric] * $weight;
        }
        
        $metrics['overall_score'] = round($metrics['overall_score'], 2);
        
        return $metrics;
    }
    
    /**
     * Initialize validation rules
     */
    private function initializeValidationRules(): void {
        $this->validationRules = [
            // Required fields validation
            'required_fields' => function($record, $index) {
                $requiredFields = ['sku', 'warehouse_name'];
                $missingFields = [];
                
                foreach ($requiredFields as $field) {
                    if (empty($record[$field])) {
                        $missingFields[] = $field;
                    }
                }
                
                return [
                    'rule' => 'required_fields',
                    'passed' => empty($missingFields),
                    'message' => empty($missingFields) ? 'All required fields present' : 'Missing required fields: ' . implode(', ', $missingFields),
                    'severity' => 'critical',
                    'details' => ['missing_fields' => $missingFields]
                ];
            },
            
            // SKU format validation
            'sku_format' => function($record, $index) {
                $sku = $record['sku'] ?? '';
                $isValid = !empty($sku) && is_string($sku) && strlen($sku) <= 255;
                
                return [
                    'rule' => 'sku_format',
                    'passed' => $isValid,
                    'message' => $isValid ? 'SKU format valid' : 'Invalid SKU format',
                    'severity' => 'critical',
                    'details' => ['sku' => $sku, 'length' => strlen($sku)]
                ];
            },
            
            // Stock values validation
            'stock_values' => function($record, $index) {
                $availableStock = $record['available_stock'] ?? 0;
                $reservedStock = $record['reserved_stock'] ?? 0;
                $totalStock = $record['total_stock'] ?? 0;
                
                $issues = [];
                
                if ($availableStock < 0) {
                    $issues[] = 'Available stock cannot be negative';
                }
                
                if ($reservedStock < 0) {
                    $issues[] = 'Reserved stock cannot be negative';
                }
                
                if ($totalStock < 0) {
                    $issues[] = 'Total stock cannot be negative';
                }
                
                if ($totalStock > 0 && ($availableStock + $reservedStock) > $totalStock * 1.1) {
                    $issues[] = 'Available + Reserved exceeds Total stock by more than 10%';
                }
                
                return [
                    'rule' => 'stock_values',
                    'passed' => empty($issues),
                    'message' => empty($issues) ? 'Stock values valid' : implode('; ', $issues),
                    'severity' => empty($issues) ? 'info' : 'warning',
                    'details' => [
                        'available' => $availableStock,
                        'reserved' => $reservedStock,
                        'total' => $totalStock,
                        'issues' => $issues
                    ]
                ];
            },
            
            // Price validation
            'price_validation' => function($record, $index) {
                $price = $record['price'] ?? 0;
                $issues = [];
                
                if ($price < 0) {
                    $issues[] = 'Price cannot be negative';
                }
                
                if ($price > self::PRICE_ANOMALY_THRESHOLD) {
                    $issues[] = 'Price exceeds reasonable threshold';
                }
                
                return [
                    'rule' => 'price_validation',
                    'passed' => empty($issues),
                    'message' => empty($issues) ? 'Price valid' : implode('; ', $issues),
                    'severity' => empty($issues) ? 'info' : 'warning',
                    'details' => ['price' => $price, 'issues' => $issues]
                ];
            },
            
            // Warehouse name validation
            'warehouse_name' => function($record, $index) {
                $warehouseName = $record['warehouse_name'] ?? '';
                $isValid = !empty($warehouseName) && is_string($warehouseName) && strlen(trim($warehouseName)) > 0;
                
                return [
                    'rule' => 'warehouse_name',
                    'passed' => $isValid,
                    'message' => $isValid ? 'Warehouse name valid' : 'Invalid warehouse name',
                    'severity' => 'critical',
                    'details' => ['warehouse_name' => $warehouseName]
                ];
            }
        ];
    }
    
    /**
     * Initialize anomaly detectors
     */
    private function initializeAnomalyDetectors(): void {
        $this->anomalyDetectors = [
            // Extreme stock levels
            'extreme_stock' => function($record, $index) {
                $availableStock = $record['available_stock'] ?? 0;
                $detected = $availableStock > self::STOCK_ANOMALY_THRESHOLD;
                
                return [
                    'detector' => 'extreme_stock',
                    'detected' => $detected,
                    'message' => $detected ? 'Extremely high stock level detected' : 'Stock level normal',
                    'severity' => $detected ? 'warning' : 'info',
                    'value' => $availableStock,
                    'threshold' => self::STOCK_ANOMALY_THRESHOLD
                ];
            },
            
            // Zero stock with reserved items
            'zero_stock_reserved' => function($record, $index) {
                $availableStock = $record['available_stock'] ?? 0;
                $reservedStock = $record['reserved_stock'] ?? 0;
                $detected = $availableStock == 0 && $reservedStock > 0;
                
                return [
                    'detector' => 'zero_stock_reserved',
                    'detected' => $detected,
                    'message' => $detected ? 'Zero available stock but has reserved items' : 'Stock allocation normal',
                    'severity' => $detected ? 'warning' : 'info',
                    'available' => $availableStock,
                    'reserved' => $reservedStock
                ];
            },
            
            // Suspicious price patterns
            'suspicious_price' => function($record, $index) {
                $price = $record['price'] ?? 0;
                $detected = ($price > 0 && $price < 1) || $price > 500000;
                
                return [
                    'detector' => 'suspicious_price',
                    'detected' => $detected,
                    'message' => $detected ? 'Suspicious price detected' : 'Price normal',
                    'severity' => $detected ? 'warning' : 'info',
                    'price' => $price
                ];
            }
        ];
    }
    
    /**
     * Initialize quality metrics calculators
     */
    private function initializeQualityMetrics(): void {
        $this->qualityMetrics = [
            // Data completeness
            'completeness' => function($record, $index) {
                $requiredFields = ['sku', 'warehouse_name', 'available_stock'];
                $optionalFields = ['product_name', 'category', 'brand', 'price'];
                
                $completedRequired = 0;
                $completedOptional = 0;
                
                foreach ($requiredFields as $field) {
                    if (!empty($record[$field])) {
                        $completedRequired++;
                    }
                }
                
                foreach ($optionalFields as $field) {
                    if (!empty($record[$field])) {
                        $completedOptional++;
                    }
                }
                
                $score = ($completedRequired / count($requiredFields)) * 70 + 
                        ($completedOptional / count($optionalFields)) * 30;
                
                return [
                    'metric' => 'completeness',
                    'score' => round($score, 2),
                    'details' => [
                        'required_completed' => $completedRequired,
                        'required_total' => count($requiredFields),
                        'optional_completed' => $completedOptional,
                        'optional_total' => count($optionalFields)
                    ]
                ];
            }
        ];
    }
    
    /**
     * Calculate statistics for anomaly detection
     * 
     * @param array $values Numeric values
     * @return array Statistical measures
     */
    private function calculateStatistics(array $values): array {
        if (empty($values)) {
            return ['mean' => 0, 'median' => 0, 'q1' => 0, 'q3' => 0, 'iqr' => 0, 'std' => 0];
        }
        
        $values = array_filter($values, 'is_numeric');
        sort($values);
        
        $count = count($values);
        $mean = array_sum($values) / $count;
        
        $median = $count % 2 == 0 
            ? ($values[$count/2 - 1] + $values[$count/2]) / 2
            : $values[floor($count/2)];
        
        $q1 = $values[floor($count * 0.25)];
        $q3 = $values[floor($count * 0.75)];
        $iqr = $q3 - $q1;
        
        // Standard deviation
        $variance = array_sum(array_map(function($x) use ($mean) { return pow($x - $mean, 2); }, $values)) / $count;
        $std = sqrt($variance);
        
        return [
            'mean' => $mean,
            'median' => $median,
            'q1' => $q1,
            'q3' => $q3,
            'iqr' => $iqr,
            'std' => $std,
            'min' => min($values),
            'max' => max($values),
            'count' => $count
        ];
    }
    
    /**
     * Check if value is an outlier using IQR method
     * 
     * @param float $value Value to check
     * @param array $stats Statistical measures
     * @return bool True if outlier
     */
    private function isOutlier(float $value, array $stats): bool {
        $lowerBound = $stats['q1'] - 1.5 * $stats['iqr'];
        $upperBound = $stats['q3'] + 1.5 * $stats['iqr'];
        
        return $value < $lowerBound || $value > $upperBound;
    }
    
    /**
     * Determine anomaly severity
     * 
     * @param float $value Anomalous value
     * @param array $stats Statistical measures
     * @return string Severity level
     */
    private function determineAnomalySeverity(float $value, array $stats): string {
        $extremeLowerBound = $stats['q1'] - 3 * $stats['iqr'];
        $extremeUpperBound = $stats['q3'] + 3 * $stats['iqr'];
        
        if ($value < $extremeLowerBound || $value > $extremeUpperBound) {
            return 'critical';
        }
        
        return 'warning';
    }
    
    /**
     * Check for business logic anomalies
     * 
     * @param array $record Stock record
     * @return bool True if anomalies detected
     */
    private function hasBusinessLogicAnomalies(array $record): bool {
        $issues = $this->getBusinessLogicIssues($record);
        return !empty($issues);
    }
    
    /**
     * Get business logic issues
     * 
     * @param array $record Stock record
     * @return array List of issues
     */
    private function getBusinessLogicIssues(array $record): array {
        $issues = [];
        
        // Check for impossible stock combinations
        $available = $record['available_stock'] ?? 0;
        $reserved = $record['reserved_stock'] ?? 0;
        $total = $record['total_stock'] ?? 0;
        
        if ($total > 0 && ($available + $reserved) == 0) {
            $issues[] = 'Total stock > 0 but no available or reserved stock';
        }
        
        if ($available > $total && $total > 0) {
            $issues[] = 'Available stock exceeds total stock';
        }
        
        if ($reserved > $total && $total > 0) {
            $issues[] = 'Reserved stock exceeds total stock';
        }
        
        // Check for suspicious product names
        $productName = $record['product_name'] ?? '';
        if (!empty($productName) && (strlen($productName) < 3 || preg_match('/^[0-9]+$/', $productName))) {
            $issues[] = 'Suspicious product name format';
        }
        
        return $issues;
    }
    
    /**
     * Calculate completeness metric
     * 
     * @param array $data Array of records
     * @return float Completeness score (0-100)
     */
    private function calculateCompleteness(array $data): float {
        if (empty($data)) {
            return 0;
        }
        
        $requiredFields = ['sku', 'warehouse_name', 'available_stock'];
        $optionalFields = ['product_name', 'category', 'brand', 'price'];
        
        $totalScore = 0;
        
        foreach ($data as $record) {
            $requiredComplete = 0;
            $optionalComplete = 0;
            
            foreach ($requiredFields as $field) {
                if (!empty($record[$field])) {
                    $requiredComplete++;
                }
            }
            
            foreach ($optionalFields as $field) {
                if (!empty($record[$field])) {
                    $optionalComplete++;
                }
            }
            
            $recordScore = ($requiredComplete / count($requiredFields)) * 70 + 
                          ($optionalComplete / count($optionalFields)) * 30;
            
            $totalScore += $recordScore;
        }
        
        return round($totalScore / count($data), 2);
    }
    
    /**
     * Calculate accuracy metric (API data is considered 100% accurate)
     * 
     * @param array $data Array of records
     * @return float Accuracy score (0-100)
     */
    private function calculateAccuracy(array $data): float {
        // Analytics API data is considered highly accurate
        return 100.0;
    }
    
    /**
     * Calculate consistency metric
     * 
     * @param array $data Array of records
     * @return float Consistency score (0-100)
     */
    private function calculateConsistency(array $data): float {
        if (empty($data)) {
            return 100;
        }
        
        $inconsistencies = 0;
        $totalChecks = 0;
        
        foreach ($data as $record) {
            // Check stock consistency
            $available = $record['available_stock'] ?? 0;
            $reserved = $record['reserved_stock'] ?? 0;
            $total = $record['total_stock'] ?? 0;
            
            $totalChecks++;
            if ($total > 0 && abs(($available + $reserved) - $total) > $total * 0.1) {
                $inconsistencies++;
            }
            
            // Check price consistency
            if (isset($record['price'])) {
                $totalChecks++;
                if ($record['price'] < 0) {
                    $inconsistencies++;
                }
            }
        }
        
        if ($totalChecks == 0) {
            return 100;
        }
        
        return round((1 - ($inconsistencies / $totalChecks)) * 100, 2);
    }
    
    /**
     * Calculate freshness metric
     * 
     * @param array $data Array of records
     * @return float Freshness score (0-100)
     */
    private function calculateFreshness(array $data): float {
        if (empty($data)) {
            return 0;
        }
        
        $now = time();
        $totalFreshness = 0;
        
        foreach ($data as $record) {
            $updatedAt = $record['updated_at'] ?? date('Y-m-d H:i:s');
            $timestamp = strtotime($updatedAt);
            
            if ($timestamp === false) {
                $timestamp = $now; // Assume current time if parsing fails
            }
            
            $ageHours = ($now - $timestamp) / 3600;
            
            if ($ageHours <= 1) {
                $freshness = 100;
            } elseif ($ageHours <= 6) {
                $freshness = 90;
            } elseif ($ageHours <= 24) {
                $freshness = 80;
            } elseif ($ageHours <= 72) {
                $freshness = 60;
            } else {
                $freshness = 30;
            }
            
            $totalFreshness += $freshness;
        }
        
        return round($totalFreshness / count($data), 2);
    }
    
    /**
     * Calculate validity metric
     * 
     * @param array $data Array of records
     * @return float Validity score (0-100)
     */
    private function calculateValidity(array $data): float {
        if (empty($data)) {
            return 0;
        }
        
        $validRecords = 0;
        
        foreach ($data as $record) {
            $isValid = true;
            
            // Check required fields
            if (empty($record['sku']) || empty($record['warehouse_name'])) {
                $isValid = false;
            }
            
            // Check data types and ranges
            $available = $record['available_stock'] ?? 0;
            $reserved = $record['reserved_stock'] ?? 0;
            $total = $record['total_stock'] ?? 0;
            
            if (!is_numeric($available) || !is_numeric($reserved) || !is_numeric($total)) {
                $isValid = false;
            }
            
            if ($available < 0 || $reserved < 0 || $total < 0) {
                $isValid = false;
            }
            
            if ($isValid) {
                $validRecords++;
            }
        }
        
        return round(($validRecords / count($data)) * 100, 2);
    }
    
    /**
     * Calculate batch quality score
     * 
     * @param array $results Validation results
     * @return float Quality score (0-100)
     */
    private function calculateBatchQualityScore(array $results): float {
        if ($results['total_records'] == 0) {
            return 0;
        }
        
        $validRatio = $results['valid_records'] / $results['total_records'];
        $warningPenalty = ($results['warnings'] / $results['total_records']) * 0.1;
        $anomalyPenalty = (count($results['anomalies']) / $results['total_records']) * 0.2;
        
        $score = ($validRatio * 100) - ($warningPenalty * 100) - ($anomalyPenalty * 100);
        
        return max(0, round($score, 2));
    }
    
    /**
     * Generate quality metrics summary
     * 
     * @param array $results Validation results
     * @return array Quality metrics
     */
    private function generateQualityMetrics(array $results): array {
        return [
            'validation_pass_rate' => $results['total_records'] > 0 
                ? round(($results['valid_records'] / $results['total_records']) * 100, 2) 
                : 0,
            'warning_rate' => $results['total_records'] > 0 
                ? round(($results['warnings'] / $results['total_records']) * 100, 2) 
                : 0,
            'anomaly_rate' => $results['total_records'] > 0 
                ? round((count($results['anomalies']) / $results['total_records']) * 100, 2) 
                : 0,
            'quality_issue_rate' => $results['total_records'] > 0 
                ? round((count($results['quality_issues']) / $results['total_records']) * 100, 2) 
                : 0
        ];
    }
    
    /**
     * Create validation tables if they don't exist
     */
    private function createValidationTablesIfNotExist(): void {
        // Create data_quality_log table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS data_quality_log (
                id SERIAL PRIMARY KEY,
                validation_batch_id VARCHAR(255) NOT NULL,
                warehouse_name VARCHAR(255),
                product_id INTEGER,
                sku VARCHAR(255),
                issue_type VARCHAR(100) NOT NULL,
                issue_description TEXT,
                validation_status VARCHAR(50) NOT NULL,
                resolution_action VARCHAR(100),
                quality_score INTEGER CHECK (quality_score >= 0 AND quality_score <= 100),
                validated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP WITH TIME ZONE,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                
                CONSTRAINT quality_valid_status CHECK (
                    validation_status IN ('passed', 'warning', 'failed', 'manual_review', 'resolved')
                ),
                
                INDEX idx_quality_batch_id (validation_batch_id),
                INDEX idx_quality_warehouse (warehouse_name),
                INDEX idx_quality_status (validation_status),
                INDEX idx_quality_validated_at (validated_at)
            )
        ");
        
        // Create validation_batches table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS validation_batches (
                id SERIAL PRIMARY KEY,
                validation_batch_id VARCHAR(255) UNIQUE NOT NULL,
                source_batch_id VARCHAR(255),
                total_records INTEGER DEFAULT 0,
                valid_records INTEGER DEFAULT 0,
                invalid_records INTEGER DEFAULT 0,
                warnings INTEGER DEFAULT 0,
                anomalies INTEGER DEFAULT 0,
                quality_score DECIMAL(5,2) DEFAULT 0,
                execution_time_ms INTEGER DEFAULT 0,
                status VARCHAR(50) DEFAULT 'completed',
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_validation_batch_id (validation_batch_id),
                INDEX idx_source_batch_id (source_batch_id),
                INDEX idx_validation_created_at (created_at)
            )
        ");
    }
    
    /**
     * Log validation batch results
     * 
     * @param string $validationBatchId Validation batch ID
     * @param string $sourceBatchId Source batch ID
     * @param array $results Validation results
     * @param float $qualityScore Overall quality score
     * @param int $executionTime Execution time in milliseconds
     */
    private function logValidationBatch(string $validationBatchId, string $sourceBatchId, array $results, float $qualityScore, int $executionTime): void {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO validation_batches (
                    validation_batch_id, source_batch_id, total_records, valid_records, 
                    invalid_records, warnings, anomalies, quality_score, execution_time_ms
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $validationBatchId,
                $sourceBatchId,
                $results['total_records'],
                $results['valid_records'],
                $results['invalid_records'],
                $results['warnings'],
                count($results['anomalies']),
                $qualityScore,
                $executionTime
            ]);
        } catch (Exception $e) {
            error_log("Failed to log validation batch: " . $e->getMessage());
        }
    }
    
    /**
     * Log individual record validation
     * 
     * @param string $validationBatchId Validation batch ID
     * @param array $record Stock record
     * @param int $index Record index
     * @param string $status Validation status
     * @param array $issues Validation issues
     * @param array $anomalies Detected anomalies
     */
    private function logRecordValidation(string $validationBatchId, array $record, int $index, string $status, array $issues, array $anomalies): void {
        if (!$this->pdo || ($status === self::STATUS_PASSED && empty($issues) && empty($anomalies))) {
            return; // Only log problematic records to reduce noise
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO data_quality_log (
                    validation_batch_id, warehouse_name, sku, issue_type, 
                    issue_description, validation_status, quality_score
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Log validation issues
            foreach ($issues as $issue) {
                $stmt->execute([
                    $validationBatchId,
                    $record['warehouse_name'] ?? null,
                    $record['sku'] ?? null,
                    $issue['rule'],
                    $issue['message'],
                    $status,
                    $this->calculateRecordQualityScore($status, $issues, $anomalies)
                ]);
            }
            
            // Log anomalies
            foreach ($anomalies as $anomaly) {
                $stmt->execute([
                    $validationBatchId,
                    $record['warehouse_name'] ?? null,
                    $record['sku'] ?? null,
                    $anomaly['detector'],
                    $anomaly['message'],
                    $status,
                    $this->calculateRecordQualityScore($status, $issues, $anomalies)
                ]);
            }
        } catch (Exception $e) {
            error_log("Failed to log record validation: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate quality score for individual record
     * 
     * @param string $status Validation status
     * @param array $issues Validation issues
     * @param array $anomalies Detected anomalies
     * @return int Quality score (0-100)
     */
    private function calculateRecordQualityScore(string $status, array $issues, array $anomalies): int {
        switch ($status) {
            case self::STATUS_PASSED:
                return empty($issues) && empty($anomalies) ? 100 : 90;
            case self::STATUS_WARNING:
                return 80;
            case self::STATUS_FAILED:
                return 50;
            case self::STATUS_MANUAL_REVIEW:
                return 30;
            default:
                return 0;
        }
    }
    
    /**
     * Generate validation batch ID
     * 
     * @param string $sourceBatchId Source batch ID
     * @return string Validation batch ID
     */
    private function generateValidationBatchId(string $sourceBatchId): string {
        return 'validation_' . $sourceBatchId . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Get validation statistics
     * 
     * @param int $days Number of days to look back
     * @return array Validation statistics
     */
    public function getValidationStatistics(int $days = 7): array {
        if (!$this->pdo) {
            return [];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_batches,
                AVG(quality_score) as avg_quality_score,
                SUM(total_records) as total_records_validated,
                SUM(valid_records) as total_valid_records,
                SUM(warnings) as total_warnings,
                SUM(anomalies) as total_anomalies,
                AVG(execution_time_ms) as avg_execution_time
            FROM validation_batches 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $stmt->execute([$days]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

/**
 * Validation result for entire batch
 */
class ValidationResult {
    private string $validationBatchId;
    private array $results;
    private float $qualityScore;
    private int $executionTime;
    private array $qualityMetrics;
    
    public function __construct(string $validationBatchId, array $results, float $qualityScore, int $executionTime, array $qualityMetrics) {
        $this->validationBatchId = $validationBatchId;
        $this->results = $results;
        $this->qualityScore = $qualityScore;
        $this->executionTime = $executionTime;
        $this->qualityMetrics = $qualityMetrics;
    }
    
    public function getValidationBatchId(): string { return $this->validationBatchId; }
    public function getResults(): array { return $this->results; }
    public function getQualityScore(): float { return $this->qualityScore; }
    public function getExecutionTime(): int { return $this->executionTime; }
    public function getQualityMetrics(): array { return $this->qualityMetrics; }
    
    public function isValid(): bool {
        return $this->results['invalid_records'] == 0;
    }
    
    public function hasWarnings(): bool {
        return $this->results['warnings'] > 0;
    }
    
    public function hasAnomalies(): bool {
        return count($this->results['anomalies']) > 0;
    }
}

/**
 * Validation result for individual record
 */
class RecordValidationResult {
    private int $index;
    private array $record;
    private string $status;
    private array $issues;
    private array $anomalies;
    private array $qualityIssues;
    
    public function __construct(int $index, array $record, string $status, array $issues, array $anomalies, array $qualityIssues) {
        $this->index = $index;
        $this->record = $record;
        $this->status = $status;
        $this->issues = $issues;
        $this->anomalies = $anomalies;
        $this->qualityIssues = $qualityIssues;
    }
    
    public function getIndex(): int { return $this->index; }
    public function getRecord(): array { return $this->record; }
    public function getStatus(): string { return $this->status; }
    public function getIssues(): array { return $this->issues; }
    public function getAnomalies(): array { return $this->anomalies; }
    public function getQualityIssues(): array { return $this->qualityIssues; }
    
    public function isValid(): bool {
        return in_array($this->status, [DataValidator::STATUS_PASSED, DataValidator::STATUS_WARNING]);
    }
    
    public function hasAnomalies(): bool {
        return !empty($this->anomalies);
    }
    
    public function hasQualityIssues(): bool {
        return !empty($this->qualityIssues);
    }
}