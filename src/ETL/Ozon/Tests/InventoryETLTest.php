<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Exception;
use RuntimeException;
use InvalidArgumentException;
use MiCore\ETL\Ozon\Components\InventoryETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Unit Tests for InventoryETL Component
 * 
 * Tests core functionality including:
 * - CSV parsing and validation
 * - Full table refresh operations
 * - Error handling scenarios
 * - Configuration validation
 * 
 * Requirements tested:
 * - 3.1: Report generation and polling
 * - 3.2: CSV download and parsing
 * - 3.3: Data validation and transformation
 * - 3.4: Full table refresh loading
 */
class InventoryETLTest extends TestCase
{
    private MockObject $mockDb;
    private MockObject $mockLogger;
    private MockObject $mockApiClient;
    private array $defaultConfig;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(DatabaseConnection::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockApiClient = $this->createMock(OzonApiClient::class);
        
        $this->defaultConfig = [
            'report_language' => 'DEFAULT',
            'max_wait_time' => 300,
            'poll_interval' => 30,
            'batch_size' => 100
        ];
    }

    /**
     * Test successful InventoryETL initialization
     */
    public function testSuccessfulInitialization(): void
    {
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('InventoryETL initialized');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $this->assertInstanceOf(InventoryETL::class, $etl);
    }

    /**
     * Test configuration validation
     */
    public function testConfigurationValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_wait_time must be positive');

        $invalidConfig = array_merge($this->defaultConfig, [
            'max_wait_time' => -1
        ]);

