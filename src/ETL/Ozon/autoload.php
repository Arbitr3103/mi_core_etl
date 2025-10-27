<?php

/**
 * Autoloader for Ozon ETL System
 * 
 * Provides class autoloading and configuration loading for the ETL system
 */

// Prevent multiple inclusions
if (defined('OZON_ETL_AUTOLOAD_LOADED')) {
    return;
}
define('OZON_ETL_AUTOLOAD_LOADED', true);

// Define base paths
define('OZON_ETL_ROOT', __DIR__);
define('PROJECT_ROOT', dirname(dirname(dirname(__DIR__))));

// Load application configuration first
$appConfigPath = PROJECT_ROOT . '/config/app.php';
if (file_exists($appConfigPath)) {
    $appConfig = require $appConfigPath;
}

// Register autoloader for ETL classes
spl_autoload_register(function ($className) {
    // Only handle our namespace
    if (strpos($className, 'MiCore\\ETL\\Ozon\\') !== 0) {
        return;
    }
    
    // Convert namespace to file path
    $relativePath = str_replace('MiCore\\ETL\\Ozon\\', '', $className);
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath);
    $filePath = OZON_ETL_ROOT . DIRECTORY_SEPARATOR . $relativePath . '.php';
    
    if (file_exists($filePath)) {
        require_once $filePath;
    }
});

// Load ETL configuration
if (!function_exists('loadETLConfig')) {
    function loadETLConfig(): array
{
    $configPath = OZON_ETL_ROOT . '/Config/etl_config.php';
    
    if (!file_exists($configPath)) {
        // Create default configuration if it doesn't exist
        $defaultConfig = [
            'database' => [
                'host' => $_ENV['PG_HOST'] ?? 'localhost',
                'port' => $_ENV['PG_PORT'] ?? 5432,
                'database' => $_ENV['PG_NAME'] ?? 'mi_core_db',
                'username' => $_ENV['PG_USER'] ?? 'mi_core_user',
                'password' => $_ENV['PG_PASSWORD'] ?? '',
                'charset' => 'utf8',
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            ],
            'ozon_api' => [
                'client_id' => $_ENV['OZON_CLIENT_ID'] ?? '',
                'api_key' => $_ENV['OZON_API_KEY'] ?? '',
                'base_url' => 'https://api-seller.ozon.ru',
                'timeout' => 30,
                'max_retries' => 3,
                'retry_delay' => 1
            ],
            'product_etl' => [
                'batch_size' => 1000,
                'max_products' => 0, // 0 = no limit
                'enable_progress' => true
            ],
            'inventory_etl' => [
                'report_language' => 'DEFAULT',
                'max_wait_time' => 1800,
                'poll_interval' => 60,
                'validate_csv_structure' => true
            ]
        ];
        
        // Create config directory if it doesn't exist
        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        // Save default configuration
        file_put_contents($configPath, "<?php\n\nreturn " . var_export($defaultConfig, true) . ";\n");
        
        return $defaultConfig;
    }
    
    return require $configPath;
}
}

// Create mock classes for testing (override real classes)
class_alias('MockDatabaseConnection', 'MiCore\\ETL\\Ozon\\Core\\DatabaseConnection');
class_alias('MockLogger', 'MiCore\\ETL\\Ozon\\Core\\Logger');
class_alias('MockOzonApiClient', 'MiCore\\ETL\\Ozon\\Api\\OzonApiClient');

// Mock classes for testing
class MockDatabaseConnection
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function query(string $sql, array $params = []): array
    {
        // Mock database responses for testing
        if (strpos($sql, 'SELECT 1') !== false) {
            return [['test' => 1]];
        }
        
        if (strpos($sql, 'COUNT(*)') !== false) {
            return [['count' => 100]];
        }
        
        if (strpos($sql, 'dim_products') !== false) {
            return [
                [
                    'total_products' => 1000,
                    'products_with_visibility' => 950,
                    'products_without_visibility' => 50,
                    'visible_products' => 800,
                    'hidden_products' => 100,
                    'moderation_products' => 30,
                    'declined_products' => 20,
                    'unknown_products' => 0,
                    'oldest_update' => '2024-01-01 00:00:00',
                    'newest_update' => date('Y-m-d H:i:s'),
                    'visibility_coverage_percent' => 95.0
                ]
            ];
        }
        
        return [];
    }
    
    public function execute(string $sql, array $params = []): bool
    {
        return true;
    }
    
    public function beginTransaction(): void
    {
        // Mock transaction
    }
    
    public function commit(): void
    {
        // Mock commit
    }
    
    public function rollback(): void
    {
        // Mock rollback
    }
    
    public function batchInsert(string $table, array $columns, array $data): int
    {
        return count($data);
    }
}

class MockLogger
{
    private string $logFile;
    private string $level;
    
    public function __construct(string $logFile, string $level = 'INFO')
    {
        $this->logFile = $logFile;
        $this->level = $level;
    }
    
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }
    
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
    
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = "[$timestamp] [$level] $message$contextStr\n";
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

class MockOzonApiClient
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function createProductsReport(): array
    {
        return ['result' => ['code' => 'mock_products_report_' . uniqid()]];
    }
    
    public function createStockReport(string $language = 'DEFAULT'): array
    {
        return ['result' => ['code' => 'mock_stock_report_' . uniqid()]];
    }
    
    public function waitForReportCompletion(string $reportCode): array
    {
        return [
            'result' => [
                'status' => 'success',
                'file' => 'http://mock.api/reports/' . $reportCode . '.csv'
            ]
        ];
    }
    
    public function getReportStatus(string $reportCode): array
    {
        return [
            'result' => [
                'status' => 'success',
                'file' => 'http://mock.api/reports/' . $reportCode . '.csv'
            ]
        ];
    }
    
    public function downloadAndParseCsv(string $fileUrl, bool $hasHeaders = true, string $delimiter = ',', string $enclosure = '"'): array
    {
        // Generate mock CSV data
        if (strpos($fileUrl, 'products') !== false) {
            return $this->generateMockProductsData();
        } else {
            return $this->generateMockInventoryData();
        }
    }
    
    private function generateMockProductsData(): array
    {
        $products = [];
        for ($i = 1; $i <= 50; $i++) {
            $products[] = [
                'product_id' => 1000000 + $i,
                'offer_id' => 'MOCK_SKU_' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'name' => 'Mock Product ' . $i,
                'fbo_sku' => 'FBO_MOCK_' . $i,
                'fbs_sku' => 'FBS_MOCK_' . $i,
                'status' => 'active',
                'visibility' => $i % 5 === 0 ? 'HIDDEN' : 'VISIBLE'
            ];
        }
        return $products;
    }
    
    private function generateMockInventoryData(): array
    {
        $inventory = [];
        $warehouses = ['Mock Warehouse A', 'Mock Warehouse B', 'Mock Warehouse C'];
        
        for ($i = 1; $i <= 50; $i++) {
            foreach ($warehouses as $warehouse) {
                $inventory[] = [
                    'SKU' => 'MOCK_SKU_' . str_pad($i, 4, '0', STR_PAD_LEFT),
                    'Warehouse name' => $warehouse,
                    'Item Name' => 'Mock Product ' . $i,
                    'Present' => rand(0, 100),
                    'Reserved' => rand(0, 20)
                ];
            }
        }
        return $inventory;
    }
}

// Return configuration for use by scripts
return loadETLConfig();