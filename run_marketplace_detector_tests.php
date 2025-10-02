<?php
/**
 * Test Runner for MarketplaceDetector Unit Tests
 * 
 * Simple test runner that executes MarketplaceDetector tests without requiring PHPUnit installation
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/src/classes/MarketplaceDetector.php';

class SimpleTestRunner {
    private $tests = [];
    private $passed = 0;
    private $failed = 0;
    private $errors = [];
    
    public function addTest($name, $callable) {
        $this->tests[$name] = $callable;
    }
    
    public function runTests() {
        echo "Running MarketplaceDetector Unit Tests\n";
        echo str_repeat("=", 50) . "\n\n";
        
        foreach ($this->tests as $name => $test) {
            try {
                echo "Testing: {$name}... ";
                $test();
                echo "✓ PASSED\n";
                $this->passed++;
            } catch (Exception $e) {
                echo "✗ FAILED\n";
                echo "  Error: " . $e->getMessage() . "\n";
                $this->failed++;
                $this->errors[] = "{$name}: " . $e->getMessage();
            }
        }
        
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "Test Results:\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . count($this->tests) . "\n";
        
        if (!empty($this->errors)) {
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "- {$error}\n";
            }
        }
        
        return $this->failed === 0;
    }
    
    public function assertEquals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            $msg = $message ?: "Expected '{$expected}', got '{$actual}'";
            throw new Exception($msg);
        }
    }
    
    public function assertTrue($condition, $message = '') {
        if (!$condition) {
            $msg = $message ?: "Expected true, got false";
            throw new Exception($msg);
        }
    }
    
    public function assertFalse($condition, $message = '') {
        if ($condition) {
            $msg = $message ?: "Expected false, got true";
            throw new Exception($msg);
        }
    }
    
    public function assertNull($value, $message = '') {
        if ($value !== null) {
            $msg = $message ?: "Expected null, got " . var_export($value, true);
            throw new Exception($msg);
        }
    }
    
    public function assertIsArray($value, $message = '') {
        if (!is_array($value)) {
            $msg = $message ?: "Expected array, got " . gettype($value);
            throw new Exception($msg);
        }
    }
    
    public function assertCount($expected, $array, $message = '') {
        if (count($array) !== $expected) {
            $msg = $message ?: "Expected count {$expected}, got " . count($array);
            throw new Exception($msg);
        }
    }
    
    public function assertContains($needle, $haystack, $message = '') {
        if (!in_array($needle, $haystack)) {
            $msg = $message ?: "Expected array to contain '{$needle}'";
            throw new Exception($msg);
        }
    }
    
    public function assertStringContains($needle, $haystack, $message = '') {
        if (strpos($haystack, $needle) === false) {
            $msg = $message ?: "Expected string to contain '{$needle}'";
            throw new Exception($msg);
        }
    }
    
    public function assertStringStartsWith($prefix, $string, $message = '') {
        if (strpos($string, $prefix) !== 0) {
            $msg = $message ?: "Expected string to start with '{$prefix}'";
            throw new Exception($msg);
        }
    }
    
    public function assertStringNotContains($needle, $haystack, $message = '') {
        if (strpos($haystack, $needle) !== false) {
            $msg = $message ?: "Expected string to NOT contain '{$needle}'";
            throw new Exception($msg);
        }
    }
    
    public function assertArrayHasKey($key, $array, $message = '') {
        if (!array_key_exists($key, $array)) {
            $msg = $message ?: "Expected array to have key '{$key}'";
            throw new Exception($msg);
        }
    }
    
    public function assertEmpty($value, $message = '') {
        if (!empty($value)) {
            $msg = $message ?: "Expected empty value, got " . var_export($value, true);
            throw new Exception($msg);
        }
    }
}

// Create test runner instance
$runner = new SimpleTestRunner();

// Mock PDO for database-dependent tests
$mockPdo = new class {
    public function prepare($sql) {
        return new class {
            public function execute($params) { return true; }
            public function fetchAll($mode) { return []; }
        };
    }
};

$detector = new MarketplaceDetector($mockPdo);

// Add all tests
$runner->addTest('detectFromSourceCode - Ozon patterns', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('ozon'));
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('OZON'));
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('ozon_api'));
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('озон'));
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('  ozon  '));
});

$runner->addTest('detectFromSourceCode - Wildberries patterns', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('wildberries'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('wb'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('вб'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('wb_api'));
});

$runner->addTest('detectFromSourceCode - Edge cases', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode(''));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode(null));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode('amazon'));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode('ozo'));
});

$runner->addTest('detectFromSourceName - Valid patterns', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceName('Ozon Marketplace'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceName('Wildberries Store'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceName('WB API'));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceName('Amazon Store'));
});

$runner->addTest('detectFromSku - Exact matches', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', 'OZ123456'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', 'WB789012'));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', 'OTHER123'));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', ''));
});

$runner->addTest('detectFromSku - Ambiguous cases', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('SAME123', 'SAME123', 'SAME123'));
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('OZ123456', null, 'OZ123456'));
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSku(null, 'WB789012', 'WB789012'));
});

$runner->addTest('detectMarketplace - Priority logic', function() use ($runner) {
    // Source code takes precedence
    $runner->assertEquals(MarketplaceDetector::OZON, 
        MarketplaceDetector::detectMarketplace('ozon', 'wildberries_store', 'OZ123', 'WB456', 'WB456'));
    
    // Source name when source code is empty
    $runner->assertEquals(MarketplaceDetector::WILDBERRIES, 
        MarketplaceDetector::detectMarketplace('', 'wildberries_store', 'OZ123', 'WB456', 'OZ123'));
    
    // SKU when both source code and name are empty
    $runner->assertEquals(MarketplaceDetector::OZON, 
        MarketplaceDetector::detectMarketplace('', '', 'OZ123', 'WB456', 'OZ123'));
});

$runner->addTest('getAllMarketplaces', function() use ($runner) {
    $marketplaces = MarketplaceDetector::getAllMarketplaces();
    $runner->assertIsArray($marketplaces);
    $runner->assertCount(2, $marketplaces);
    $runner->assertContains(MarketplaceDetector::OZON, $marketplaces);
    $runner->assertContains(MarketplaceDetector::WILDBERRIES, $marketplaces);
});

$runner->addTest('getMarketplaceName', function() use ($runner) {
    $runner->assertEquals('Ozon', MarketplaceDetector::getMarketplaceName(MarketplaceDetector::OZON));
    $runner->assertEquals('Wildberries', MarketplaceDetector::getMarketplaceName(MarketplaceDetector::WILDBERRIES));
    $runner->assertEquals('Неопределенный источник', MarketplaceDetector::getMarketplaceName(MarketplaceDetector::UNKNOWN));
    $runner->assertEquals('Неизвестный маркетплейс', MarketplaceDetector::getMarketplaceName('invalid'));
});

$runner->addTest('getMarketplaceIcon', function() use ($runner) {
    $runner->assertEquals('📦', MarketplaceDetector::getMarketplaceIcon(MarketplaceDetector::OZON));
    $runner->assertEquals('🛍️', MarketplaceDetector::getMarketplaceIcon(MarketplaceDetector::WILDBERRIES));
    $runner->assertEquals('❓', MarketplaceDetector::getMarketplaceIcon(MarketplaceDetector::UNKNOWN));
    $runner->assertEquals('🏪', MarketplaceDetector::getMarketplaceIcon('invalid'));
});

$runner->addTest('validateMarketplaceParameter - Valid inputs', function() use ($runner) {
    $result = MarketplaceDetector::validateMarketplaceParameter(null);
    $runner->assertTrue($result['valid']);
    $runner->assertNull($result['error']);
    
    $result = MarketplaceDetector::validateMarketplaceParameter(MarketplaceDetector::OZON);
    $runner->assertTrue($result['valid']);
    
    $result = MarketplaceDetector::validateMarketplaceParameter('OZON');
    $runner->assertTrue($result['valid']);
});

$runner->addTest('validateMarketplaceParameter - Invalid inputs', function() use ($runner) {
    $result = MarketplaceDetector::validateMarketplaceParameter(123);
    $runner->assertFalse($result['valid']);
    $runner->assertEquals('Параметр маркетплейса должен быть строкой', $result['error']);
    
    $result = MarketplaceDetector::validateMarketplaceParameter('');
    $runner->assertFalse($result['valid']);
    
    $result = MarketplaceDetector::validateMarketplaceParameter('amazon');
    $runner->assertFalse($result['valid']);
    $runner->assertStringContains('Недопустимый маркетплейс', $result['error']);
});

$runner->addTest('buildMarketplaceFilter - Ozon', function() use ($runner, $detector) {
    $result = $detector->buildMarketplaceFilter(MarketplaceDetector::OZON);
    
    $runner->assertIsArray($result);
    $runner->assertArrayHasKey('condition', $result);
    $runner->assertArrayHasKey('params', $result);
    
    $condition = $result['condition'];
    $runner->assertStringContains('s.code LIKE :ozon_code', $condition);
    $runner->assertStringContains('dp.sku_ozon IS NOT NULL', $condition);
    
    $params = $result['params'];
    $runner->assertEquals('%ozon%', $params['ozon_code']);
});

$runner->addTest('buildMarketplaceFilter - Wildberries', function() use ($runner, $detector) {
    $result = $detector->buildMarketplaceFilter(MarketplaceDetector::WILDBERRIES);
    
    $condition = $result['condition'];
    $runner->assertStringContains('s.code LIKE :wb_code1', $condition);
    $runner->assertStringContains('dp.sku_wb IS NOT NULL', $condition);
    
    $params = $result['params'];
    $runner->assertEquals('%wb%', $params['wb_code1']);
    $runner->assertEquals('%wildberries%', $params['wb_code2']);
});

$runner->addTest('buildMarketplaceFilter - Null marketplace', function() use ($runner, $detector) {
    $result = $detector->buildMarketplaceFilter(null);
    $runner->assertEquals('1=1', $result['condition']);
    $runner->assertEmpty($result['params']);
});

$runner->addTest('buildMarketplaceFilter - Custom aliases', function() use ($runner, $detector) {
    $result = $detector->buildMarketplaceFilter(MarketplaceDetector::OZON, 'sources', 'products', 'orders');
    
    $condition = $result['condition'];
    $runner->assertStringContains('sources.code', $condition);
    $runner->assertStringContains('products.sku_ozon', $condition);
    $runner->assertStringContains('orders.sku', $condition);
});

$runner->addTest('buildMarketplaceExcludeFilter', function() use ($runner, $detector) {
    $result = $detector->buildMarketplaceExcludeFilter(MarketplaceDetector::OZON);
    
    $runner->assertStringStartsWith('NOT (', $result['condition']);
    
    $includeResult = $detector->buildMarketplaceFilter(MarketplaceDetector::OZON);
    $runner->assertEquals($includeResult['params'], $result['params']);
});

$runner->addTest('Edge cases - Missing data', function() use ($runner) {
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, 
        MarketplaceDetector::detectMarketplace(null, null, null, null, null));
    
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, 
        MarketplaceDetector::detectMarketplace('', '', '', '', ''));
    
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, 
        MarketplaceDetector::detectMarketplace('   ', '   ', '   ', '   ', '   '));
});

$runner->addTest('Case sensitivity', function() use ($runner) {
    // Source detection should be case insensitive
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('OZON'));
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('ozon'));
    
    // SKU detection should be case sensitive (exact match)
    $runner->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('OZ123', 'WB456', 'OZ123'));
    $runner->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('OZ123', 'WB456', 'oz123'));
});

$runner->addTest('SQL injection prevention', function() use ($runner, $detector) {
    $result = $detector->buildMarketplaceFilter(MarketplaceDetector::OZON);
    
    $condition = $result['condition'];
    $runner->assertStringNotContains('ozon', $condition); // Should not contain literal values
    $runner->assertStringContains(':ozon_code', $condition); // Should contain parameter placeholders
    
    $params = $result['params'];
    $runner->assertArrayHasKey('ozon_code', $params);
    $runner->assertArrayHasKey('ozon_name', $params);
});

// Run all tests
$success = $runner->runTests();

if ($success) {
    echo "\n🎉 All tests passed successfully!\n";
    exit(0);
} else {
    echo "\n❌ Some tests failed. Please check the errors above.\n";
    exit(1);
}
?>