        new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $invalidConfig
        );
    }

    /**
     * Test invalid report language configuration
     */
    public function testInvalidReportLanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid report_language');

        $invalidConfig = array_merge($this->defaultConfig, [
            'report_language' => 'INVALID'
        ]);

        new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $invalidConfig
        );
    }

    /**
     * Test successful CSV parsing with valid data
     */
    public function testSuccessfulCsvParsing(): void
    {
        $csvData = [
            [
                'SKU' => 'TEST-SKU-001',
                'Warehouse name' => 'Moscow Warehouse',
                'Item Name' => 'Test Product 1',
                'Present' => '100',
                'Reserved' => '10'
            ],
            [
                'SKU' => 'TEST-SKU-002',
                'Warehouse name' => 'SPB Warehouse',
                'Item Name' => 'Test Product 2',
                'Present' => '50',
                'Reserved' => '5'
            ]
        ];

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->with('https://example.com/report.csv')
            ->willReturn($csvData);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $result = $etl->downloadAndParseCsv('https://example.com/report.csv');

        $this->assertCount(2, $result);
        $this->assertEquals('TEST-SKU-001', $result[0]['offer_id']);
        $this->assertEquals('Moscow Warehouse', $result[0]['warehouse_name']);
        $this->assertEquals(100, $result[0]['present']);
        $this->assertEquals(10, $result[0]['reserved']);
        $this->assertEquals(90, $result[0]['available']); // 100 - 10
    }

    /**
     * Test CSV parsing with missing required headers
     */
    public function testCsvParsingWithMissingHeaders(): void
    {
        $csvData = [
            [
                'SKU' => 'TEST-SKU-001',
                'Item Name' => 'Test Product 1',
                // Missing 'Warehouse name', 'Present', 'Reserved'
            ]
        ];

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->willReturn($csvData);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('error');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSV missing required headers');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $etl->downloadAndParseCsv('https://example.com/report.csv');
    }

    /**
     * Test CSV parsing with invalid quantity values
     */
    public function testCsvParsingWithInvalidQuantities(): void
    {
        $csvData = [
            [
                'SKU' => 'TEST-SKU-001',
                'Warehouse name' => 'Moscow Warehouse',
                'Item Name' => 'Test Product 1',
                'Present' => 'invalid',
                'Reserved' => '10'
            ]
        ];

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->willReturn($csvData);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $result = $etl->downloadAndParseCsv('https://example.com/report.csv');

        // Should return empty array as all rows are invalid
        $this->assertEmpty($result);
    }

    /**
     * Test CSV parsing with reserved > present (invalid business logic)
     */
    public function testCsvParsingWithInvalidBusinessLogic(): void
    {
        $csvData = [
            [
                'SKU' => 'TEST-SKU-001',
                'Warehouse name' => 'Moscow Warehouse',
                'Item Name' => 'Test Product 1',
                'Present' => '10',
                'Reserved' => '20' // Reserved > Present
            ]
        ];

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->willReturn($csvData);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $result = $etl->downloadAndParseCsv('https://example.com/report.csv');

        // Should return empty array as business logic validation fails
        $this->assertEmpty($result);
    }

    /**
     * Test successful data transformation
     */
    public function testSuccessfulDataTransformation(): void
    {
        $inputData = [
            [
                'offer_id' => 'TEST-SKU-001',
                'warehouse_name' => 'Moscow Warehouse',
                'item_name' => 'Test Product 1',
                'present' => 100,
                'reserved' => 10,
                'available' => 90,
                'updated_at' => '2023-01-01 12:00:00'
            ],
            [
                'offer_id' => 'TEST-SKU-002',
                'warehouse_name' => 'SPB Warehouse',
                'item_name' => 'Test Product 2',
                'present' => 0,
                'reserved' => 0,
                'available' => 0,
                'updated_at' => '2023-01-01 12:00:00'
            ]
        ];

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $result = $etl->transform($inputData);

        // Should skip items with zero quantities (business rule)
        $this->assertCount(1, $result);
        $this->assertEquals('TEST-SKU-001', $result[0]['offer_id']);
        $this->assertTrue($result[0]['has_stock']);
        $this->assertTrue($result[0]['is_reserved']);
    }

    /**
     * Test successful full table refresh loading
     */
    public function testSuccessfulFullTableRefresh(): void
    {
        $inputData = [
            [
                'offer_id' => 'TEST-SKU-001',
                'warehouse_name' => 'Moscow Warehouse',
                'item_name' => 'Test Product 1',
                'present' => 100,
                'reserved' => 10,
                'available' => 90,
                'updated_at' => '2023-01-01 12:00:00',
                'has_stock' => true,
                'is_reserved' => true
            ]
        ];

        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');

        $this->mockDb->expects($this->once())
            ->method('query')
            ->with('SELECT COUNT(*) as count FROM inventory')
            ->willReturn([['count' => '50']]);

        $this->mockDb->expects($this->once())
            ->method('execute')
            ->with('TRUNCATE TABLE inventory RESTART IDENTITY');

        $this->mockDb->expects($this->once())
            ->method('batchInsert')
            ->with('inventory', $this->isType('array'), $inputData)
            ->willReturn(1);

        $this->mockDb->expects($this->once())
            ->method('commit');

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $etl->load($inputData);
    }

    /**
     * Test loading with database transaction rollback on error
     */
    public function testLoadingWithTransactionRollback(): void
    {
        $inputData = [
            [
                'offer_id' => 'TEST-SKU-001',
                'warehouse_name' => 'Moscow Warehouse',
                'item_name' => 'Test Product 1',
                'present' => 100,
                'reserved' => 10,
                'available' => 90,
                'updated_at' => '2023-01-01 12:00:00',
                'has_stock' => true,
                'is_reserved' => true
            ]
        ];

        // Mock database operations with failure
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([['count' => '50']]);

        $this->mockDb->expects($this->once())
            ->method('execute')
            ->willThrowException(new Exception('Database error'));

        $this->mockDb->expects($this->once())
            ->method('rollback');

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('error');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Inventory loading failed');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $etl->load($inputData);
    }

    /**
     * Test loading with empty data
     */
    public function testLoadingWithEmptyData(): void
    {
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with('No inventory data to load');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $etl->load([]);
    }

    /**
     * Test CSV parsing with duplicate SKU+Warehouse combinations
     */
    public function testCsvParsingWithDuplicates(): void
    {
        $csvData = [
            [
                'SKU' => 'TEST-SKU-001',
                'Warehouse name' => 'Moscow Warehouse',
                'Item Name' => 'Test Product 1',
                'Present' => '100',
                'Reserved' => '10'
            ],
            [
                'SKU' => 'TEST-SKU-001',
                'Warehouse name' => 'Moscow Warehouse', // Duplicate combination
                'Item Name' => 'Test Product 1 Updated',
                'Present' => '120',
                'Reserved' => '15'
            ]
        ];

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->willReturn($csvData);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('Duplicate SKU+Warehouse combination'));

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $result = $etl->downloadAndParseCsv('https://example.com/report.csv');

        // Should keep both records (latest occurrence logic)
        $this->assertCount(2, $result);
    }

    /**
     * Test CSV parsing with too many validation errors
     */
    public function testCsvParsingWithTooManyErrors(): void
    {
        // Create data where more than 50% of rows are invalid
        $csvData = [];
        for ($i = 0; $i < 10; $i++) {
            $csvData[] = [
                'SKU' => "TEST-SKU-{$i}",
                'Warehouse name' => 'Moscow Warehouse',
                'Item Name' => "Test Product {$i}",
                'Present' => 'invalid', // Invalid quantity
                'Reserved' => '10'
            ];
        }

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->willReturn($csvData);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many validation errors');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $etl->downloadAndParseCsv('https://example.com/report.csv');
    }

    /**
     * Test successful end-to-end ETL execution
     */
    public function testSuccessfulEndToEndExecution(): void
    {
        $csvData = [
            [
                'SKU' => 'TEST-SKU-001',
                'Warehouse name' => 'Moscow Warehouse',
                'Item Name' => 'Test Product 1',
                'Present' => '100',
                'Reserved' => '10'
            ]
        ];

        // Mock API client for report workflow
        $this->mockApiClient->expects($this->once())
            ->method('createStockReport')
            ->willReturn(['result' => ['code' => 'test-report-123']]);

        $this->mockApiClient->expects($this->once())
            ->method('waitForReportCompletion')
            ->willReturn(['result' => ['status' => 'success', 'file' => 'https://example.com/report.csv']]);

        $this->mockApiClient->expects($this->once())
            ->method('downloadAndParseCsv')
            ->willReturn($csvData);

        // Mock database operations
        $this->mockDb->expects($this->once())
            ->method('beginTransaction');

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn([['count' => '0']]);

        $this->mockDb->expects($this->once())
            ->method('execute');

        $this->mockDb->expects($this->once())
            ->method('batchInsert')
            ->willReturn(1);

        $this->mockDb->expects($this->once())
            ->method('commit');

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info');

        $etl = new InventoryETL(
            $this->mockDb,
            $this->mockLogger,
            $this->mockApiClient,
            $this->defaultConfig
        );

        $result = $etl->execute();

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->getRecordsProcessed());
    }
}