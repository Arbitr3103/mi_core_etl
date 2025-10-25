<?php
/**
 * Mock Analytics API Response for Testing
 * 
 * Provides realistic mock responses for Ozon Analytics API
 * to support comprehensive testing without making real API calls.
 * 
 * Task: 9.1 - Реализовать mock объекты для Analytics API
 */

class MockAnalyticsApiResponse {
    
    /**
     * Generate mock stock on warehouses response
     */
    public static function getStockOnWarehousesResponse(int $offset = 0, int $limit = 100): array {
        $warehouses = [
            'РФЦ Москва',
            'РФЦ Санкт-Петербург', 
            'МРФЦ Екатеринбург',
            'РФЦ Новосибирск',
            'МРФЦ Казань',
            'РФЦ Ростов-на-Дону',
            'МРФЦ Краснодар',
            'РФЦ Нижний Новгород'
        ];
        
        $products = [
            ['sku' => 'TEST-SKU-001', 'name' => 'Test Product 1', 'category' => 'Electronics', 'brand' => 'TestBrand'],
            ['sku' => 'TEST-SKU-002', 'name' => 'Test Product 2', 'category' => 'Clothing', 'brand' => 'TestBrand'],
            ['sku' => 'TEST-SKU-003', 'name' => 'Test Product 3', 'category' => 'Home', 'brand' => 'TestBrand'],
            ['sku' => 'TEST-SKU-004', 'name' => 'Test Product 4', 'category' => 'Sports', 'brand' => 'TestBrand'],
            ['sku' => 'TEST-SKU-005', 'name' => 'Test Product 5', 'category' => 'Books', 'brand' => 'TestBrand']
        ];
        
        $data = [];
        $count = 0;
        
        foreach ($warehouses as $warehouse) {
            foreach ($products as $product) {
                if ($count >= $offset && count($data) < $limit) {
                    $data[] = [
                        'sku' => $product['sku'],
                        'warehouse_name' => $warehouse,
                        'available_stock' => rand(0, 500),
                        'reserved_stock' => rand(0, 50),
                        'total_stock' => rand(50, 600),
                        'product_name' => $product['name'],
                        'category' => $product['category'],
                        'brand' => $product['brand'],
                        'price' => round(rand(500, 5000) + (rand(0, 99) / 100), 2),
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s', time() - rand(0, 3600))
                    ];
                }
                $count++;
            }
        }
        
        return [
            'result' => [
                'data' => $data,
                'total_count' => count($warehouses) * count($products)
            ]
        ];
    }
    
