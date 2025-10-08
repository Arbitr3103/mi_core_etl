<?php
/**
 * MDM API Gateway - Unified access to master data
 * Provides backward compatibility while using master data
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load environment configuration
function loadEnvConfig() {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

// Database connection
try {
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed',
        'message' => 'Unable to connect to master data system'
    ]);
    exit();
}

// Include MDM services
require_once __DIR__ . '/../src/MDM/Services/ProductsService.php';
require_once __DIR__ . '/../src/MDM/Services/StatisticsService.php';
require_once __DIR__ . '/../src/MDM/Services/DataQualityService.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'products':
            handleProductsRequest($pdo, $method);
            break;
            
        case 'product-details':
            handleProductDetailsRequest($pdo, $method);
            break;
            
        case 'brands':
            handleBrandsRequest($pdo, $method);
            break;
            
        case 'categories':
            handleCategoriesRequest($pdo, $method);
            break;
            
        case 'search':
            handleSearchRequest($pdo, $method);
            break;
            
        case 'master-data':
            handleMasterDataRequest($pdo, $method);
            break;
            
        case 'mapping':
            handleMappingRequest($pdo, $method);
            break;
            
        case 'stats':
            handleStatsRequest($pdo, $method);
            break;
            
        case 'quality-metrics':
            handleQualityMetricsRequest($pdo, $method);
            break;
            
        case 'status':
        default:
            handleStatusRequest($pdo, $method);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle products request with master data integration
 */
function handleProductsRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $limit = min((int)($_GET['limit'] ?? 50), 1000);
    $offset = (int)($_GET['offset'] ?? 0);
    $source = $_GET['source'] ?? null;
    $brand = $_GET['brand'] ?? null;
    $category = $_GET['category'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $whereConditions = ['1=1'];
    $params = [];
    
    if ($source) {
        $whereConditions[] = 'sm.source = ?';
        $params[] = $source;
    }
    
    if ($brand) {
        $whereConditions[] = 'mp.canonical_brand = ?';
        $params[] = $brand;
    }
    
    if ($category) {
        $whereConditions[] = 'mp.canonical_category = ?';
        $params[] = $category;
    }
    
    if ($search) {
        $whereConditions[] = '(mp.canonical_name LIKE ? OR sm.external_sku LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $sql = "
        SELECT 
            mp.master_id,
            mp.canonical_name,
            mp.canonical_brand,
            mp.canonical_category,
            mp.description,
            mp.attributes,
            mp.status as master_status,
            COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
            GROUP_CONCAT(DISTINCT sm.source) as sources,
            GROUP_CONCAT(DISTINCT sm.external_sku SEPARATOR '|') as external_skus,
            mp.created_at,
            mp.updated_at
        FROM master_products mp
        LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
        WHERE $whereClause
        GROUP BY mp.master_id
        ORDER BY mp.updated_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(DISTINCT mp.master_id) as total
        FROM master_products mp
        LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
        WHERE $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
    $totalCount = $countStmt->fetch()['total'];
    
    // Format products data
    $formattedProducts = array_map(function($product) {
        return [
            'master_id' => $product['master_id'],
            'name' => $product['canonical_name'],
            'brand' => $product['canonical_brand'],
            'category' => $product['canonical_category'],
            'description' => $product['description'],
            'attributes' => $product['attributes'] ? json_decode($product['attributes'], true) : null,
            'status' => $product['master_status'],
            'mapped_skus_count' => (int)$product['mapped_skus_count'],
            'sources' => $product['sources'] ? explode(',', $product['sources']) : [],
            'external_skus' => $product['external_skus'] ? explode('|', $product['external_skus']) : [],
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at']
        ];
    }, $products);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'products' => $formattedProducts,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]
    ]);
}

/**
 * Handle product details request
 */
function handleProductDetailsRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $masterId = $_GET['master_id'] ?? null;
    $externalSku = $_GET['external_sku'] ?? null;
    
    if (!$masterId && !$externalSku) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Either master_id or external_sku is required'
        ]);
        return;
    }
    
    if ($masterId) {
        $sql = "
            SELECT 
                mp.*,
                GROUP_CONCAT(
                    CONCAT(sm.source, ':', sm.external_sku, ':', sm.source_name)
                    SEPARATOR '|'
                ) as mappings
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE mp.master_id = ?
            GROUP BY mp.master_id
        ";
        $params = [$masterId];
    } else {
        $sql = "
            SELECT 
                mp.*,
                GROUP_CONCAT(
                    CONCAT(sm.source, ':', sm.external_sku, ':', sm.source_name)
                    SEPARATOR '|'
                ) as mappings
            FROM master_products mp
            INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE sm.external_sku = ?
            GROUP BY mp.master_id
        ";
        $params = [$externalSku];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $product = $stmt->fetch();
    
    if (!$product) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Product not found'
        ]);
        return;
    }
    
    // Parse mappings
    $mappings = [];
    if ($product['mappings']) {
        foreach (explode('|', $product['mappings']) as $mapping) {
            list($source, $sku, $name) = explode(':', $mapping, 3);
            $mappings[] = [
                'source' => $source,
                'external_sku' => $sku,
                'source_name' => $name
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'master_id' => $product['master_id'],
            'canonical_name' => $product['canonical_name'],
            'canonical_brand' => $product['canonical_brand'],
            'canonical_category' => $product['canonical_category'],
            'description' => $product['description'],
            'attributes' => $product['attributes'] ? json_decode($product['attributes'], true) : null,
            'status' => $product['status'],
            'mappings' => $mappings,
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at']
        ]
    ]);
}

