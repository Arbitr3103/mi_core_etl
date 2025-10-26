<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Exception;
use MiCore\ETL\Ozon\Components\SalesETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;
use MiCore\ETL\Ozon\Core\ETLResult;

/**
 * Unit Tests for SalesETL Component
 * 
 * Tests all methods of SalesETL with mock data and error scenarios
 * according to requirements 2.1, 2.2, 2.3, 2.4, 2.5
 */
class SalesETLTest extends TestCase
{
    private MockObject $mockDb;
    private MockObject $mockLogger;
    private MockObject $mockApiClient;
    private SalesETL $salesETL;
    private array $testConfig;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mocks
        $this->mockDb = $this->createMock(DatabaseConnection::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockApiClient = $this->createMock(OzonApiClient::class);
        
        // Test configuration
        $this->testConfig = [
            'batch_size' => 100,
            'days_back' => 30,
            'enable_progress' => false,
            'incremental_load' => true
        ];
        
        // Create SalesETL instance
        $this->salesETL = new SalesETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->testConfig
        );
    }

    /**
     * Test successful sales extraction with pagination
     * Requirements: 2.1, 2.2
     */
    public function testExtractSuccessWithPagination(): void
    {
        // Mock API responses for pagination
        $firstResponse = [
            'result' => [
                'postings' => [
                    [
                        'posting_number' => 'ORDER-001',
                        'in_process_at' => '2023-01-01T10:00:00Z',
                        'warehouse_id' => 'WH-001',
                        'products' => [
                            [
                                'sku' => 'TEST-001',
                                'quantity' => 2,
                                'price' => 100.50
                            ],
                            [
                                'sku' => 'TEST-002',
                                'quantity' => 1,
                                'price' => 50.25
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $secondResponse = [
            'result' => [
                'postings' => [
                    [
                        'posting_number' => 'ORDER-002',
                        'in_process_at' => '2023-01-01T11:00:00Z',
                        'warehouse_id' => 'WH-002',
                        'products' => [
                            [
                                'sku' => 'TEST-003',
                                'quantity' => 3,
                                'price' => 75.00
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $thirdResponse = [
            'result' => [
                'postings' => [] // Empty response indicates end of pagination
            ]
        ];
        
        // Set up API client expectations
        $this->mockApiClient->expects($this->exactly(3))
            ->method('getSalesHistory')
            ->withConsecutive(
                [$this->isType('string'), $this->isType('string'), 100, 0],
                [$this->isType('string'), $this->isType('string'), 100, 100],
                [$this->isType('string'), $this->isType('string'), 100, 200]
            )
            ->willReturnOnConsecutiveCalls($firstResponse, $secondResponse, $thirdResponse);
        
        // Execute extraction
        $result = $this->salesETL->extract();
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('ORDER-001', $result[0]['posting_number']);
        $this->assertEquals('ORDER-002', $result[1]['posting_number']);
        $this->assertCount(2, $result[0]['products']);
        $this->assertCount(1, $result[1]['products']);
    }

    /**
     * Test extraction with API error
     */
    public function testExtractWithApiError(): void
    {
        // Mock API client to throw exception
        $this->mockApiClient->expects($this->once())
            ->method('getSalesHistory')
            ->willThrowException(new Exception('API connection failed'));
        
        // Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Sales extraction failed after 0 batches: API connection failed');
        
        // Execute extraction
        $this->salesETL->extract();
    }

    /**
     * Test successful data transformation with products array processing
     * Requirements: 2.3, 2.4
     */
    public function testTransformSuccess(): void
    {
        $rawData = [
            [
                'posting_number' => 'ORDER-001',
                'in_process_at' => '2023-01-01T10:00:00Z',
                'warehouse_id' => 'WH-001',
                'products' => [
                    [
                        'sku' => 'TEST-001',
                        'quantity' => 2,
                        'price' => 100.50
                    ],
                    [
                        'sku' => 'TEST-002',
                        'quantity' => 1,
                        'price' => 50.25
                    ]
                ]
            ],
            [
                'posting_number' => 'ORDER-002',
                'in_process_at' => '2023-01-01T11:00:00Z',
                'warehouse_id' => null, // Test null warehouse
                'products' => [
                    [
                        'sku' => '  TEST-003  ', // Test trimming
                        'quantity' => '3', // Test string to int conversion
                        'price' => null // Test null price
                    ]
                ]
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(3, $result); // 2 products from first order + 1 from second
        
        // Check first order items
        $this->assertEquals('ORDER-001', $result[0]['posting_number']);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
        $this->assertEquals(2, $result[0]['quantity']);
        $this->assertEquals(100.50, $result[0]['price']);
        $this->assertEquals('WH-001', $result[0]['warehouse_id']);
        $this->assertEquals('2023-01-01 10:00:00', $result[0]['in_process_at']);
        
        $this->assertEquals('ORDER-001', $result[1]['posting_number']);
        $this->assertEquals('TEST-002', $result[1]['offer_id']);
        $this->assertEquals(1, $result[1]['quantity']);
        $this->assertEquals(50.25, $result[1]['price']);
        
        // Check second order item (with normalization)
        $this->assertEquals('ORDER-002', $result[2]['posting_number']);
        $this->assertEquals('TEST-003', $result[2]['offer_id']); // Should be trimmed
        $this->assertEquals(3, $result[2]['quantity']); // Should be converted to int
        $this->assertNull($result[2]['price']); // Should remain null
        $this->assertNull($result[2]['warehouse_id']); // Should remain null
        $this->assertIsString($result[2]['created_at']);
    }

    /**
     * Test transformation with invalid order data
     */
    public function testTransformWithInvalidOrderData(): void
    {
        $rawData = [
            [
                'posting_number' => 'ORDER-001',
                'products' => [
                    [
                        'sku' => 'TEST-001',
                        'quantity' => 1
                    ]
                ]
            ],
            [
                // Missing posting_number
                'products' => [
                    [
                        'sku' => 'TEST-002',
                        'quantity' => 1
                    ]
                ]
            ],
            [
                'posting_number' => 'ORDER-003',
                // Missing products array
            ],
            [
                'posting_number' => 'ORDER-004',
                'products' => [] // Empty products array
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        // Should only return items from the valid order
        $this->assertCount(1, $result);
        $this->assertEquals('ORDER-001', $result[0]['posting_number']);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
    }

    /**
     * Test transformation with invalid product data
     */
    public function testTransformWithInvalidProductData(): void
    {
        $rawData = [
            [
                'posting_number' => 'ORDER-001',
                'products' => [
                    [
                        'sku' => 'TEST-001',
                        'quantity' => 2 // Valid product
                    ],
                    [
                        // Missing sku
                        'quantity' => 1
                    ],
                    [
                        'sku' => 'TEST-003',
                        // Missing quantity
                    ],
                    [
                        'sku' => '',
                        'quantity' => 1 // Empty sku
                    ],
                    [
                        'sku' => 'TEST-005',
                        'quantity' => 0 // Invalid quantity (must be positive)
                    ],
                    [
                        'sku' => 'TEST-006',
                        'quantity' => 'invalid' // Non-numeric quantity
                    ]
                ]
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        // Should only return the valid product
        $this->assertCount(1, $result);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
        $this->assertEquals(2, $result[0]['quantity']);
    }

    /**
     * Test transformation with duplicate order items
     */
    public function testTransformWithDuplicateOrderItems(): void
    {
        $rawData = [
            [
                'posting_number' => 'ORDER-001',
                'products' => [
                    [
                        'sku' => 'DUPLICATE-001',
                        'quantity' => 1
                    ]
                ]
            ],
            [
                'posting_number' => 'ORDER-001', // Same posting number
                'products' => [
                    [
                        'sku' => 'DUPLICATE-001', // Same SKU - creates duplicate key
                        'quantity' => 2
                    ]
                ]
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        // Should return both items (duplicates are logged but not filtered in transform)
        $this->assertCount(2, $result);
        $this->assertEquals('ORDER-001', $result[0]['posting_number']);
        $this->assertEquals('ORDER-001', $result[1]['posting_number']);
        $this->assertEquals('DUPLICATE-001', $result[0]['offer_id']);
        $this->assertEquals('DUPLICATE-001', $result[1]['offer_id']);
    }

    /**
     * Test successful incremental loading
     * Requirements: 2.4, 2.5
     */
    public function testLoadSuccessIncremental(): void
    {
        $transformedData = [
            [
                'posting_number' => 'ORDER-001',
                'offer_id' => 'TEST-001',
                'quantity' => 2,
                'price' => 100.50,
                'warehouse_id' => 'WH-001',
                'in_process_at' => '2023-01-01 10:00:00',
                'created_at' => '2023-01-01 12:00:00'
            ],
            [
                'posting_number' => 'ORDER-002',
                'offer_id' => 'TEST-002',
                'quantity' => 1,
                'price' => 50.25,
                'warehouse_id' => 'WH-002',
                'in_process_at' => '2023-01-01 11:00:00',
                'created_at' => '2023-01-01 12:00:00'
            ]
        ];
        
        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        // Mock existing keys check (no existing records)
        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                // Check for existing keys
                [$this->stringContains('SELECT DISTINCT posting_number, offer_id FROM fact_orders')],
                // Insert new records
                [$this->stringContains('INSERT INTO fact_orders')]
            )
            ->willReturnOnConsecutiveCalls(
                [], // No existing records
                [ // Insert results
                    ['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001'],
                    ['posting_number' => 'ORDER-002', 'offer_id' => 'TEST-002']
                ]
            );
        
        // Mock verification query
        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([
                ['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001', 'created_at' => '2023-01-01 12:00:00'],
                ['posting_number' => 'ORDER-002', 'offer_id' => 'TEST-002', 'created_at' => '2023-01-01 12:00:00']
            ]);
        
        $this->mockDb->expects($this->once())
            ->method('commit');
        
        // Execute loading
        $this->salesETL->load($transformedData);
        
        // Verify metrics were updated
        $metrics = $this->salesETL->getMetrics();
        $this->assertEquals(2, $metrics['records_loaded']);
        $this->assertEquals(2, $metrics['records_inserted']);
    }

    /**
     * Test incremental loading with existing records
     */
    public function testLoadIncrementalWithExistingRecords(): void
    {
        $transformedData = [
            [
                'posting_number' => 'ORDER-001',
                'offer_id' => 'TEST-001',
                'quantity' => 2,
                'price' => 100.50,
                'warehouse_id' => 'WH-001',
                'in_process_at' => '2023-01-01 10:00:00',
                'created_at' => '2023-01-01 12:00:00'
            ],
            [
                'posting_number' => 'ORDER-002',
                'offer_id' => 'TEST-002',
                'quantity' => 1,
                'price' => 50.25,
                'warehouse_id' => 'WH-002',
                'in_process_at' => '2023-01-01 11:00:00',
                'created_at' => '2023-01-01 12:00:00'
            ]
        ];
        
        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        // Mock existing keys check (ORDER-001|TEST-001 already exists)
        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                // Check for existing keys
                [$this->stringContains('SELECT DISTINCT posting_number, offer_id FROM fact_orders')],
                // Insert only new records
                [$this->stringContains('INSERT INTO fact_orders')]
            )
            ->willReturnOnConsecutiveCalls(
                [['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001']], // Existing record
                [['posting_number' => 'ORDER-002', 'offer_id' => 'TEST-002']] // Only new record inserted
            );
        
        // Mock verification query
        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([
                ['posting_number' => 'ORDER-002', 'offer_id' => 'TEST-002', 'created_at' => '2023-01-01 12:00:00']
            ]);
        
        $this->mockDb->expects($this->once())
            ->method('commit');
        
        // Execute loading
        $this->salesETL->load($transformedData);
        
        // Verify metrics - only 1 record should be loaded (the new one)
        $metrics = $this->salesETL->getMetrics();
        $this->assertEquals(1, $metrics['records_loaded']);
        $this->assertEquals(1, $metrics['existing_records_skipped']);
    }

    /**
     * Test loading with database error
     */
    public function testLoadWithDatabaseError(): void
    {
        $transformedData = [
            [
                'posting_number' => 'ORDER-001',
                'offer_id' => 'TEST-001',
                'quantity' => 1,
                'price' => 100.00,
                'warehouse_id' => 'WH-001',
                'in_process_at' => '2023-01-01 10:00:00',
                'created_at' => '2023-01-01 12:00:00'
            ]
        ];
        
        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        $this->mockDb->expects($this->once())
            ->method('query')
            ->willThrowException(new Exception('Database connection lost'));
        
        $this->mockDb->expects($this->once())
            ->method('rollback');
        
        // Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Sales loading failed after 0 batches');
        
        // Execute loading
        $this->salesETL->load($transformedData);
    }

    /**
     * Test loading with empty data
     */
    public function testLoadWithEmptyData(): void
    {
        // Mock logger to expect warning about no sales data
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with('No sales data to load');
        
        // Execute loading with empty array
        $this->salesETL->load([]);
        
        // Should complete without error
        $this->assertTrue(true);
    }

    /**
     * Test complete ETL execution
     */
    public function testExecuteCompleteETL(): void
    {
        // Mock API response
        $apiResponse = [
            'result' => [
                'postings' => [
                    [
                        'posting_number' => 'ORDER-001',
                        'in_process_at' => '2023-01-01T10:00:00Z',
                        'products' => [
                            [
                                'sku' => 'TEST-001',
                                'quantity' => 1,
                                'price' => 100.00
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $this->mockApiClient->expects($this->exactly(2))
            ->method('getSalesHistory')
            ->willReturnOnConsecutiveCalls(
                $apiResponse,
                ['result' => ['postings' => []]] // Empty response to end pagination
            );
        
        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        $this->mockDb->expects($this->exactly(3))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [], // No existing records
                [['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001']], // Insert result
                [['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001', 'created_at' => '2023-01-01 12:00:00']] // Verification
            );
        
        $this->mockDb->expects($this->once())
            ->method('commit');
        
        // Mock ETL execution logging
        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1]], // ETL execution start log
                null // ETL execution update
            );
        
        // Execute complete ETL
        $result = $this->salesETL->execute();
        
        // Assertions
        $this->assertInstanceOf(ETLResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->getRecordsProcessed());
    }

    /**
     * Test configuration validation
     */
    public function testConfigurationValidation(): void
    {
        // Test invalid batch size
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be between 1 and 1000');
        
        new SalesETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            ['batch_size' => 0]
        );
    }

    /**
     * Test invalid days back configuration
     */
    public function testInvalidDaysBackConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Days back must be between 1 and 365');
        
        new SalesETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            ['days_back' => 0]
        );
    }

    /**
     * Test SalesETL statistics
     */
    public function testGetSalesETLStats(): void
    {
        $stats = $this->salesETL->getSalesETLStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('config', $stats);
        $this->assertArrayHasKey('metrics', $stats);
        $this->assertEquals(100, $stats['config']['batch_size']);
        $this->assertEquals(30, $stats['config']['days_back']);
        $this->assertTrue($stats['config']['incremental_load']);
    }

    /**
     * Test date normalization
     */
    public function testDateNormalization(): void
    {
        $rawData = [
            [
                'posting_number' => 'ORDER-001',
                'in_process_at' => '2023-01-01T10:30:45Z',
                'products' => [
                    [
                        'sku' => 'TEST-001',
                        'quantity' => 1
                    ]
                ]
            ],
            [
                'posting_number' => 'ORDER-002',
                'in_process_at' => 'invalid-date',
                'products' => [
                    [
                        'sku' => 'TEST-002',
                        'quantity' => 1
                    ]
                ]
            ],
            [
                'posting_number' => 'ORDER-003',
                'in_process_at' => null,
                'products' => [
                    [
                        'sku' => 'TEST-003',
                        'quantity' => 1
                    ]
                ]
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        $this->assertEquals('2023-01-01 10:30:45', $result[0]['in_process_at']);
        $this->assertNull($result[1]['in_process_at']); // Invalid date should become null
        $this->assertNull($result[2]['in_process_at']); // Null should remain null
    }

    /**
     * Test field length validation
     */
    public function testFieldLengthValidation(): void
    {
        $rawData = [
            [
                'posting_number' => str_repeat('A', 256), // Too long posting number
                'products' => [
                    [
                        'sku' => 'TEST-001',
                        'quantity' => 1
                    ]
                ]
            ],
            [
                'posting_number' => 'ORDER-002',
                'products' => [
                    [
                        'sku' => str_repeat('B', 256), // Too long SKU
                        'quantity' => 1
                    ]
                ]
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        // Both orders should be skipped due to validation errors
        $this->assertCount(0, $result);
    }

    /**
     * Test price validation
     */
    public function testPriceValidation(): void
    {
        $rawData = [
            [
                'posting_number' => 'ORDER-001',
                'products' => [
                    [
                        'sku' => 'TEST-001',
                        'quantity' => 1,
                        'price' => 100.50 // Valid price
                    ],
                    [
                        'sku' => 'TEST-002',
                        'quantity' => 1,
                        'price' => -10.00 // Invalid negative price
                    ],
                    [
                        'sku' => 'TEST-003',
                        'quantity' => 1,
                        'price' => 'invalid' // Invalid non-numeric price
                    ]
                ]
            ]
        ];
        
        $result = $this->salesETL->transform($rawData);
        
        // Only the product with valid price should be returned
        $this->assertCount(1, $result);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
        $this->assertEquals(100.50, $result[0]['price']);
    }

    /**
     * Test non-incremental loading mode
     */
    public function testNonIncrementalLoading(): void
    {
        // Create SalesETL with incremental_load disabled
        $salesETL = new SalesETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            ['incremental_load' => false]
        );
        
        $transformedData = [
            [
                'posting_number' => 'ORDER-001',
                'offer_id' => 'TEST-001',
                'quantity' => 1,
                'price' => 100.00,
                'warehouse_id' => 'WH-001',
                'in_process_at' => '2023-01-01 10:00:00',
                'created_at' => '2023-01-01 12:00:00'
            ]
        ];
        
        // Mock database operations - should not check for existing records
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                // Should directly insert without checking existing
                [$this->stringContains('INSERT INTO fact_orders')],
                // Verification query
                [$this->stringContains('SELECT posting_number, offer_id')]
            )
            ->willReturnOnConsecutiveCalls(
                [['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001']],
                [['posting_number' => 'ORDER-001', 'offer_id' => 'TEST-001', 'created_at' => '2023-01-01 12:00:00']]
            );
        
        $this->mockDb->expects($this->once())
            ->method('commit');
        
        // Execute loading
        $salesETL->load($transformedData);
        
        // Should complete successfully
        $this->assertTrue(true);
    }
}