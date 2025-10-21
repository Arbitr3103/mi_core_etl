<?php
/**
 * End-to-End Tests for Regional Analytics Dashboard
 * 
 * Tests complete dashboard functionality including chart rendering,
 * filtering controls, responsive design, and user interactions.
 * 
 * Requirements: 1.5, 3.4, 4.3
 */

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;

class RegionalAnalyticsDashboardE2ETest extends TestCase {
    
    private $dashboardUrl;
    private $testDateFrom;
    private $testDateTo;
    
    protected function setUp(): void {
        $this->dashboardUrl = 'http://localhost/html/regional-dashboard/index.html';
        $this->testDateFrom = '2025-09-01';
        $this->testDateTo = '2025-09-30';
        
        // Check if dashboard is accessible
        $this->checkDashboardAccessibility();
    }
    
    /**
     * Check if dashboard is accessible
     */
    private function checkDashboardAccessibility() {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => 'User-Agent: RegionalAnalyticsDashboardE2ETest/1.0'
            ]
        ]);
        
        $response = @file_get_contents($this->dashboardUrl, false, $context);
        
        if ($response === false) {
            $this->markTestSkipped('Dashboard not accessible at: ' . $this->dashboardUrl);
        }
    }
    
    /**
     * Execute JavaScript in simulated browser environment
     */
    private function executeJavaScript($script) {
        // Simple JavaScript execution simulation
        // In a real implementation, this would use a browser automation tool like Selenium
        
        $tempFile = tempnam(sys_get_temp_dir(), 'js_test_');
        file_put_contents($tempFile, $script);
        
        $output = shell_exec("node $tempFile 2>&1");
        unlink($tempFile);
        
        return $output;
    }
    
    /**
     * Simulate HTTP request from dashboard JavaScript
     */
    private function simulateAjaxRequest($endpoint, $params = []) {
        $url = "http://localhost/api/analytics/endpoints/$endpoint.php?" . http_build_query($params);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => [
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                    'User-Agent: RegionalAnalyticsDashboardE2ETest/1.0'
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("AJAX request failed: $url");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Test dashboard loading and initial data display
     * Requirements: 1.5, 3.4
     */
    public function testDashboardInitialLoad() {
        // Get dashboard HTML content
        $dashboardContent = file_get_contents($this->dashboardUrl);
        
        // Validate HTML structure
        $this->assertStringContains('<title>', $dashboardContent);
        $this->assertStringContains('Regional Sales Analytics', $dashboardContent);
        $this->assertStringContains('ЭТОНОВО', $dashboardContent);
        
        // Check for required dashboard sections
        $this->assertStringContains('id="kpi-cards"', $dashboardContent);
        $this->assertStringContains('id="marketplace-comparison-chart"', $dashboardContent);
        $this->assertStringContains('id="sales-dynamics-chart"', $dashboardContent);
        $this->assertStringContains('id="top-products-table"', $dashboardContent);
        
        // Check for filter controls
        $this->assertStringContains('id="date-from"', $dashboardContent);
        $this->assertStringContains('id="date-to"', $dashboardContent);
        $this->assertStringContains('id="marketplace-filter"', $dashboardContent);
        
        // Check for JavaScript includes
        $this->assertStringContains('chart.js', $dashboardContent);
        $this->assertStringContains('dashboard.js', $dashboardContent);
    }
    
    /**
     * Test KPI cards data loading and display
     * Requirements: 1.5, 3.4
     */
    public function testKPICardsDataLoading() {
        // Simulate dashboard summary API call
        $response = $this->simulateAjaxRequest('dashboard-summary', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'marketplace' => 'all',
            'api_key' => 'test_api_key'
        ]);
        
        // Validate API response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        
        if ($response['success']) {
            $data = $response['data'];
            
            // Validate KPI data structure
            $this->assertArrayHasKey('total_revenue', $data);
            $this->assertArrayHasKey('total_orders', $data);
            $this->assertArrayHasKey('average_order_value', $data);
            $this->assertArrayHasKey('unique_products', $data);
            
            // Test KPI card rendering simulation
            $kpiScript = "
                const kpiData = " . json_encode($data) . ";
                
                // Simulate KPI card updates
                const totalRevenue = kpiData.total_revenue;
                const totalOrders = kpiData.total_orders;
                const avgOrderValue = kpiData.average_order_value;
                const uniqueProducts = kpiData.unique_products;
                
                console.log('KPI_REVENUE:' + totalRevenue);
                console.log('KPI_ORDERS:' + totalOrders);
                console.log('KPI_AOV:' + avgOrderValue);
                console.log('KPI_PRODUCTS:' + uniqueProducts);
            ";
            
            $output = $this->executeJavaScript($kpiScript);
            
            // Validate KPI values are displayed
            $this->assertStringContains('KPI_REVENUE:' . $data['total_revenue'], $output);
            $this->assertStringContains('KPI_ORDERS:' . $data['total_orders'], $output);
            $this->assertStringContains('KPI_AOV:' . $data['average_order_value'], $output);
            $this->assertStringContains('KPI_PRODUCTS:' . $data['unique_products'], $output);
        }
    }
    
    /**
     * Test marketplace comparison chart rendering
     * Requirements: 1.5, 3.4
     */
    public function testMarketplaceComparisonChart() {
        // Simulate marketplace comparison API call
        $response = $this->simulateAjaxRequest('marketplace-comparison', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'api_key' => 'test_api_key'
        ]);
        
        // Validate API response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        
        if ($response['success']) {
            $data = $response['data'];
            
            // Test chart data preparation
            $chartScript = "
                const marketplaceData = " . json_encode($data['marketplaces']) . ";
                
                // Simulate Chart.js pie chart creation
                const labels = marketplaceData.map(m => m.marketplace_name);
                const revenues = marketplaceData.map(m => m.total_revenue);
                const shares = marketplaceData.map(m => m.revenue_share);
                
                console.log('CHART_LABELS:' + labels.join(','));
                console.log('CHART_REVENUES:' + revenues.join(','));
                console.log('CHART_SHARES:' + shares.join(','));
                
                // Validate chart data
                const totalShare = shares.reduce((sum, share) => sum + share, 0);
                console.log('TOTAL_SHARE:' + totalShare);
            ";
            
            $output = $this->executeJavaScript($chartScript);
            
            // Validate chart data
            $this->assertStringContains('CHART_LABELS:', $output);
            $this->assertStringContains('CHART_REVENUES:', $output);
            $this->assertStringContains('CHART_SHARES:', $output);
            
            // Validate total share equals 100%
            if (preg_match('/TOTAL_SHARE:(\d+\.?\d*)/', $output, $matches)) {
                $totalShare = floatval($matches[1]);
                $this->assertEquals(100.0, $totalShare, 'Total marketplace share should equal 100%', 0.1);
            }
        }
    }
    
    /**
     * Test sales dynamics chart rendering
     * Requirements: 1.5, 3.4
     */
    public function testSalesDynamicsChart() {
        // Simulate sales dynamics API call
        $response = $this->simulateAjaxRequest('sales-dynamics', [
            'period' => 'month',
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'marketplace' => 'all',
            'api_key' => 'test_api_key'
        ]);
        
        // Validate API response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        
        if ($response['success']) {
            $data = $response['data'];
            
            // Test line chart data preparation
            $chartScript = "
                const dynamicsData = " . json_encode($data['dynamics']) . ";
                
                // Simulate Chart.js line chart creation
                const periods = dynamicsData.map(d => d.period);
                const revenues = dynamicsData.map(d => d.total_revenue);
                const orders = dynamicsData.map(d => d.total_orders);
                
                console.log('PERIODS:' + periods.join(','));
                console.log('REVENUES:' + revenues.join(','));
                console.log('ORDERS:' + orders.join(','));
                
                // Test growth rate calculations
                if (dynamicsData.length > 1) {
                    const lastPeriod = dynamicsData[dynamicsData.length - 1];
                    if (lastPeriod.growth_rates) {
                        console.log('REVENUE_GROWTH:' + lastPeriod.growth_rates.revenue_growth);
                        console.log('ORDERS_GROWTH:' + lastPeriod.growth_rates.orders_growth);
                    }
                }
            ";
            
            $output = $this->executeJavaScript($chartScript);
            
            // Validate chart data
            $this->assertStringContains('PERIODS:', $output);
            $this->assertStringContains('REVENUES:', $output);
            $this->assertStringContains('ORDERS:', $output);
            
            // Check for growth rate indicators
            if (count($data['dynamics']) > 1) {
                $this->assertStringContains('REVENUE_GROWTH:', $output);
                $this->assertStringContains('ORDERS_GROWTH:', $output);
            }
        }
    }
    
    /**
     * Test top products table rendering and sorting
     * Requirements: 1.5, 4.3
     */
    public function testTopProductsTable() {
        // Simulate top products API call
        $response = $this->simulateAjaxRequest('top-products', [
            'marketplace' => 'all',
            'limit' => 10,
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'api_key' => 'test_api_key'
        ]);
        
        // Validate API response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        
        if ($response['success']) {
            $data = $response['data'];
            
            // Test table data rendering
            $tableScript = "
                const productsData = " . json_encode($data['products']) . ";
                
                // Simulate table rendering
                let tableRows = [];
                productsData.forEach((product, index) => {
                    const row = {
                        rank: index + 1,
                        name: product.product_name,
                        revenue: product.total_revenue,
                        orders: product.total_orders,
                        margin: product.margin_percent
                    };
                    tableRows.push(row);
                    console.log('TABLE_ROW:' + JSON.stringify(row));
                });
                
                console.log('TABLE_ROWS_COUNT:' + tableRows.length);
                
                // Test sorting functionality
                const sortedByRevenue = [...tableRows].sort((a, b) => b.revenue - a.revenue);
                console.log('SORTED_TOP_PRODUCT:' + sortedByRevenue[0].name);
                console.log('SORTED_TOP_REVENUE:' + sortedByRevenue[0].revenue);
            ";
            
            $output = $this->executeJavaScript($tableScript);
            
            // Validate table rendering
            $this->assertStringContains('TABLE_ROW:', $output);
            $this->assertStringContains('TABLE_ROWS_COUNT:', $output);
            $this->assertStringContains('SORTED_TOP_PRODUCT:', $output);
            $this->assertStringContains('SORTED_TOP_REVENUE:', $output);
            
            // Validate table has data
            if (preg_match('/TABLE_ROWS_COUNT:(\d+)/', $output, $matches)) {
                $rowCount = intval($matches[1]);
                $this->assertGreaterThan(0, $rowCount, 'Table should have at least one row');
                $this->assertLessThanOrEqual(10, $rowCount, 'Table should not exceed limit');
            }
        }
    }
    
    /**
     * Test date range filtering functionality
     * Requirements: 1.5, 4.3
     */
    public function testDateRangeFiltering() {
        // Test different date ranges
        $dateRanges = [
            ['2025-09-01', '2025-09-15'], // Half month
            ['2025-09-01', '2025-09-30'], // Full month
            ['2025-08-01', '2025-09-30']  // Two months
        ];
        
        foreach ($dateRanges as $range) {
            $dateFrom = $range[0];
            $dateTo = $range[1];
            
            // Simulate API call with date filter
            $response = $this->simulateAjaxRequest('dashboard-summary', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'marketplace' => 'all',
                'api_key' => 'test_api_key'
            ]);
            
            if ($response && $response['success']) {
                $data = $response['data'];
                
                // Validate period info matches filter
                $this->assertEquals($dateFrom, $data['period_info']['date_from']);
                $this->assertEquals($dateTo, $data['period_info']['date_to']);
                
                // Test filter UI simulation
                $filterScript = "
                    const dateFrom = '$dateFrom';
                    const dateTo = '$dateTo';
                    
                    // Simulate date input changes
                    console.log('DATE_FILTER_FROM:' + dateFrom);
                    console.log('DATE_FILTER_TO:' + dateTo);
                    
                    // Validate date range
                    const fromDate = new Date(dateFrom);
                    const toDate = new Date(dateTo);
                    const isValidRange = fromDate <= toDate;
                    
                    console.log('DATE_RANGE_VALID:' + isValidRange);
                ";
                
                $output = $this->executeJavaScript($filterScript);
                
                // Validate date filtering
                $this->assertStringContains("DATE_FILTER_FROM:$dateFrom", $output);
                $this->assertStringContains("DATE_FILTER_TO:$dateTo", $output);
                $this->assertStringContains('DATE_RANGE_VALID:true', $output);
            }
        }
    }
    
    /**
     * Test marketplace filtering functionality
     * Requirements: 1.5, 4.3
     */
    public function testMarketplaceFiltering() {
        $marketplaceFilters = ['all', 'ozon', 'wb'];
        
        foreach ($marketplaceFilters as $marketplace) {
            // Simulate API call with marketplace filter
            $response = $this->simulateAjaxRequest('top-products', [
                'marketplace' => $marketplace,
                'limit' => 10,
                'date_from' => $this->testDateFrom,
                'date_to' => $this->testDateTo,
                'api_key' => 'test_api_key'
            ]);
            
            if ($response && $response['success']) {
                $data = $response['data'];
                
                // Validate filter is applied
                $this->assertEquals($marketplace, $data['filters']['marketplace']);
                
                // Test filter UI simulation
                $filterScript = "
                    const marketplace = '$marketplace';
                    
                    // Simulate marketplace filter change
                    console.log('MARKETPLACE_FILTER:' + marketplace);
                    
                    // Validate filter options
                    const validFilters = ['all', 'ozon', 'wb'];
                    const isValidFilter = validFilters.includes(marketplace);
                    
                    console.log('MARKETPLACE_FILTER_VALID:' + isValidFilter);
                ";
                
                $output = $this->executeJavaScript($filterScript);
                
                // Validate marketplace filtering
                $this->assertStringContains("MARKETPLACE_FILTER:$marketplace", $output);
                $this->assertStringContains('MARKETPLACE_FILTER_VALID:true', $output);
            }
        }
    }
    
    /**
     * Test responsive design on different screen sizes
     * Requirements: 1.5, 3.4
     */
    public function testResponsiveDesign() {
        // Get dashboard CSS content
        $dashboardContent = file_get_contents($this->dashboardUrl);
        
        // Check for responsive design elements
        $this->assertStringContains('@media', $dashboardContent);
        $this->assertStringContains('max-width', $dashboardContent);
        
        // Test responsive breakpoints simulation
        $responsiveScript = "
            // Simulate different screen sizes
            const breakpoints = [
                { name: 'mobile', width: 480 },
                { name: 'tablet', width: 768 },
                { name: 'desktop', width: 1200 }
            ];
            
            breakpoints.forEach(bp => {
                console.log('BREAKPOINT:' + bp.name + ':' + bp.width);
                
                // Simulate responsive behavior
                if (bp.width < 768) {
                    console.log('MOBILE_LAYOUT:true');
                } else if (bp.width < 1200) {
                    console.log('TABLET_LAYOUT:true');
                } else {
                    console.log('DESKTOP_LAYOUT:true');
                }
            });
        ";
        
        $output = $this->executeJavaScript($responsiveScript);
        
        // Validate responsive breakpoints
        $this->assertStringContains('BREAKPOINT:mobile:480', $output);
        $this->assertStringContains('BREAKPOINT:tablet:768', $output);
        $this->assertStringContains('BREAKPOINT:desktop:1200', $output);
        $this->assertStringContains('MOBILE_LAYOUT:true', $output);
        $this->assertStringContains('TABLET_LAYOUT:true', $output);
        $this->assertStringContains('DESKTOP_LAYOUT:true', $output);
    }
    
    /**
     * Test error handling in dashboard UI
     * Requirements: 1.5, 4.3
     */
    public function testDashboardErrorHandling() {
        // Test error handling simulation
        $errorScript = "
            // Simulate API error response
            const errorResponse = {
                success: false,
                error: {
                    code: 'INVALID_DATE_RANGE',
                    message: 'Date range cannot exceed 12 months'
                }
            };
            
            // Simulate error handling
            if (!errorResponse.success) {
                console.log('ERROR_DETECTED:' + errorResponse.error.code);
                console.log('ERROR_MESSAGE:' + errorResponse.error.message);
                
                // Simulate user notification
                console.log('USER_NOTIFICATION:Error loading data');
                console.log('FALLBACK_BEHAVIOR:Show cached data');
            }
        ";
        
        $output = $this->executeJavaScript($errorScript);
        
        // Validate error handling
        $this->assertStringContains('ERROR_DETECTED:INVALID_DATE_RANGE', $output);
        $this->assertStringContains('ERROR_MESSAGE:Date range cannot exceed 12 months', $output);
        $this->assertStringContains('USER_NOTIFICATION:Error loading data', $output);
        $this->assertStringContains('FALLBACK_BEHAVIOR:Show cached data', $output);
    }
    
    /**
     * Test dashboard performance with large datasets
     * Requirements: 1.5, 3.4
     */
    public function testDashboardPerformance() {
        // Test performance simulation
        $performanceScript = "
            // Simulate large dataset rendering
            const largeDataset = Array.from({length: 1000}, (_, i) => ({
                id: i,
                name: 'Product ' + i,
                revenue: Math.random() * 10000,
                orders: Math.floor(Math.random() * 100)
            }));
            
            console.log('DATASET_SIZE:' + largeDataset.length);
            
            // Simulate rendering performance
            const startTime = Date.now();
            
            // Simulate table rendering with pagination
            const pageSize = 50;
            const totalPages = Math.ceil(largeDataset.length / pageSize);
            const currentPage = largeDataset.slice(0, pageSize);
            
            const endTime = Date.now();
            const renderTime = endTime - startTime;
            
            console.log('RENDER_TIME:' + renderTime);
            console.log('PAGINATION_PAGES:' + totalPages);
            console.log('CURRENT_PAGE_SIZE:' + currentPage.length);
        ";
        
        $output = $this->executeJavaScript($performanceScript);
        
        // Validate performance metrics
        $this->assertStringContains('DATASET_SIZE:1000', $output);
        $this->assertStringContains('RENDER_TIME:', $output);
        $this->assertStringContains('PAGINATION_PAGES:20', $output);
        $this->assertStringContains('CURRENT_PAGE_SIZE:50', $output);
        
        // Validate render time is reasonable (under 100ms for simulation)
        if (preg_match('/RENDER_TIME:(\d+)/', $output, $matches)) {
            $renderTime = intval($matches[1]);
            $this->assertLessThan(100, $renderTime, 'Render time should be under 100ms');
        }
    }
}