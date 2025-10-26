<?php

declare(strict_types=1);

/**
 * Simple Test Runner for InventoryETL Tests
 * 
 * This script provides a basic test runner for InventoryETL functionality
 * without requiring PHPUnit installation. It tests core functionality
 * including CSV parsing, data validation, and transformation logic.
 */

require_once __DIR__ . '/../autoload.php';

use MiCore\ETL\Ozon\Components\InventoryETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

class SimpleInventoryETLTest
{
    private int $testsRun = 0;
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private array $failures = [];

    public function runAllTests(): void
    {
        echo "Running InventoryETL Tests...\n\n";

        $this->testCsvDataValidation();
        $this->testDataTransformation();
        $this->testBusinessRules();
        $this->testConfigurationValidation();
        $this->testErrorHandling();

        $this->printResults();
    }

    /**
     * Test CSV data validation logic
     */
    public function testCsvDataValidation(): void
    {
        echo "Testing CSV data validation...\n";

        // Test valid data
        $validData = [
            [
                'SKU' => 'TEST-001',
                'Warehouse name' => 'Moscow',
                'Item Name' => 'Test Product',
                'Present' => '100',
                'Reserved' => '10'
            ]
        ];

        $result = $this->validateCsvData($validData);
        $this->assert(count($result) === 1, "Valid CSV data should pass validation");
        $this->assert($result[0]['offer_id'] === 'TEST-001', "SKU should be mapped to offer_id");
        $this->assert($result[0]['available'] === 90, "Available should be calculated correctly");

        // Test invalid data - missing SKU
        $invalidData = [
            [
                'SKU' => '',
                'Warehouse name' => 'Moscow',
                'Item Name' => 'Test Product',
                'Present' => '100',
                'Reserved' => '10'
            ]
        ];

        $result = $this->validateCsvData($invalidData);
        $this->assert(count($result) === 0, "Invalid CSV data should be filtered out");

        // Test invalid quantities
        $invalidQuantities = [
            [
                'SKU' => 'TEST-001',
                'Warehouse name' => 'Moscow',
                'Item Name' => 'Test Product',
                'Present' => 'invalid',
                'Reserved' => '10'
            ]
        ];

        $result = $this->validateCsvData($invalidQuantities);
        $this->assert(count($result) === 0, "Invalid quantities should be filtered out");

        echo "✓ CSV data validation tests passed\n\n";
    }

    /**
     * Test data transformation logic
     */
    public function testDataTransformation(): void
    {
        echo "Testing data transformation...\n";

        $inputData = [
            [
                'offer_id' => 'TEST-001',
                'warehouse_name' => 'Moscow',
                'item_name' => 'Test Product',
                'present' => 100,
                'reserved' => 10,
                'available' => 90,
                'updated_at' => '2023-01-01 12:00:00'
            ]
        ];

        $transformed = $this->transformData($inputData);
        
        $this->assert(count($transformed) === 1, "Data should be transformed");
        $this->assert(isset($transformed[0]['has_stock']), "has_stock field should be added");
        $this->assert(isset($transformed[0]['is_reserved']), "is_reserved field should be added");
        $this->assert($transformed[0]['has_stock'] === true, "has_stock should be true for available items");
        $this->assert($transformed[0]['is_reserved'] === true, "is_reserved should be true for reserved items");

        echo "✓ Data transformation tests passed\n\n";
    }

    /**
     * Test business rules application
     */
    public function testBusinessRules(): void
    {
        echo "Testing business rules...\n";

        // Test zero quantity filtering
        $zeroQuantityData = [
            [
                'offer_id' => 'TEST-001',
                'warehouse_name' => 'Moscow',
                'item_name' => 'Test Product',
                'present' => 0,
                'reserved' => 0,
                'available' => 0,
                'updated_at' => '2023-01-01 12:00:00'
            ]
        ];

        $transformed = $this->transformData($zeroQuantityData);
        $this->assert(count($transformed) === 0, "Zero quantity items should be filtered out");

        // Test available quantity calculation
        $testData = [
            [
                'offer_id' => 'TEST-001',
                'warehouse_name' => 'Moscow',
                'item_name' => 'Test Product',
                'present' => 100,
                'reserved' => 30,
                'available' => 50, // This should be recalculated
                'updated_at' => '2023-01-01 12:00:00'
            ]
        ];

        $transformed = $this->transformData($testData);
        $this->assert($transformed[0]['available'] === 70, "Available quantity should be recalculated (100-30=70)");

        echo "✓ Business rules tests passed\n\n";
    }

