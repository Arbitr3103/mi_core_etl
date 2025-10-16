<?php
/**
 * Mock API Server for Performance Testing
 * 
 * Provides mock responses when the real API is not available
 */

class MockAPIServer
{
    private $responses = [];

    public function __construct()
    {
        $this->setupMockResponses();
    }

    private function setupMockResponses(): void
    {
        $this->responses = [
            'critical_stock' => [
                'success' => true,
                'data' => [
                    ['id' => 'test_1', 'name' => 'Test Product 1', 'stock' => 5],
                    ['id' => 'test_2', 'name' => 'Test Product 2', 'stock' => 3],
                    ['id' => 'test_3', 'name' => 'Test Product 3', 'stock' => 8]
                ],
                'count' => 3,
                'execution_time' => rand(50, 200) / 1000
            ],
            'low_stock' => [
                'success' => true,
                'data' => [
                    ['id' => 'test_4', 'name' => 'Test Product 4', 'stock' => 15],
                    ['id' => 'test_5', 'name' => 'Test Product 5', 'stock' => 12],
                    ['id' => 'test_6', 'name' => 'Test Product 6', 'stock' => 18]
                ],
                'count' => 3,
                'execution_time' => rand(60, 250) / 1000
            ],
            'overstock' => [
                'success' => true,
                'data' => [
                    ['id' => 'test_7', 'name' => 'Test Product 7', 'stock' => 150],
                    ['id' => 'test_8', 'name' => 'Test Product 8', 'stock' => 200]
                ],
                'count' => 2,
                'execution_time' => rand(40, 180) / 1000
            ],
            'activity_stats' => [
                'success' => true,
                'data' => [
                    'active_products' => 48,
                    'inactive_products' => 128,
                    'total_products' => 176,
                    'active_percentage' => 27.3,
                    'products_with_stock' => 42,
                    'products_without_stock' => 6
                ],
                'execution_time' => rand(80, 300) / 1000
            ],
            'inactive_products' => [
                'success' => true,
                'data' => array_map(function($i) {
                    return [
                        'id' => "inactive_{$i}",
                        'name' => "Inactive Product {$i}",
                        'reason' => ['not_visible', 'no_stock', 'not_processed'][rand(0, 2)]
                    ];
                }, range(1, 20)),
                'count' => 20,
                'execution_time' => rand(100, 400) / 1000
            ],
            'activity_changes' => [
                'success' => true,
                'data' => array_map(function($i) {
                    return [
                        'product_id' => "test_{$i}",
                        'previous_status' => rand(0, 1),
                        'new_status' => rand(0, 1),
                        'changed_at' => date('Y-m-d H:i:s', strtotime("-{$i} days"))
                    ];
                }, range(1, 10)),
                'count' => 10,
                'execution_time' => rand(120, 500) / 1000
            ]
        ];
    }

    public function handleRequest(string $action, array $params = []): array
    {
        // Simulate processing time
        usleep(rand(10000, 100000)); // 10-100ms

        if (!isset($this->responses[$action])) {
            return [
                'success' => false,
                'error' => 'Unknown action',
                'execution_time' => 0.001
            ];
        }

        $response = $this->responses[$action];
        
        // Add some randomization to make it more realistic
        if (isset($response['data']) && is_array($response['data'])) {
            // Randomly modify some values
            foreach ($response['data'] as &$item) {
                if (is_array($item) && isset($item['stock'])) {
                    $item['stock'] += rand(-2, 2);
                    $item['stock'] = max(0, $item['stock']);
                }
            }
        }

        // Update execution time
        $response['execution_time'] = rand(50, 300) / 1000;
        
        return $response;
    }

    public function startServer(int $port = 8080): void
    {
        echo "Starting mock API server on port {$port}...\n";
        
        // Simple HTTP server implementation
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', $port);
        socket_listen($socket);
        
        echo "Mock API server listening on http://127.0.0.1:{$port}\n";
        echo "Available endpoints:\n";
        foreach (array_keys($this->responses) as $action) {
            echo "  - /?action={$action}\n";
        }
        echo "\nPress Ctrl+C to stop the server.\n\n";
        
        while (true) {
            $client = socket_accept($socket);
            
            if ($client) {
                $request = socket_read($client, 1024);
                $this->handleHTTPRequest($client, $request);
                socket_close($client);
            }
        }
    }

    private function handleHTTPRequest($client, string $request): void
    {
        $lines = explode("\n", $request);
        $requestLine = $lines[0] ?? '';
        
        if (preg_match('/GET\s+\/\?action=([a-zA-Z_]+)/', $requestLine, $matches)) {
            $action = $matches[1];
            $response = $this->handleRequest($action);
        } else {
            $response = ['success' => false, 'error' => 'Invalid request'];
        }
        
        $json = json_encode($response);
        $httpResponse = "HTTP/1.1 200 OK\r\n";
        $httpResponse .= "Content-Type: application/json\r\n";
        $httpResponse .= "Content-Length: " . strlen($json) . "\r\n";
        $httpResponse .= "Access-Control-Allow-Origin: *\r\n";
        $httpResponse .= "\r\n";
        $httpResponse .= $json;
        
        socket_write($client, $httpResponse);
    }
}

// Start mock server if run directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $server = new MockAPIServer();
    $port = isset($argv[1]) ? (int)$argv[1] : 8080;
    $server->startServer($port);
}