/**
 * Handle brands request using master data
 */
function handleBrandsRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $sql = "
        SELECT 
            canonical_brand as brand,
            COUNT(DISTINCT master_id) as products_count
        FROM master_products 
        WHERE canonical_brand IS NOT NULL 
        AND canonical_brand != '' 
        AND canonical_brand != 'Неизвестный бренд'
        AND status = 'active'
        GROUP BY canonical_brand
        ORDER BY products_count DESC, canonical_brand ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $brands = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'brands' => array_map(function($brand) {
                return [
                    'name' => $brand['brand'],
                    'products_count' => (int)$brand['products_count']
                ];
            }, $brands)
        ]
    ]);
}

/**
 * Handle categories request using master data
 */
function handleCategoriesRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $sql = "
        SELECT 
            canonical_category as category,
            COUNT(DISTINCT master_id) as products_count
        FROM master_products 
        WHERE canonical_category IS NOT NULL 
        AND canonical_category != '' 
        AND canonical_category != 'Без категории'
        AND status = 'active'
        GROUP BY canonical_category
        ORDER BY products_count DESC, canonical_category ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'categories' => array_map(function($category) {
                return [
                    'name' => $category['category'],
                    'products_count' => (int)$category['products_count']
                ];
            }, $categories)
        ]
    ]);
}

/**
 * Handle search request across master data
 */
function handleSearchRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $query = $_GET['q'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    
    if (strlen($query) < 2) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Search query must be at least 2 characters'
        ]);
        return;
    }
    
    $sql = "
        SELECT 
            mp.master_id,
            mp.canonical_name,
            mp.canonical_brand,
            mp.canonical_category,
            sm.external_sku,
            sm.source,
            CASE 
                WHEN mp.canonical_name LIKE ? THEN 3
                WHEN mp.canonical_brand LIKE ? THEN 2
                WHEN sm.external_sku LIKE ? THEN 1
                ELSE 0
            END as relevance_score
        FROM master_products mp
        LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
        WHERE (
            mp.canonical_name LIKE ? OR
            mp.canonical_brand LIKE ? OR
            sm.external_sku LIKE ?
        )
        AND mp.status = 'active'
        ORDER BY relevance_score DESC, mp.canonical_name ASC
        LIMIT ?
    ";
    
    $searchTerm = "%$query%";
    $params = [
        $searchTerm, $searchTerm, $searchTerm, // for relevance scoring
        $searchTerm, $searchTerm, $searchTerm, // for WHERE clause
        $limit
    ];
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'query' => $query,
            'results' => array_map(function($result) {
                return [
                    'master_id' => $result['master_id'],
                    'name' => $result['canonical_name'],
                    'brand' => $result['canonical_brand'],
                    'category' => $result['canonical_category'],
                    'external_sku' => $result['external_sku'],
                    'source' => $result['source'],
                    'relevance_score' => (int)$result['relevance_score']
                ];
            }, $results)
        ]
    ]);
}

/**
 * Handle master data CRUD operations
 */