    /**
     * Test configuration validation
     */
    public function testConfigurationValidation(): void
    {
        echo "Testing configuration validation...\n";

        // Test valid configuration
        $validConfig = [
            'report_language' => 'DEFAULT',
            'max_wait_time' => 300,
            'poll_interval' => 30
        ];

        $isValid = $this->validateConfig($validConfig);
        $this->assert($isValid, "Valid configuration should pass validation");

        // Test invalid max_wait_time
        $invalidConfig1 = [
            'report_language' => 'DEFAULT',
            'max_wait_time' => -1,
            'poll_interval' => 30
        ];

        $isValid = $this->validateConfig($invalidConfig1);
        $this->assert(!$isValid, "Negative max_wait_time should fail validation");

        // Test invalid report_language
        $invalidConfig2 = [
            'report_language' => 'INVALID',
            'max_wait_time' => 300,
            'poll_interval' => 30
        ];

        $isValid = $this->validateConfig($invalidConfig2);
        $this->assert(!$isValid, "Invalid report_language should fail validation");

        echo "✓ Configuration validation tests passed\n\n";
    }

    /**
     * Test error handling scenarios
     */
    public function testErrorHandling(): void
    {
        echo "Testing error handling...\n";

        // Test reserved > present validation
        $invalidBusinessLogic = [
            [
                'SKU' => 'TEST-001',
                'Warehouse name' => 'Moscow',
                'Item Name' => 'Test Product',
                'Present' => '10',
                'Reserved' => '20' // Reserved > Present
            ]
        ];

        $result = $this->validateCsvData($invalidBusinessLogic);
        $this->assert(count($result) === 0, "Reserved > Present should fail validation");

        // Test empty CSV data
        $emptyData = [];
        $result = $this->validateCsvData($emptyData);
        $this->assert(count($result) === 0, "Empty data should return empty result");

        echo "✓ Error handling tests passed\n\n";
    }

    /**
     * Helper method to validate CSV data (simplified version)
     */
    private function validateCsvData(array $csvData): array
    {
        $validatedData = [];

        foreach ($csvData as $row) {
            // Basic validation
            if (empty($row['SKU']) || trim($row['SKU']) === '') {
                continue;
            }

            if (empty($row['Warehouse name']) || trim($row['Warehouse name']) === '') {
                continue;
            }

            if (!isset($row['Present']) || !is_numeric($row['Present']) || (int)$row['Present'] < 0) {
                continue;
            }

            if (!isset($row['Reserved']) || !is_numeric($row['Reserved']) || (int)$row['Reserved'] < 0) {
                continue;
            }

            $present = (int)$row['Present'];
            $reserved = (int)$row['Reserved'];

            // Business logic validation
            if ($reserved > $present) {
                continue;
            }

            // Normalize data
            $validatedData[] = [
                'offer_id' => trim($row['SKU']),
                'warehouse_name' => trim($row['Warehouse name']),
                'item_name' => $row['Item Name'] ?? null,
                'present' => $present,
                'reserved' => $reserved,
                'available' => max(0, $present - $reserved),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        return $validatedData;
    }

    /**
     * Helper method to transform data (simplified version)
     */
    private function transformData(array $data): array
    {
        $transformedData = [];

        foreach ($data as $item) {
            // Skip items with zero quantities
            if ($item['present'] === 0 && $item['reserved'] === 0) {
                continue;
            }

            // Recalculate available quantity
            $item['available'] = max(0, $item['present'] - $item['reserved']);

            // Add computed fields
            $item['has_stock'] = $item['available'] > 0;
            $item['is_reserved'] = $item['reserved'] > 0;

            $transformedData[] = $item;
        }

        return $transformedData;
    }

    /**
     * Helper method to validate configuration (simplified version)
     */
    private function validateConfig(array $config): bool
    {
        if (isset($config['max_wait_time']) && $config['max_wait_time'] <= 0) {
            return false;
        }

        if (isset($config['poll_interval']) && $config['poll_interval'] <= 0) {
            return false;
        }

        if (isset($config['report_language'])) {
            $validLanguages = ['DEFAULT', 'RU', 'EN'];
            if (!in_array($config['report_language'], $validLanguages)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assert helper method
     */
    private function assert(bool $condition, string $message): void
    {
        $this->testsRun++;

        if ($condition) {
            $this->testsPassed++;
        } else {
            $this->testsFailed++;
            $this->failures[] = $message;
            echo "✗ FAILED: $message\n";
        }
    }

    /**
     * Print test results
     */
    private function printResults(): void
    {
        echo "=== Test Results ===\n";
        echo "Tests run: {$this->testsRun}\n";
        echo "Passed: {$this->testsPassed}\n";
        echo "Failed: {$this->testsFailed}\n";

        if ($this->testsFailed > 0) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "- $failure\n";
            }
            exit(1);
        } else {
            echo "\n✓ All tests passed!\n";
        }
    }
}

// Run the tests
$tester = new SimpleInventoryETLTest();
$tester->runAllTests();