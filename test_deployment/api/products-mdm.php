<?php
/**
 * Enhanced Products API with MDM Integration
 * Backward compatible with existing products.php but uses master data
 */

require_once '../CountryFilterAPI.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Метод не поддерживается'
    ], JSON_UNESCAPED_UNICODE);
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

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Check if MDM tables exist
    $mdmAvailable = false;
    try {
        $pdo->query("SELECT 1 FROM master_products LIMIT 1");
        $pdo->query("SELECT 1 FROM sku_mapping LIMIT 1");
        $mdmAvailable = true;
    } catch (Exception $e) {
        // MDM tables don't exist, fall back to original logic
    }
    
    // Collect filter parameters (backward compatibility)
    $filters = [
        'brand_id' => isset($_GET['brand_id']) && $_GET['brand_id'] !== '' ? $_GET['brand_id'] : null,
        'model_id' => isset($_GET['model_id']) && $_GET['model_id'] !== '' ? $_GET['model_id'] : null,
        'year' => isset($_GET['year']) && $_GET['year'] !== '' ? $_GET['year'] : null,
        'country_id' => isset($_GET['country_id']) && $_GET['country_id'] !== '' ? $_GET['country_id'] : null,
        'limit' => isset($_GET['limit']) && $_GET['limit'] !== '' ? (int)$_GET['limit'] : 100,
        'offset' => isset($_GET['offset']) && $_GET['offset'] !== '' ? (int)$_GET['offset'] : 0,
        'use_master_data' => isset($_GET['use_master_data']) ? (bool)$_GET['use_master_data'] : $mdmAvailable
    ];
    
    if ($mdmAvailable && $filters['use_master_data']) {
        // Use MDM-enhanced product filtering
        $result = filterProductsWithMDM($pdo, $filters);
    } else {
        // Fall back to original API
        $api = new CountryFilterAPI();
        
        // Validate parameters
        $validation = $api->validateFilters($filters);
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибки валидации параметров: ' . implode(', ', $validation['errors']),
                'error_type' => 'validation_error'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $existenceValidation = $api->validateFilterExistence($filters);
        if (!$existenceValidation['valid']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибки валидации данных: ' . implode(', ', $existenceValidation['errors']),
                'error_type' => 'data_validation_error'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $result = $api->filterProducts($filters);
    }
    
    if ($result['success']) {
        http_response_code(200);
    } else {
        http_response_code(400);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Enhanced product filtering using MDM data
 */
function filterProductsWithMDM($pdo, $filters) {
    try {
        // Build WHERE conditions for car applicability
        $whereConditions = ['1=1'];
        $params = [];
        
        if ($filters['brand_id']) {
            $whereConditions[] = 'ca.brand_id = ?';
            $params[] = $filters['brand_id'];
        }
        
        if ($filters['model_id']) {
            $whereConditions[] = 'ca.model_id = ?';
            $params[] = $filters['model_id'];
        }
        
        if ($filters['year']) {
            $whereConditions[] = 'ca.year = ?';
            $params[] = $filters['year'];
        }
        
        if ($filters['country_id']) {
            $whereConditions[] = 'ca.country_id = ?';
            $params[] = $filters['country_id'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Enhanced query with MDM integration
        $sql = "
            SELECT DISTINCT
                ca.sku,
                ca.brand_id,
                ca.model_id,
                ca.year,
                ca.country_id,
                b.name as brand_name,
                m.name as model_name,
                c.name as country_name,
                -- Master data integration
                mp.master_id,
                COALESCE(mp.canonical_name, ca.sku) as product_name,
                COALESCE(mp.canonical_brand, b.name) as canonical_brand,
                COALESCE(mp.canonical_category, 'Автозапчасти') as canonical_category,
                mp.description as product_description,
                mp.attributes as product_attributes,
                -- SKU mapping info
                sm.source as data_source,
                sm.confidence_score,
                sm.verification_status,
                -- Data quality indicators
                CASE 
                    WHEN mp.master_id IS NOT NULL THEN 'master_data'
                    WHEN sm.external_sku IS NOT NULL THEN 'mapped'
                    ELSE 'raw_data'
                END as data_quality_level,
                -- Enhanced metadata
                CASE 
                    WHEN mp.master_id IS NOT NULL THEN 'Данные из мастер-системы'
                    WHEN sm.external_sku IS NOT NULL THEN 'Сопоставленные данные'
                    ELSE 'Исходные данные'
                END as data_source_description
            FROM v_car_applicability ca
            LEFT JOIN brands b ON ca.brand_id = b.id
            LEFT JOIN models m ON ca.model_id = m.id
            LEFT JOIN countries c ON ca.country_id = c.id
            -- MDM joins
            LEFT JOIN sku_mapping sm ON ca.sku = sm.external_sku
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
            WHERE $whereClause
            ORDER BY 
                CASE 
                    WHEN mp.master_id IS NOT NULL THEN 1
                    WHEN sm.external_sku IS NOT NULL THEN 2
                    ELSE 3
                END,
                canonical_brand ASC,
                product_name ASC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $filters['limit'];
        $params[] = $filters['offset'];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        // Get total count
        $countSql = "
            SELECT COUNT(DISTINCT ca.sku) as total
            FROM v_car_applicability ca
            LEFT JOIN brands b ON ca.brand_id = b.id
            LEFT JOIN models m ON ca.model_id = m.id
            LEFT JOIN countries c ON ca.country_id = c.id
            WHERE $whereClause
        ";
        
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
        $totalCount = $countStmt->fetch()['total'];
        
        // Calculate data quality statistics
        $qualityStats = calculateDataQualityStats($pdo, $whereClause, array_slice($params, 0, -2));
        
        // Format response with enhanced data
        $formattedProducts = array_map(function($product) {
            return [
                // Original fields for backward compatibility
                'sku' => $product['sku'],
                'brand_id' => (int)$product['brand_id'],
                'model_id' => (int)$product['model_id'],
                'year' => (int)$product['year'],
                'country_id' => (int)$product['country_id'],
                'brand_name' => $product['brand_name'],
                'model_name' => $product['model_name'],
                'country_name' => $product['country_name'],
                
                // Enhanced MDM fields
                'master_id' => $product['master_id'],
                'product_name' => $product['product_name'],
                'canonical_brand' => $product['canonical_brand'],
                'canonical_category' => $product['canonical_category'],
                'product_description' => $product['product_description'],
                'product_attributes' => $product['product_attributes'] ? 
                    json_decode($product['product_attributes'], true) : null,
                
                // Data quality metadata
                'data_quality_level' => $product['data_quality_level'],
                'data_source' => $product['data_source'],
                'data_source_description' => $product['data_source_description'],
                'confidence_score' => $product['confidence_score'] ? 
                    (float)$product['confidence_score'] : null,
                'verification_status' => $product['verification_status']
            ];
        }, $products);
        
        return [
            'success' => true,
            'data' => $formattedProducts,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $filters['limit'],
                'offset' => $filters['offset'],
                'has_more' => ($filters['offset'] + $filters['limit']) < $totalCount
            ],
            'metadata' => [
                'mdm_enabled' => true,
                'data_quality_stats' => $qualityStats,
                'api_version' => '2.0-mdm',
                'backward_compatible' => true
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Ошибка при фильтрации с использованием мастер-данных: ' . $e->getMessage(),
            'fallback_available' => true
        ];
    }
}

/**
 * Calculate data quality statistics for the current filter set
 */
function calculateDataQualityStats($pdo, $whereClause, $params) {
    try {
        $sql = "
            SELECT 
                COUNT(DISTINCT ca.sku) as total_products,
                COUNT(DISTINCT mp.master_id) as products_with_master_data,
                COUNT(DISTINCT sm.external_sku) as products_with_mapping,
                COUNT(DISTINCT CASE WHEN mp.canonical_brand IS NOT NULL AND mp.canonical_brand != 'Неизвестный бренд' THEN mp.master_id END) as products_with_clean_brand,
                COUNT(DISTINCT CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN mp.master_id END) as products_with_category,
                COUNT(DISTINCT CASE WHEN sm.verification_status = 'auto' THEN sm.external_sku END) as auto_verified,
                COUNT(DISTINCT CASE WHEN sm.verification_status = 'manual' THEN sm.external_sku END) as manually_verified
            FROM v_car_applicability ca
            LEFT JOIN sku_mapping sm ON ca.sku = sm.external_sku
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
            WHERE $whereClause
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetch();
        
        $totalProducts = (int)$stats['total_products'];
        
        return [
            'total_products' => $totalProducts,
            'master_data_coverage' => $totalProducts > 0 ? 
                round(($stats['products_with_master_data'] / $totalProducts) * 100, 2) : 0,
            'mapping_coverage' => $totalProducts > 0 ? 
                round(($stats['products_with_mapping'] / $totalProducts) * 100, 2) : 0,
            'brand_quality' => $stats['products_with_master_data'] > 0 ? 
                round(($stats['products_with_clean_brand'] / $stats['products_with_master_data']) * 100, 2) : 0,
            'category_coverage' => $stats['products_with_master_data'] > 0 ? 
                round(($stats['products_with_category'] / $stats['products_with_master_data']) * 100, 2) : 0,
            'verification_stats' => [
                'auto_verified' => (int)$stats['auto_verified'],
                'manually_verified' => (int)$stats['manually_verified'],
                'total_verified' => (int)$stats['auto_verified'] + (int)$stats['manually_verified']
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'error' => 'Unable to calculate quality stats: ' . $e->getMessage()
        ];
    }
}
?>