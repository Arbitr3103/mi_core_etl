<?php
/**
 * Mock ETL Dependencies for Testing
 * 
 * Provides mock implementations of ETL dependencies to support
 * isolated unit testing of the AnalyticsETL orchestrator.
 * 
 * Task: 9.1 - Реализовать mock объекты для Analytics API
 */

require_once __DIR__ . '/MockAnalyticsApiResponse.php';

class MockETLDependencies {
    
    /**
     * Create mock AnalyticsApiClient
     */
    public static function createMockApiClient(array $responses = []): AnalyticsApiClient {
        $mock = new class($responses) {
            private array $responses;
            private int $callCount = 0;
            
            public function __construct(array $responses) {
                $this->responses = $responses ?: [
                    MockAnalyticsApiResponse::getStockOnWarehousesResponse()
                ];
            }
            
            public function getAllStockData(array $filters = []): Generator {
                foreach ($this->responses as $response) {
                    yield $response;
                    $this->callCount++;
                }
            }
            
            public function getStockOnWarehouses(int $offset = 0, int $limit = 1000, array $filters = []): array {
                $responseIndex = min($this->callCount, count($this->responses) - 1);
                $this->callCount++;
                return $this->responses[$responseIndex];
            }
            
            public function getStats(): array {
                return [
                    'client_id' => 'mock_client_id',
                    'cache_entries' => 0,
                    'request_history_count' => $this->callCount,
                    'rate_limit_per_minute' => 30,
                    'max_retries' => 3,
                    'cache_ttl' => 7200
                ];
            }
            
            public function clearExpiredCache(): int {
                return 0;
            }
            
            public function generateBatchId(): string {
                return 'mock_batch_' . uniqid();
            }
        };
        
        return $mock;
    }
    
    /**
     * Create mock DataValidator
     */
    public static function createMockDataValidator(float $qualityScore = 95.0, array $issues = []): DataValidator {
        $mock = new class($qualityScore, $issues) {
            private float $qualityScore;
            private array $issues;
            
            public function __construct(float $qualityScore, array $issues) {
                $this->qualityScore = $qualityScore;
                $this->issues = $issues;
            }
            
            public function validateBatch(array $data, string $batchId): ValidationResult {
                $totalRecords = count($data);
                $validRecords = $totalRecords - count($this->issues);
                $invalidRecords = count($this->issues);
                
                return new ValidationResult(
                    $batchId,
                    [
                        'total_records' => $totalRecords,
                        'valid_records' => $validRecords,
                        'invalid_records' => $invalidRecords,
                        'warnings' => 0,
                        'anomalies' => 0
                    ],
                    $this->qualityScore,
                    100, // execution time ms
                    []
                );
            }
            
            public function validateRecord(array $record, int $index, string $batchId): RecordValidationResult {
                $isValid = !in_array($index, array_keys($this->issues));
                $status = $isValid ? DataValidator::STATUS_PASSED : DataValidator::STATUS_FAILED;
                $issues = $isValid ? [] : [$this->issues[$index] ?? 'Mock validation error'];
                
                return new RecordValidationResult(
                    $index,
                    $record,
                    $isValid,
                    $status,
                    $issues,
                    []
                );
            }
            
            public function detectAnomalies(array $data): array {
                return [];
            }
            
            public function calculateQualityMetrics(array $data): array {
                return [
                    'completeness' => 95.0,
                    'accuracy' => 100.0,
                    'consistency' => 90.0,
                    'freshness' => 85.0,
                    'validity' => 95.0,
                    'overall_score' => $this->qualityScore
                ];
            }
            
            public function getValidationStatistics(int $days = 7): array {
                return [
                    'total_batches' => 10,
                    'avg_quality_score' => $this->qualityScore,
                    'total_records_validated' => 1000,
                    'total_valid_records' => 950,
                    'avg_execution_time' => 150
                ];
            }
        };
        
        return $mock;
    }
    
