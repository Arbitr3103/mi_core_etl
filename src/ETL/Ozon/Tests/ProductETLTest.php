<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Exception;
use MiCore\ETL\Ozon\Components\ProductETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;
use MiCore\ETL\Ozon\Core\ETLResult;

/**
 * Unit Tests for ProductETL Component
 * 
 * Tests all methods of ProductETL with mock data and error scenarios
 * according to requirements 1.1, 1.2, 1.3
 */
class ProductETLTest extends TestCase
{
    private MockObject $mockDb;
    private MockObject $mockLogger;
    private MockObject $mockApiClient;
    private ProductETL $productETL;
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
            'max_products' => 0,
            'enable_progress' => false
        ];
        
        // Create ProductETL instance
        $this->productETL = new ProductETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->testConfig
        );
    }

    /**
     * Test successful product extraction with pagination
     */
    public function testExtractSuccessWithPagination(): void
    {
        // Mock API responses for pagination
        $firstResponse = [
            'result' => [
                'items' => [
                    [
                        'product_id' => 123,
                        'offer_id' => 'TEST-001',
                        'name' => 'Test Product 1',
                        'fbo_sku' => 'FBO-001',
                        'fbs_sku' => 'FBS-001',
                        'status' => 'active'
                    ],
                    [
                        'product_id' => 124,
                        'offer_id' => 'TEST-002',
                        'name' => 'Test Product 2',
                        'fbo_sku' => 'FBO-002',
                        'fbs_sku' => null,
                        'status' => 'inactive'
                    ]
                ],
                'last_id' => 'next_page_token'
            ]
        ];
        
        $secondResponse = [
            'result' => [
                'items' => [
                    [
                        'product_id' => 125,
                        'offer_id' => 'TEST-003',
                        'name' => 'Test Product 3',
                        'fbo_sku' => null,
                        'fbs_sku' => 'FBS-003',
                        'status' => 'active'
                    ]
                ],
                'last_id' => null // No more pages
            ]
        ];
        
        // Set up API client expectations
        $this->mockApiClient->expects($this->exactly(2))
            ->method('getProducts')
            ->withConsecutive(
                [100, null],
                [100, 'next_page_token']
            )
            ->willReturnOnConsecutiveCalls($firstResponse, $secondResponse);
        
        // Execute extraction
        $result = $this->productETL->extract();
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals(123, $result[0]['product_id']);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
        $this->assertEquals('TEST-003', $result[2]['offer_id']);
    }

    /**
     * Test extraction with API error
     */
    public function testExtractWithApiError(): void
    {
        // Mock API client to throw exception
        $this->mockApiClient->expects($this->once())
            ->method('getProducts')
            ->willThrowException(new Exception('API connection failed'));
        
        // Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Product extraction failed after 0 batches: API connection failed');
        
        // Execute extraction
        $this->productETL->extract();
    }

    /**
     * Test successful data transformation
     */
    public function testTransformSuccess(): void
    {
        $rawData = [
            [
                'product_id' => 123,
                'offer_id' => 'TEST-001',
                'name' => 'Test Product 1',
                'fbo_sku' => 'FBO-001',
                'fbs_sku' => 'FBS-001',
                'status' => 'active'
            ],
            [
                'product_id' => 124,
                'offer_id' => 'TEST-002',
                'name' => '  Test Product 2  ', // Test trimming
                'fbo_sku' => '',  // Test empty string handling
                'fbs_sku' => null,
                'status' => 'INACTIVE' // Test status normalization
            ]
        ];
        
        $result = $this->productETL->transform($rawData);
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        
        // Check first product
        $this->assertEquals(123, $result[0]['product_id']);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
        $this->assertEquals('Test Product 1', $result[0]['name']);
        $this->assertEquals('FBO-001', $result[0]['fbo_sku']);
        $this->assertEquals('active', $result[0]['status']);
        
        // Check second product (trimming and normalization)
        $this->assertEquals('Test Product 2', $result[1]['name']);
        $this->assertNull($result[1]['fbo_sku']); // Empty string should become null
        $this->assertEquals('inactive', $result[1]['status']); // Should be normalized to lowercase
        $this->assertIsString($result[1]['updated_at']);
    }

    /**
     * Test transformation with invalid data
     */
    public function testTransformWithInvalidData(): void
    {
        $rawData = [
            [
                'product_id' => 123,
                'offer_id' => 'TEST-001',
                'name' => 'Valid Product'
            ],
            [
                // Missing product_id
                'offer_id' => 'TEST-002',
                'name' => 'Invalid Product 1'
            ],
            [
                'product_id' => 125,
                // Missing offer_id
                'name' => 'Invalid Product 2'
            ],
            [
                'product_id' => 'invalid', // Non-numeric product_id
                'offer_id' => 'TEST-004',
                'name' => 'Invalid Product 3'
            ]
        ];
        
        $result = $this->productETL->transform($rawData);
        
        // Should only return the valid product
        $this->assertCount(1, $result);
        $this->assertEquals(123, $result[0]['product_id']);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
    }

    /**
     * Test transformation with duplicate offer_ids
     */
    public function testTransformWithDuplicateOfferIds(): void
    {
        $rawData = [
            [
                'product_id' => 123,
                'offer_id' => 'DUPLICATE-001',
                'name' => 'First Product'
            ],
            [
                'product_id' => 124,
                'offer_id' => 'DUPLICATE-001', // Duplicate offer_id
                'name' => 'Second Product'
            ]
        ];
        
        $result = $this->productETL->transform($rawData);
        
        // Should return both products (duplicates are logged but not filtered)
        $this->assertCount(2, $result);
    }

    /**
     * Test successful data loading
     */
    public function testLoadSuccess(): void
    {
        $transformedData = [
            [
                'product_id' => 123,
                'offer_id' => 'TEST-001',
                'name' => 'Test Product 1',
                'fbo_sku' => 'FBO-001',
                'fbs_sku' => 'FBS-001',
                'status' => 'active',
                'updated_at' => '2023-01-01 12:00:00'
            ],
            [
                'product_id' => 124,
                'offer_id' => 'TEST-002',
                'name' => 'Test Product 2',
                'fbo_sku' => null,
                'fbs_sku' => 'FBS-002',
                'status' => 'inactive',
                'updated_at' => '2023-01-01 12:00:00'
            ]
        ];
        
        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([
                ['offer_id' => 'TEST-001', 'operation' => 'inserted'],
                ['offer_id' => 'TEST-002', 'operation' => 'updated']
            ]);
        
        $this->mockDb->expects($this->once())
            ->method('commit');
        
        // Mock verification query
        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([
                ['offer_id' => 'TEST-001', 'product_id' => 123, 'updated_at' => '2023-01-01 12:00:00'],
                ['offer_id' => 'TEST-002', 'product_id' => 124, 'updated_at' => '2023-01-01 12:00:00']
            ]);
        
        // Execute loading
        $this->productETL->load($transformedData);
        
        // Verify metrics were updated
        $metrics = $this->productETL->getMetrics();
        $this->assertEquals(2, $metrics['records_loaded']);
    }

    /**
     * Test loading with database error
     */
    public function testLoadWithDatabaseError(): void
    {
        $transformedData = [
            [
                'product_id' => 123,
                'offer_id' => 'TEST-001',
                'name' => 'Test Product',
                'fbo_sku' => null,
                'fbs_sku' => null,
                'status' => 'active',
                'updated_at' => '2023-01-01 12:00:00'
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
        $this->expectExceptionMessage('Product loading failed after 0 batches');
        
        // Execute loading
        $this->productETL->load($transformedData);
    }

    /**
     * Test loading with empty data
     */
    public function testLoadWithEmptyData(): void
    {
        // Mock logger to expect warning about no products
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with('No products to load');
        
        // Execute loading with empty array
        $this->productETL->load([]);
        
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
                'items' => [
                    [
                        'product_id' => 123,
                        'offer_id' => 'TEST-001',
                        'name' => 'Test Product',
                        'fbo_sku' => 'FBO-001',
                        'fbs_sku' => null,
                        'status' => 'active'
                    ]
                ],
                'last_id' => null
            ]
        ];
        
        $this->mockApiClient->expects($this->once())
            ->method('getProducts')
            ->willReturn($apiResponse);
        
        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');
        
        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['offer_id' => 'TEST-001', 'operation' => 'inserted']], // Upsert result
                [['offer_id' => 'TEST-001', 'product_id' => 123, 'updated_at' => '2023-01-01 12:00:00']] // Verification
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
        $result = $this->productETL->execute();
        
        // Assertions
        $this->assertInstanceOf(ETLResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->getRecordsProcessed());
    }

    /**
     * Test ETL execution with API error
     */
    public function testExecuteWithApiError(): void
    {
        // Mock API client to throw exception
        $this->mockApiClient->expects($this->once())
            ->method('getProducts')
            ->willThrowException(new Exception('API rate limit exceeded'));
        
        // Mock ETL execution logging
        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([['id' => 1]]);
        
        $this->mockDb->expects($this->once())
            ->method('execute'); // Update ETL log with error
        
        // Expect exception
        $this->expectException(Exception::class);
        
        // Execute ETL
        $this->productETL->execute();
    }

    /**
     * Test configuration validation
     */
    public function testConfigurationValidation(): void
    {
        // Test invalid batch size
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be between 1 and 1000');
        
        new ProductETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            ['batch_size' => 0]
        );
    }

    /**
     * Test ProductETL statistics
     */
    public function testGetProductETLStats(): void
    {
        $stats = $this->productETL->getProductETLStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('config', $stats);
        $this->assertArrayHasKey('metrics', $stats);
        $this->assertEquals(100, $stats['config']['batch_size']);
        $this->assertEquals(0, $stats['config']['max_products']);
    }

    /**
     * Test status normalization
     */
    public function testStatusNormalization(): void
    {
        $rawData = [
            [
                'product_id' => 123,
                'offer_id' => 'TEST-001',
                'status' => 'ACTIVE'
            ],
            [
                'product_id' => 124,
                'offer_id' => 'TEST-002',
                'status' => 'Inactive'
            ],
            [
                'product_id' => 125,
                'offer_id' => 'TEST-003',
                'status' => 'unknown_status'
            ],
            [
                'product_id' => 126,
                'offer_id' => 'TEST-004',
                'status' => null
            ]
        ];
        
        $result = $this->productETL->transform($rawData);
        
        $this->assertEquals('active', $result[0]['status']);
        $this->assertEquals('inactive', $result[1]['status']);
        $this->assertEquals('unknown', $result[2]['status']);
        $this->assertEquals('unknown', $result[3]['status']);
    }

    /**
     * Test field length validation
     */
    public function testFieldLengthValidation(): void
    {
        $rawData = [
            [
                'product_id' => 123,
                'offer_id' => str_repeat('A', 256), // Too long offer_id
                'name' => 'Test Product'
            ],
            [
                'product_id' => 124,
                'offer_id' => 'TEST-002',
                'name' => str_repeat('B', 1001) // Too long name
            ]
        ];
        
        $result = $this->productETL->transform($rawData);
        
        // Both products should be skipped due to validation errors
        $this->assertCount(0, $result);
    }

    /**
     * Test offer_id character validation
     */
    public function testOfferIdCharacterValidation(): void
    {
        $rawData = [
            [
                'product_id' => 123,
                'offer_id' => 'TEST-001', // Valid
                'name' => 'Valid Product'
            ],
            [
                'product_id' => 124,
                'offer_id' => 'TEST@002', // Invalid character
                'name' => 'Invalid Product'
            ]
        ];
        
        $result = $this->productETL->transform($rawData);
        
        // Only the valid product should be returned
        $this->assertCount(1, $result);
        $this->assertEquals('TEST-001', $result[0]['offer_id']);
    }
}