    /**
     * Generate mock error response
     */
    public static function getErrorResponse(int $errorCode = 400, string $message = 'Bad Request'): array {
        return [
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'details' => 'Mock error response for testing'
            ]
        ];
    }
    
    /**
     * Generate mock rate limit response
     */
    public static function getRateLimitResponse(): array {
        return [
            'error' => [
                'code' => 429,
                'message' => 'Too Many Requests',
                'details' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => 60
            ]
        ];
    }
    
    /**
     * Generate mock authentication error response
     */
    public static function getAuthErrorResponse(): array {
        return [
            'error' => [
                'code' => 401,
                'message' => 'Unauthorized',
                'details' => 'Invalid Client-Id or Api-Key'
            ]
        ];
    }
    
    /**
     * Generate mock empty response
     */
    public static function getEmptyResponse(): array {
        return [
            'result' => [
                'data' => [],
                'total_count' => 0
            ]
        ];
    }
    
    /**
     * Generate mock response with anomalies for testing data validation
     */
    public static function getAnomalousDataResponse(): array {
        return [
            'result' => [
                'data' => [
                    [
                        'sku' => 'ANOMALY-001',
                        'warehouse_name' => 'РФЦ Москва',
                        'available_stock' => -10, // Negative stock (anomaly)
                        'reserved_stock' => 5,
                        'total_stock' => 15,
                        'product_name' => 'Anomalous Product 1',
                        'price' => -100.50, // Negative price (anomaly)
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    [
                        'sku' => 'ANOMALY-002',
                        'warehouse_name' => '', // Empty warehouse name (anomaly)
                        'available_stock' => 1000000, // Extremely high stock (anomaly)
                        'reserved_stock' => 0,
                        'total_stock' => 50, // Total < Available (anomaly)
                        'product_name' => '123', // Suspicious product name (anomaly)
                        'price' => 0.01, // Suspiciously low price (anomaly)
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s', time() - 86400) // Old data (anomaly)
                    ],
                    [
                        'sku' => '', // Empty SKU (anomaly)
                        'warehouse_name' => 'РФЦ СПб',
                        'available_stock' => 0,
                        'reserved_stock' => 100, // Reserved > Available when Available = 0 (anomaly)
                        'total_stock' => 100,
                        'product_name' => 'Valid Product Name',
                        'price' => 1500.00,
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ],
                'total_count' => 3
            ]
        ];
    }
    
    /**
     * Generate mock response with warehouse name variations for normalization testing
     */
    public static function getWarehouseVariationsResponse(): array {
        return [
            'result' => [
                'data' => [
                    [
                        'sku' => 'NORM-001',
                        'warehouse_name' => 'рфц москва', // Lowercase
                        'available_stock' => 100,
                        'reserved_stock' => 10,
                        'total_stock' => 110,
                        'product_name' => 'Normalization Test 1',
                        'price' => 1000.00,
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    [
                        'sku' => 'NORM-002',
                        'warehouse_name' => '  РФЦ   Санкт-Петербург  ', // Extra spaces
                        'available_stock' => 200,
                        'reserved_stock' => 20,
                        'total_stock' => 220,
                        'product_name' => 'Normalization Test 2',
                        'price' => 2000.00,
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    [
                        'sku' => 'NORM-003',
                        'warehouse_name' => 'Региональный Фулфилмент Центр Москва', // Full name
                        'available_stock' => 300,
                        'reserved_stock' => 30,
                        'total_stock' => 330,
                        'product_name' => 'Normalization Test 3',
                        'price' => 3000.00,
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    [
                        'sku' => 'NORM-004',
                        'warehouse_name' => 'МРФЦ Екатеринбург!!!', // With special characters
                        'available_stock' => 400,
                        'reserved_stock' => 40,
                        'total_stock' => 440,
                        'product_name' => 'Normalization Test 4',
                        'price' => 4000.00,
                        'currency' => 'RUB',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ],
                'total_count' => 4
            ]
        ];
    }
    
    /**
     * Generate mock HTTP response headers
     */
    public static function getMockHeaders(int $statusCode = 200): array {
        $headers = [
            'HTTP/1.1 ' . $statusCode . ' ' . self::getStatusText($statusCode),
            'Content-Type: application/json',
            'X-Request-Id: mock-request-' . uniqid(),
            'X-RateLimit-Limit: 30',
            'X-RateLimit-Remaining: 29',
            'X-RateLimit-Reset: ' . (time() + 60),
            'Date: ' . gmdate('D, d M Y H:i:s T')
        ];
        
        if ($statusCode === 429) {
            $headers[] = 'Retry-After: 60';
        }
        
        return $headers;
    }
    
    /**
     * Get HTTP status text
     */
    private static function getStatusText(int $statusCode): string {
        $statusTexts = [
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable'
        ];
        
        return $statusTexts[$statusCode] ?? 'Unknown';
    }
    
    /**
     * Generate mock cURL response info
     */
    public static function getMockCurlInfo(int $httpCode = 200, float $totalTime = 0.5): array {
        return [
            'url' => 'https://api-seller.ozon.ru/v2/analytics/stock_on_warehouses',
            'content_type' => 'application/json',
            'http_code' => $httpCode,
            'header_size' => 256,
            'request_size' => 512,
            'filetime' => -1,
            'ssl_verify_result' => 0,
            'redirect_count' => 0,
            'total_time' => $totalTime,
            'namelookup_time' => 0.001,
            'connect_time' => 0.01,
            'pretransfer_time' => 0.02,
            'size_upload' => 512,
            'size_download' => 1024,
            'speed_download' => 2048,
            'speed_upload' => 1024,
            'download_content_length' => 1024,
            'upload_content_length' => 512,
            'starttransfer_time' => 0.1,
            'redirect_time' => 0,
            'certinfo' => [],
            'primary_ip' => '192.168.1.1',
            'primary_port' => 443,
            'local_ip' => '192.168.1.100',
            'local_port' => 54321
        ];
    }
}