    /**
     * Create mock WarehouseNormalizer
     */
    public static function createMockWarehouseNormalizer(array $normalizationRules = []): WarehouseNormalizer {
        $mock = new class($normalizationRules) {
            private array $rules;
            
            public function __construct(array $rules) {
                $this->rules = $rules ?: [
                    'РФЦ МОСКВА' => 'РФЦ_МОСКВА',
                    'РФЦ САНКТ-ПЕТЕРБУРГ' => 'РФЦ_САНКТ_ПЕТЕРБУРГ',
                    'МРФЦ ЕКАТЕРИНБУРГ' => 'МРФЦ_ЕКАТЕРИНБУРГ'
                ];
            }
            
            public function normalize(string $warehouseName, string $sourceType): NormalizationResult {
                $cleaned = strtoupper(trim($warehouseName));
                $normalized = $this->rules[$cleaned] ?? $this->generateFallback($cleaned);
                
                $matchType = isset($this->rules[$cleaned]) 
                    ? WarehouseNormalizer::MATCH_EXACT 
                    : WarehouseNormalizer::MATCH_RULE_BASED;
                    
                $confidence = isset($this->rules[$cleaned]) 
                    ? WarehouseNormalizer::CONFIDENCE_EXACT 
                    : WarehouseNormalizer::CONFIDENCE_RULE_BASED;
                
                return new NormalizationResult(
                    $warehouseName,
                    $normalized,
                    $matchType,
                    $confidence,
                    $sourceType,
                    ['auto_created' => !isset($this->rules[$cleaned])]
                );
            }
            
            public function normalizeBatch(array $warehouseNames, string $sourceType): array {
                $results = [];
                foreach ($warehouseNames as $name) {
                    $results[] = $this->normalize($name, $sourceType);
                }
                return $results;
            }
            
            private function generateFallback(string $name): string {
                // Simple fallback normalization
                $name = preg_replace('/\s+/', '_', $name);
                $name = preg_replace('/[^\w\-_]/', '', $name);
                
                if (strpos($name, 'РФЦ') === false && strpos($name, 'МРФЦ') === false) {
                    $name = 'СКЛАД_' . $name;
                }
                
                return $name;
            }
            
            public function addManualRule(string $original, string $normalized, string $sourceType): int {
                $this->rules[strtoupper(trim($original))] = $normalized;
                return count($this->rules);
            }
            
            public function getNormalizationStatistics(int $days = 7): array {
                return [
                    'total_rules' => count($this->rules),
                    'active_rules' => count($this->rules),
                    'usage_stats' => [],
                    'confidence_distribution' => [
                        'high' => 80,
                        'medium' => 15,
                        'low' => 5
                    ],
                    'source_distribution' => [
                        'api' => 70,
                        'ui_report' => 25,
                        'manual' => 5
                    ]
                ];
            }
            
            public function getUnrecognizedWarehouses(int $limit = 50): array {
                return [];
            }
        };
        
        return $mock;
    }
    
    /**
     * Create mock PDO for database operations
     */
    public static function createMockPDO(bool $shouldFail = false): PDO {
        if ($shouldFail) {
            $mock = new class() {
                public function beginTransaction(): bool {
                    return true;
                }
                
                public function prepare(string $statement): PDOStatement {
                    throw new PDOException('Mock database error');
                }
                
                public function inTransaction(): bool {
                    return true;
                }
                
                public function rollBack(): bool {
                    return true;
                }
                
                public function commit(): bool {
                    return true;
                }
            };
        } else {
            $mock = new class() {
                private int $insertedRows = 0;
                private int $updatedRows = 0;
                
                public function beginTransaction(): bool {
                    return true;
                }
                
                public function prepare(string $statement): PDOStatement {
                    return new class($this) {
                        private $pdo;
                        
                        public function __construct($pdo) {
                            $this->pdo = $pdo;
                        }
                        
                        public function execute(array $params = []): bool {
                            if (strpos($statement ?? '', 'INSERT') !== false) {
                                $this->pdo->insertedRows++;
                            } elseif (strpos($statement ?? '', 'UPDATE') !== false) {
                                $this->pdo->updatedRows++;
                            }
                            return true;
                        }
                        
                        public function rowCount(): int {
                            return 1;
                        }
                        
                        public function fetchColumn(): mixed {
                            return 0; // Simulate new record
                        }
                    };
                }
                
                public function inTransaction(): bool {
                    return false;
                }
                
                public function commit(): bool {
                    return true;
                }
                
                public function rollBack(): bool {
                    return true;
                }
                
                public function getInsertedRows(): int {
                    return $this->insertedRows;
                }
                
                public function getUpdatedRows(): int {
                    return $this->updatedRows;
                }
            };
        }
        
        return $mock;
    }
    
    /**
     * Create mock ETL configuration
     */
    public static function getMockETLConfig(): array {
        return [
            'load_batch_size' => 100,
            'min_quality_score' => 80.0,
            'max_memory_records' => 5000,
            'enable_audit_logging' => false,
            'enable_performance_monitoring' => true,
            'retry_failed_records' => true,
            'max_execution_time' => 3600
        ];
    }
    
    /**
     * Create mock ETL metrics
     */
    public static function getMockETLMetrics(): array {
        return [
            'extract' => [
                'records_extracted' => 1000,
                'api_requests_made' => 10,
                'cache_hits' => 5,
                'cache_misses' => 5,
                'extraction_time_ms' => 2000
            ],
            'transform' => [
                'records_processed' => 1000,
                'records_normalized' => 1000,
                'validation_quality_score' => 95.0,
                'anomalies_detected' => 2,
                'transformation_time_ms' => 1500
            ],
            'load' => [
                'records_inserted' => 800,
                'records_updated' => 180,
                'records_errors' => 20,
                'database_operations' => 980,
                'load_time_ms' => 3000
            ]
        ];
    }
}