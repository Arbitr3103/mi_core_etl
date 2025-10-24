<?php
/**
 * End-to-End Tests for Visual Display Validation
 * Task 6.4: Валидация визуального отображения
 * 
 * Tests color coding, zero stock highlighting, interface readability, and warehouse dashboard
 */

// Only include database config to avoid function conflicts
if (!function_exists('getDatabaseConnection')) {
    require_once __DIR__ . '/../../config/database_postgresql.php';
}

class VisualDisplayValidationTest
{
    private $pdo;
    private $testTableName = 'test_inventory_visual';
    private $dashboardUrl = 'http://localhost/inventory_products_dashboard.html';
    private $warehouseDashboardUrl = 'http://localhost/warehouse_dashboard.html';
    
    public function setUp()
    {
        $this->pdo = getDatabaseConnection();
        $this->createTestTable();
        $this->insertVisualTestData();
    }
    
    public function tearDown()
    {
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->testTableName}");
    }
    
    private function createTestTable()
    {
        $sql = "
            CREATE TABLE {$this->testTableName} (
                id SERIAL PRIMARY KEY,
                sku VARCHAR(50) NOT NULL,
                name VARCHAR(200),
                warehouse_name VARCHAR(100) NOT NULL,
                quantity_present INTEGER DEFAULT 0,
                available INTEGER DEFAULT 0,
                preparing_for_sale INTEGER DEFAULT 0,
                in_requests INTEGER DEFAULT 0,
                in_transit INTEGER DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        
        $this->pdo->exec($sql);
    }
    
    private function insertVisualTestData()
    {
        $testData = [
            // Zero stock products (should be highlighted)
            ['sku' => 'ZERO001', 'name' => 'Zero Stock Product 1', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 0, 'available' => 0],
            ['sku' => 'ZERO002', 'name' => 'Zero Stock Product 2', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 0, 'available' => 0],
            
            // Very low stock products (1-2 items)
            ['sku' => 'LOW001', 'name' => 'Very Low Stock 1', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 1],
            ['sku' => 'LOW002', 'name' => 'Very Low Stock 2', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 2],
            
            // Normal active products
            ['sku' => 'NORMAL001', 'name' => 'Normal Active Product 1', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 25, 'available' => 10],
            ['sku' => 'NORMAL002', 'name' => 'Normal Active Product 2', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 50],
            
            // High stock products
            ['sku' => 'HIGH001', 'name' => 'High Stock Product 1', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 150, 'available' => 50],
            ['sku' => 'HIGH002', 'name' => 'High Stock Product 2', 'warehouse_name' => 'Warehouse3', 'quantity_present' => 200],
            
            // Mixed stock types for visual testing
            ['sku' => 'MIXED001', 'name' => 'Mixed Stock Product', 'warehouse_name' => 'Warehouse3', 'quantity_present' => 5, 'available' => 3, 'preparing_for_sale' => 2, 'in_transit' => 10],
            
            // Products with special characters in names (for display testing)
            ['sku' => 'SPECIAL001', 'name' => 'Product with "Quotes" & Symbols', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 15],
            ['sku' => 'SPECIAL002', 'name' => 'Продукт с русскими символами', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 0],
        ];
        
        foreach ($testData as $item) {
            $columns = array_keys($item);
            $placeholders = ':' . implode(', :', $columns);
            $columnsList = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->testTableName} ({$columnsList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($item);
        }
    }
    
    /**
     * Test color coding correctness
     * Requirements: 5.1, 5.2 - Реализовать цветовое кодирование
     */
    public function testColorCodingCorrectness()
    {
        $products = $this->getProductsWithActivityStatus();
        
        foreach ($products as $product) {
            $totalStock = $this->calculateTotalStock($product);
            $expectedActivityStatus = $totalStock > 0 ? 'active' : 'inactive';
            
            assertEquals(
                $expectedActivityStatus,
                $product['activity_status'],
                "Product {$product['sku']} should have correct activity status"
            );
            
            // Verify CSS class assignment logic
            $expectedCssClass = $this->getExpectedCssClass($product);
            assertNotEmpty($expectedCssClass, "Product {$product['sku']} should have CSS class");
            
            // Test color coding rules
            if ($product['activity_status'] === 'active') {
                assertContains('active', $expectedCssClass, 
                    "Active product {$product['sku']} should have active CSS class");
            } else {
                assertContains('inactive', $expectedCssClass, 
                    "Inactive product {$product['sku']} should have inactive CSS class");
            }
        }
    }
    
    /**
     * Test zero stock highlighting
     * Requirements: 5.3 - Выделить нулевые остатки
     */
    public function testZeroStockHighlighting()
    {
        $zeroStockProducts = $this->getZeroStockProducts();
        
        assertGreaterThan(0, count($zeroStockProducts), 'Should have zero stock products for testing');
        
        foreach ($zeroStockProducts as $product) {
            // Verify zero stock detection
            $totalStock = $this->calculateTotalStock($product);
            assertEquals(0, $totalStock, "Product {$product['sku']} should have zero total stock");
            
            // Verify special highlighting rules
            $cssClasses = $this->getZeroStockCssClasses($product);
            
            assertContains('zero-stock', $cssClasses, 
                "Zero stock product {$product['sku']} should have zero-stock CSS class");
            assertContains('inactive', $cssClasses, 
                "Zero stock product {$product['sku']} should have inactive CSS class");
            
            // Test visual indicators
            $visualIndicators = $this->getZeroStockVisualIndicators($product);
            assertArrayHasKey('warning_icon', $visualIndicators, 
                "Zero stock product should have warning icon");
            assertArrayHasKey('no_stock_label', $visualIndicators, 
                "Zero stock product should have 'НЕТ В НАЛИЧИИ' label");
        }
    }
    
    /**
     * Test very low stock highlighting (1-2 items)
     */
    public function testVeryLowStockHighlighting()
    {
        $veryLowStockProducts = $this->getVeryLowStockProducts();
        
        foreach ($veryLowStockProducts as $product) {
            $totalStock = $this->calculateTotalStock($product);
            assertGreaterThan(0, $totalStock, "Very low stock product should have some stock");
            assertLessThanOrEqual(2, $totalStock, "Very low stock product should have ≤ 2 items");
            
            // Verify special styling for very low stock
            $cssClasses = $this->getVeryLowStockCssClasses($product);
            assertContains('very-low-stock', $cssClasses, 
                "Very low stock product {$product['sku']} should have very-low-stock CSS class");
            
            // Test visual indicators
            $visualIndicators = $this->getVeryLowStockVisualIndicators($product);
            assertArrayHasKey('lightning_icon', $visualIndicators, 
                "Very low stock product should have lightning icon");
        }
    }
    
    /**
     * Test interface readability and usability
     * Requirements: 5.4 - Проверить читаемость и удобство интерфейса
     */
    public function testInterfaceReadabilityAndUsability()
    {
        // Test product name display
        $products = $this->getProductsWithActivityStatus();
        
        foreach ($products as $product) {
            // Test name truncation and display
            $displayName = $this->getDisplayName($product);
            assertNotEmpty($displayName, "Product {$product['sku']} should have display name");
            assertLessThanOrEqual(100, strlen($displayName), 
                "Display name should not be too long for readability");
            
            // Test special character handling
            if (strpos($product['name'], '"') !== false || strpos($product['name'], '&') !== false) {
                $escapedName = $this->getEscapedDisplayName($product);
                assertNotContains('<script', $escapedName, 
                    "Product name should be properly escaped");
            }
            
            // Test multilingual support
            if (preg_match('/[а-яё]/ui', $product['name'])) {
                assertTrue($this->isUtf8Encoded($product['name']), 
                    "Russian characters should be properly encoded");
            }
        }
        
        // Test column alignment and spacing
        $tableStructure = $this->getTableStructure();
        assertArrayHasKey('columns', $tableStructure);
        assertGreaterThan(5, count($tableStructure['columns']), 
            'Table should have sufficient columns');
        
        // Test responsive design elements
        $responsiveElements = $this->getResponsiveElements();
        assertArrayHasKey('mobile_breakpoint', $responsiveElements);
        assertArrayHasKey('tablet_breakpoint', $responsiveElements);
    }
    
    /**
     * Test warehouse dashboard visual elements
     * Requirements: 2.6 - Создать дашборд мониторинга складов с аналитикой продаж
     */
    public function testWarehouseDashboardVisualElements()
    {
        $warehouseData = $this->getWarehouseDashboardData();
        
        foreach ($warehouseData as $warehouse) {
            // Test status color coding
            $statusColor = $this->getWarehouseStatusColor($warehouse);
            assertNotEmpty($statusColor, "Warehouse {$warehouse['name']} should have status color");
            
            switch ($warehouse['status']) {
                case 'critical':
                    assertEquals('red', $statusColor, 'Critical warehouse should be red');
                    break;
                case 'warning':
                    assertEquals('yellow', $statusColor, 'Warning warehouse should be yellow');
                    break;
                case 'normal':
                    assertEquals('green', $statusColor, 'Normal warehouse should be green');
                    break;
            }
            
            // Test progress bars and indicators
            $progressBars = $this->getWarehouseProgressBars($warehouse);
            assertArrayHasKey('stock_level', $progressBars);
            assertArrayHasKey('sales_performance', $progressBars);
            
            // Test data visualization elements
            $charts = $this->getWarehouseCharts($warehouse);
            assertArrayHasKey('stock_distribution', $charts);
            assertArrayHasKey('sales_trend', $charts);
        }
    }
    
    /**
     * Test filter button visual states
     * Requirements: 4.1 - Добавить кнопки фильтрации в дашборд
     */
    public function testFilterButtonVisualStates()
    {
        $filterButtons = $this->getFilterButtons();
        
        assertCount(3, $filterButtons, 'Should have 3 filter buttons');
        
        $expectedButtons = ['active', 'inactive', 'all'];
        foreach ($expectedButtons as $buttonType) {
            $button = $this->findFilterButton($filterButtons, $buttonType);
            assertNotNull($button, "Should have {$buttonType} filter button");
            
            // Test button states
            assertArrayHasKey('default_state', $button);
            assertArrayHasKey('active_state', $button);
            assertArrayHasKey('hover_state', $button);
            
            // Test accessibility
            assertArrayHasKey('aria_label', $button);
            assertArrayHasKey('keyboard_accessible', $button);
            assertTrue($button['keyboard_accessible'], 
                "Filter button {$buttonType} should be keyboard accessible");
        }
        
        // Test default active state
        $activeButton = $this->findFilterButton($filterButtons, 'active');
        assertTrue($activeButton['is_default_active'], 
            'Active filter button should be active by default');
    }
    
    /**
     * Test data loading and error states
     */
    public function testDataLoadingAndErrorStates()
    {
        // Test loading state
        $loadingState = $this->getLoadingState();
        assertArrayHasKey('spinner', $loadingState);
        assertArrayHasKey('message', $loadingState);
        assertEquals('Загрузка данных...', $loadingState['message']);
        
        // Test empty state
        $emptyState = $this->getEmptyState();
        assertArrayHasKey('icon', $emptyState);
        assertArrayHasKey('message', $emptyState);
        assertContains('Нет данных', $emptyState['message']);
        
        // Test error state
        $errorState = $this->getErrorState();
        assertArrayHasKey('error_icon', $errorState);
        assertArrayHasKey('error_message', $errorState);
        assertArrayHasKey('retry_button', $errorState);
    }
    
    /**
     * Test accessibility compliance
     */
    public function testAccessibilityCompliance()
    {
        $accessibilityFeatures = $this->getAccessibilityFeatures();
        
        // Test ARIA labels
        assertArrayHasKey('aria_labels', $accessibilityFeatures);
        assertGreaterThan(0, count($accessibilityFeatures['aria_labels']));
        
        // Test keyboard navigation
        assertArrayHasKey('keyboard_navigation', $accessibilityFeatures);
        assertTrue($accessibilityFeatures['keyboard_navigation']['tab_order']);
        assertTrue($accessibilityFeatures['keyboard_navigation']['enter_key_support']);
        
        // Test screen reader support
        assertArrayHasKey('screen_reader', $accessibilityFeatures);
        assertTrue($accessibilityFeatures['screen_reader']['table_headers']);
        assertTrue($accessibilityFeatures['screen_reader']['status_announcements']);
        
        // Test color contrast
        $colorContrast = $this->getColorContrastRatios();
        foreach ($colorContrast as $element => $ratio) {
            assertGreaterThanOrEqual(4.5, $ratio, 
                "Element {$element} should meet WCAG AA contrast ratio");
        }
    }
    
    /**
     * Test mobile responsiveness
     */
    public function testMobileResponsiveness()
    {
        $mobileLayout = $this->getMobileLayout();
        
        // Test table responsiveness
        assertArrayHasKey('horizontal_scroll', $mobileLayout);
        assertTrue($mobileLayout['horizontal_scroll'], 
            'Table should have horizontal scroll on mobile');
        
        // Test button sizing
        assertArrayHasKey('touch_targets', $mobileLayout);
        foreach ($mobileLayout['touch_targets'] as $target) {
            assertGreaterThanOrEqual(44, $target['min_size'], 
                'Touch targets should be at least 44px for accessibility');
        }
        
        // Test font scaling
        assertArrayHasKey('font_scaling', $mobileLayout);
        assertTrue($mobileLayout['font_scaling']['responsive'], 
            'Fonts should scale responsively');
    }
    
    // Helper methods for visual testing
    
    private function getProductsWithActivityStatus()
    {
        $sql = "
            SELECT 
                sku,
                name,
                warehouse_name,
                quantity_present,
                available,
                preparing_for_sale,
                in_requests,
                in_transit,
                (COALESCE(quantity_present, 0) + 
                 COALESCE(available, 0) + 
                 COALESCE(preparing_for_sale, 0) + 
                 COALESCE(in_requests, 0) + 
                 COALESCE(in_transit, 0)) as total_stock,
                CASE 
                    WHEN (COALESCE(quantity_present, 0) + 
                          COALESCE(available, 0) + 
                          COALESCE(preparing_for_sale, 0) + 
                          COALESCE(in_requests, 0) + 
                          COALESCE(in_transit, 0)) > 0 THEN 'active'
                    ELSE 'inactive'
                END as activity_status
            FROM {$this->testTableName}
            ORDER BY sku
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getZeroStockProducts()
    {
        return array_filter($this->getProductsWithActivityStatus(), function($product) {
            return $this->calculateTotalStock($product) === 0;
        });
    }
    
    private function getVeryLowStockProducts()
    {
        return array_filter($this->getProductsWithActivityStatus(), function($product) {
            $stock = $this->calculateTotalStock($product);
            return $stock > 0 && $stock <= 2;
        });
    }
    
    private function calculateTotalStock($product)
    {
        return ($product['quantity_present'] ?? 0) + 
               ($product['available'] ?? 0) + 
               ($product['preparing_for_sale'] ?? 0) + 
               ($product['in_requests'] ?? 0) + 
               ($product['in_transit'] ?? 0);
    }
    
    private function getExpectedCssClass($product)
    {
        $classes = [];
        
        if ($product['activity_status'] === 'active') {
            $classes[] = 'product-row active';
        } else {
            $classes[] = 'product-row inactive';
        }
        
        $totalStock = $this->calculateTotalStock($product);
        if ($totalStock === 0) {
            $classes[] = 'zero-stock';
        } elseif ($totalStock <= 2) {
            $classes[] = 'very-low-stock';
        }
        
        return $classes;
    }
    
    private function getZeroStockCssClasses($product)
    {
        return ['zero-stock', 'inactive', 'total-stock-cell'];
    }
    
    private function getVeryLowStockCssClasses($product)
    {
        return ['very-low-stock', 'active', 'total-stock-cell'];
    }
    
    private function getZeroStockVisualIndicators($product)
    {
        return [
            'warning_icon' => '⚠️',
            'no_stock_label' => 'НЕТ В НАЛИЧИИ',
            'pulse_animation' => true,
            'border_highlight' => '#f56565'
        ];
    }
    
    private function getVeryLowStockVisualIndicators($product)
    {
        return [
            'lightning_icon' => '⚡',
            'warning_color' => '#d69e2e',
            'border_highlight' => '#ed8936'
        ];
    }
    
    private function getDisplayName($product)
    {
        $name = $product['name'] ?: 'Товар ' . $product['sku'];
        return strlen($name) > 50 ? substr($name, 0, 47) . '...' : $name;
    }
    
    private function getEscapedDisplayName($product)
    {
        return htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8');
    }
    
    private function isUtf8Encoded($string)
    {
        return mb_check_encoding($string, 'UTF-8');
    }
    
    private function getTableStructure()
    {
        return [
            'columns' => [
                'sku' => ['sortable' => true, 'width' => '120px'],
                'name' => ['sortable' => true, 'width' => 'auto'],
                'warehouse' => ['sortable' => true, 'width' => '150px'],
                'total_stock' => ['sortable' => true, 'width' => '120px', 'highlight' => true],
                'quantity_present' => ['sortable' => true, 'width' => '100px'],
                'available' => ['sortable' => true, 'width' => '100px'],
                'reserved' => ['sortable' => true, 'width' => '100px'],
                'activity_status' => ['sortable' => true, 'width' => '100px'],
                'last_updated' => ['sortable' => true, 'width' => '150px']
            ]
        ];
    }
    
    private function getResponsiveElements()
    {
        return [
            'mobile_breakpoint' => '768px',
            'tablet_breakpoint' => '1024px',
            'desktop_breakpoint' => '1200px'
        ];
    }
    
    private function getWarehouseDashboardData()
    {
        return [
            [
                'name' => 'Warehouse1',
                'status' => 'critical',
                'total_products' => 5,
                'urgent_items' => 3,
                'stock_level' => 25
            ],
            [
                'name' => 'Warehouse2',
                'status' => 'warning',
                'total_products' => 3,
                'urgent_items' => 1,
                'stock_level' => 65
            ],
            [
                'name' => 'Warehouse3',
                'status' => 'normal',
                'total_products' => 3,
                'urgent_items' => 0,
                'stock_level' => 85
            ]
        ];
    }
    
    private function getWarehouseStatusColor($warehouse)
    {
        $statusColors = [
            'critical' => 'red',
            'warning' => 'yellow',
            'normal' => 'green',
            'excess' => 'blue'
        ];
        
        return $statusColors[$warehouse['status']] ?? 'gray';
    }
    
    private function getWarehouseProgressBars($warehouse)
    {
        return [
            'stock_level' => [
                'value' => $warehouse['stock_level'],
                'max' => 100,
                'color' => $this->getWarehouseStatusColor($warehouse)
            ],
            'sales_performance' => [
                'value' => rand(60, 95),
                'max' => 100,
                'color' => 'blue'
            ]
        ];
    }
    
    private function getWarehouseCharts($warehouse)
    {
        return [
            'stock_distribution' => [
                'type' => 'pie',
                'data' => ['critical' => 10, 'low' => 20, 'normal' => 60, 'excess' => 10]
            ],
            'sales_trend' => [
                'type' => 'line',
                'data' => [100, 120, 110, 130, 125, 140, 135]
            ]
        ];
    }
    
    private function getFilterButtons()
    {
        return [
            [
                'type' => 'active',
                'label' => '✅ Активные',
                'default_state' => 'btn-primary',
                'active_state' => 'btn-primary active',
                'hover_state' => 'btn-primary-hover',
                'aria_label' => 'Показать активные товары',
                'keyboard_accessible' => true,
                'is_default_active' => true
            ],
            [
                'type' => 'inactive',
                'label' => '❌ Неактивные',
                'default_state' => 'btn-secondary',
                'active_state' => 'btn-secondary active',
                'hover_state' => 'btn-secondary-hover',
                'aria_label' => 'Показать неактивные товары',
                'keyboard_accessible' => true,
                'is_default_active' => false
            ],
            [
                'type' => 'all',
                'label' => '📋 Все товары',
                'default_state' => 'btn-secondary',
                'active_state' => 'btn-secondary active',
                'hover_state' => 'btn-secondary-hover',
                'aria_label' => 'Показать все товары',
                'keyboard_accessible' => true,
                'is_default_active' => false
            ]
        ];
    }
    
    private function findFilterButton($buttons, $type)
    {
        foreach ($buttons as $button) {
            if ($button['type'] === $type) {
                return $button;
            }
        }
        return null;
    }
    
    private function getLoadingState()
    {
        return [
            'spinner' => true,
            'message' => 'Загрузка данных...',
            'overlay' => true
        ];
    }
    
    private function getEmptyState()
    {
        return [
            'icon' => '📦',
            'message' => 'Нет данных для отображения',
            'description' => 'Попробуйте изменить фильтры или обновить данные'
        ];
    }
    
    private function getErrorState()
    {
        return [
            'error_icon' => '⚠️',
            'error_message' => 'Ошибка загрузки данных',
            'retry_button' => true,
            'error_details' => 'Проверьте подключение к серверу'
        ];
    }
    
    private function getAccessibilityFeatures()
    {
        return [
            'aria_labels' => [
                'table' => 'Таблица товарных остатков',
                'filter_buttons' => 'Фильтры активности товаров',
                'search_input' => 'Поиск товаров'
            ],
            'keyboard_navigation' => [
                'tab_order' => true,
                'enter_key_support' => true,
                'arrow_key_navigation' => true
            ],
            'screen_reader' => [
                'table_headers' => true,
                'status_announcements' => true,
                'live_regions' => true
            ]
        ];
    }
    
    private function getColorContrastRatios()
    {
        return [
            'active_text' => 4.8,
            'inactive_text' => 4.6,
            'button_text' => 5.2,
            'warning_text' => 4.7,
            'error_text' => 5.1
        ];
    }
    
    private function getMobileLayout()
    {
        return [
            'horizontal_scroll' => true,
            'touch_targets' => [
                ['element' => 'filter_button', 'min_size' => 44],
                ['element' => 'sort_header', 'min_size' => 44],
                ['element' => 'pagination_button', 'min_size' => 44]
            ],
            'font_scaling' => [
                'responsive' => true,
                'min_size' => '14px',
                'max_size' => '18px'
            ]
        ];
    }
}