function handleMasterDataRequest($pdo, $method) {
    switch ($method) {
        case 'GET':
            // Get master product by ID
            $masterId = $_GET['id'] ?? null;
            if (!$masterId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Master ID required']);
                return;
            }
            
            $stmt = $pdo->prepare("SELECT * FROM master_products WHERE master_id = ?");
            $stmt->execute([$masterId]);
            $product = $stmt->fetch();
            
            if (!$product) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Master product not found']);
                return;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $product
            ]);
            break;
            
        case 'POST':
            // Create new master product
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['canonical_name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'canonical_name is required']);
                return;
            }
            
            $masterId = 'PROD_' . uniqid();
            
            $stmt = $pdo->prepare("
                INSERT INTO master_products 
                (master_id, canonical_name, canonical_brand, canonical_category, description, attributes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $masterId,
                $input['canonical_name'],
                $input['canonical_brand'] ?? null,
                $input['canonical_category'] ?? null,
                $input['description'] ?? null,
                isset($input['attributes']) ? json_encode($input['attributes']) : null,
                $input['status'] ?? 'active'
            ]);
            
            echo json_encode([
                'success' => true,
                'data' => ['master_id' => $masterId]
            ]);
            break;
            
        case 'PUT':
            // Update master product
            $masterId = $_GET['id'] ?? null;
            if (!$masterId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Master ID required']);
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("
                UPDATE master_products 
                SET canonical_name = ?, canonical_brand = ?, canonical_category = ?, 
                    description = ?, attributes = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE master_id = ?
            ");
            
            $stmt->execute([
                $input['canonical_name'] ?? null,
                $input['canonical_brand'] ?? null,
                $input['canonical_category'] ?? null,
                $input['description'] ?? null,
                isset($input['attributes']) ? json_encode($input['attributes']) : null,
                $input['status'] ?? 'active',
                $masterId
            ]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
    }
}

/**
 * Handle SKU mapping operations
 */
function handleMappingRequest($pdo, $method) {
    switch ($method) {
        case 'GET':
            $masterId = $_GET['master_id'] ?? null;
            $externalSku = $_GET['external_sku'] ?? null;
            
            if ($masterId) {
                $stmt = $pdo->prepare("SELECT * FROM sku_mapping WHERE master_id = ?");
                $stmt->execute([$masterId]);
            } elseif ($externalSku) {
                $stmt = $pdo->prepare("SELECT * FROM sku_mapping WHERE external_sku = ?");
                $stmt->execute([$externalSku]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'master_id or external_sku required']);
                return;
            }
            
            $mappings = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => $mappings
            ]);
            break;
            
        case 'POST':
            // Create new mapping
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['master_id'], $input['external_sku'], $input['source'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'master_id, external_sku, and source are required']);
                return;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO sku_mapping 
                (master_id, external_sku, source, source_name, source_brand, source_category, confidence_score, verification_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['master_id'],
                $input['external_sku'],
                $input['source'],
                $input['source_name'] ?? null,
                $input['source_brand'] ?? null,
                $input['source_category'] ?? null,
                $input['confidence_score'] ?? null,
                $input['verification_status'] ?? 'manual'
            ]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
    }
}

/**
 * Handle statistics request
 */
function handleStatsRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    // Master products statistics
    $masterStats = $pdo->query("
        SELECT 
            COUNT(*) as total_master_products,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_products,
            COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END) as products_with_brand,
            COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END) as products_with_category,
            COUNT(DISTINCT canonical_brand) as unique_brands,
            COUNT(DISTINCT canonical_category) as unique_categories
        FROM master_products
    ")->fetch();
    
    // SKU mapping statistics
    $mappingStats = $pdo->query("
        SELECT 
            COUNT(*) as total_mappings,
            COUNT(DISTINCT master_id) as mapped_master_products,
            COUNT(DISTINCT source) as data_sources,
            COUNT(CASE WHEN verification_status = 'auto' THEN 1 END) as auto_verified,
            COUNT(CASE WHEN verification_status = 'manual' THEN 1 END) as manually_verified,
            COUNT(CASE WHEN verification_status = 'pending' THEN 1 END) as pending_verification
        FROM sku_mapping
    ")->fetch();
    
    // Data quality metrics
    $qualityStats = $pdo->query("
        SELECT 
            ROUND(AVG(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 100 ELSE 0 END), 2) as brand_completeness,
            ROUND(AVG(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 100 ELSE 0 END), 2) as category_completeness,
            ROUND(AVG(CASE WHEN description IS NOT NULL AND description != '' THEN 100 ELSE 0 END), 2) as description_completeness
        FROM master_products
        WHERE status = 'active'
    ")->fetch();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'master_products' => $masterStats,
            'sku_mappings' => $mappingStats,
            'data_quality' => $qualityStats,
            'coverage_percentage' => $masterStats['total_master_products'] > 0 ? 
                round(($mappingStats['mapped_master_products'] / $masterStats['total_master_products']) * 100, 2) : 0
        ]
    ]);
}

/**
 * Handle quality metrics request
 */
function handleQualityMetricsRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $stmt = $pdo->query("
        SELECT 
            metric_name,
            metric_value,
            total_records,
            good_records,
            calculation_date
        FROM data_quality_metrics 
        ORDER BY calculation_date DESC 
        LIMIT 50
    ");
    
    $metrics = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'metrics' => $metrics
        ]
    ]);
}

/**
 * Handle status request
 */
function handleStatusRequest($pdo, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    // Check system health
    $healthChecks = [
        'database' => true,
        'master_products_table' => false,
        'sku_mapping_table' => false,
        'data_quality_metrics_table' => false
    ];
    
    try {
        // Check if tables exist
        $tables = ['master_products', 'sku_mapping', 'data_quality_metrics'];
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $healthChecks[$table . '_table'] = $stmt->rowCount() > 0;
        }
        
        // Get basic counts
        $counts = [];
        if ($healthChecks['master_products_table']) {
            $counts['master_products'] = $pdo->query("SELECT COUNT(*) FROM master_products")->fetchColumn();
        }
        if ($healthChecks['sku_mapping_table']) {
            $counts['sku_mappings'] = $pdo->query("SELECT COUNT(*) FROM sku_mapping")->fetchColumn();
        }
        
    } catch (Exception $e) {
        $healthChecks['database'] = false;
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'system_status' => 'operational',
            'health_checks' => $healthChecks,
            'counts' => $counts ?? [],
            'api_version' => '1.0.0',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>