<?php
/**
 * Enhanced Brands API with MDM Integration
 * Provides canonical brand names from master data
 */

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
        $mdmAvailable = true;
    } catch (Exception $e) {
        // MDM tables don't exist, fall back to original logic
    }
    
    $useMasterData = isset($_GET['use_master_data']) ? (bool)$_GET['use_master_data'] : $mdmAvailable;
    $includeStats = isset($_GET['include_stats']) ? (bool)$_GET['include_stats'] : false;
    
    if ($mdmAvailable && $useMasterData) {
        // Use MDM-enhanced brand data
        $result = getBrandsWithMDM($pdo, $includeStats);
    } else {
        // Fall back to original brands table
        $result = getOriginalBrands($pdo, $includeStats);
    }
    
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Get brands using MDM master data
 */
function getBrandsWithMDM($pdo, $includeStats = false) {
    try {
        // Get canonical brands from master data
        $sql = "
            SELECT 
                mp.canonical_brand as name,
                COUNT(DISTINCT mp.master_id) as master_products_count,
                COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
                COUNT(DISTINCT sm.source) as data_sources_count,
                GROUP_CONCAT(DISTINCT sm.source) as data_sources,
                -- Quality metrics
                COUNT(DISTINCT CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN mp.master_id END) as products_with_category,
                COUNT(DISTINCT CASE WHEN mp.description IS NOT NULL AND mp.description != '' THEN mp.master_id END) as products_with_description,
                -- Verification status
                COUNT(DISTINCT CASE WHEN sm.verification_status = 'auto' THEN sm.external_sku END) as auto_verified_skus,
                COUNT(DISTINCT CASE WHEN sm.verification_status = 'manual' THEN sm.external_sku END) as manually_verified_skus,
                COUNT(DISTINCT CASE WHEN sm.verification_status = 'pending' THEN sm.external_sku END) as pending_verification_skus,
                -- Original brand variations
                GROUP_CONCAT(DISTINCT sm.source_brand ORDER BY sm.source_brand SEPARATOR '|') as original_brand_variations
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE mp.canonical_brand IS NOT NULL 
            AND mp.canonical_brand != '' 
            AND mp.canonical_brand != 'Неизвестный бренд'
            AND mp.status = 'active'
            GROUP BY mp.canonical_brand
            ORDER BY master_products_count DESC, mp.canonical_brand ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $brands = $stmt->fetchAll();
        
        // Also get original brands for comparison/fallback
        $originalBrandsStmt = $pdo->query("
            SELECT 
                b.id,
                b.name,
                COUNT(DISTINCT ca.sku) as applicability_count
            FROM brands b
            LEFT JOIN v_car_applicability ca ON b.id = ca.brand_id
            WHERE b.name IS NOT NULL AND b.name != ''
            GROUP BY b.id, b.name
            ORDER BY b.name ASC
        ");
        $originalBrands = $originalBrandsStmt->fetchAll();
        
        // Calculate overall statistics
        $overallStats = null;
        if ($includeStats) {
            $overallStats = calculateBrandStats($pdo);
        }
        
        // Format brands data
        $formattedBrands = array_map(function($brand) use ($includeStats) {
            $result = [
                'name' => $brand['name'],
                'master_products_count' => (int)$brand['master_products_count'],
                'mapped_skus_count' => (int)$brand['mapped_skus_count']
            ];
            
            if ($includeStats) {
                $result['data_sources'] = $brand['data_sources'] ? explode(',', $brand['data_sources']) : [];
                $result['data_sources_count'] = (int)$brand['data_sources_count'];
                $result['quality_metrics'] = [
                    'category_coverage' => $brand['master_products_count'] > 0 ? 
                        round(($brand['products_with_category'] / $brand['master_products_count']) * 100, 2) : 0,
                    'description_coverage' => $brand['master_products_count'] > 0 ? 
                        round(($brand['products_with_description'] / $brand['master_products_count']) * 100, 2) : 0
                ];
                $result['verification_status'] = [
                    'auto_verified' => (int)$brand['auto_verified_skus'],
                    'manually_verified' => (int)$brand['manually_verified_skus'],
                    'pending_verification' => (int)$brand['pending_verification_skus']
                ];
                $result['original_variations'] = $brand['original_brand_variations'] ? 
                    explode('|', $brand['original_brand_variations']) : [];
            }
            
            return $result;
        }, $brands);
        
        return [
            'success' => true,
            'data' => $formattedBrands,
            'metadata' => [
                'mdm_enabled' => true,
                'total_canonical_brands' => count($formattedBrands),
                'total_original_brands' => count($originalBrands),
                'data_source' => 'master_data',
                'include_stats' => $includeStats,
                'overall_stats' => $overallStats
            ]
        ];
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении брендов из мастер-данных: ' . $e->getMessage());
    }
}

/**
 * Get brands from original brands table (fallback)
 */
function getOriginalBrands($pdo, $includeStats = false) {
    try {
        $sql = "
            SELECT DISTINCT 
                b.id,
                b.name
        ";
        
        if ($includeStats) {
            $sql .= ",
                COUNT(DISTINCT ca.sku) as products_count,
                COUNT(DISTINCT ca.model_id) as models_count,
                COUNT(DISTINCT ca.country_id) as countries_count
            ";
        }
        
        $sql .= "
            FROM brands b
        ";
        
        if ($includeStats) {
            $sql .= "LEFT JOIN v_car_applicability ca ON b.id = ca.brand_id";
        }
        
        $sql .= "
            WHERE b.name IS NOT NULL AND b.name != ''
        ";
        
        if ($includeStats) {
            $sql .= "GROUP BY b.id, b.name";
        }
        
        $sql .= "ORDER BY b.name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $brands = $stmt->fetchAll();
        
        $formattedBrands = array_map(function($brand) use ($includeStats) {
            $result = [
                'id' => (int)$brand['id'],
                'name' => $brand['name']
            ];
            
            if ($includeStats) {
                $result['products_count'] = (int)($brand['products_count'] ?? 0);
                $result['models_count'] = (int)($brand['models_count'] ?? 0);
                $result['countries_count'] = (int)($brand['countries_count'] ?? 0);
            }
            
            return $result;
        }, $brands);
        
        return [
            'success' => true,
            'data' => $formattedBrands,
            'metadata' => [
                'mdm_enabled' => false,
                'total_brands' => count($formattedBrands),
                'data_source' => 'original_brands_table',
                'include_stats' => $includeStats
            ]
        ];
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении брендов из оригинальной таблицы: ' . $e->getMessage());
    }
}

/**
 * Calculate overall brand statistics
 */
function calculateBrandStats($pdo) {
    try {
        $stats = $pdo->query("
            SELECT 
                COUNT(DISTINCT mp.canonical_brand) as total_canonical_brands,
                COUNT(DISTINCT mp.master_id) as total_master_products,
                COUNT(DISTINCT sm.external_sku) as total_mapped_skus,
                COUNT(DISTINCT sm.source) as total_data_sources,
                -- Quality metrics
                COUNT(DISTINCT CASE WHEN mp.canonical_brand IS NOT NULL AND mp.canonical_brand != 'Неизвестный бренд' THEN mp.master_id END) as products_with_clean_brand,
                COUNT(DISTINCT CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN mp.master_id END) as products_with_category,
                -- Top brands by product count
                (SELECT mp2.canonical_brand FROM master_products mp2 WHERE mp2.status = 'active' AND mp2.canonical_brand IS NOT NULL GROUP BY mp2.canonical_brand ORDER BY COUNT(*) DESC LIMIT 1) as top_brand_by_products,
                -- Brand consolidation metrics
                COUNT(DISTINCT sm.source_brand) as original_brand_variations,
                COUNT(DISTINCT mp.canonical_brand) as consolidated_brands
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE mp.status = 'active'
        ")->fetch();
        
        // Calculate consolidation ratio
        $consolidationRatio = $stats['original_brand_variations'] > 0 ? 
            round(($stats['original_brand_variations'] / $stats['consolidated_brands']), 2) : 0;
        
        return [
            'total_canonical_brands' => (int)$stats['total_canonical_brands'],
            'total_master_products' => (int)$stats['total_master_products'],
            'total_mapped_skus' => (int)$stats['total_mapped_skus'],
            'total_data_sources' => (int)$stats['total_data_sources'],
            'brand_quality_percentage' => $stats['total_master_products'] > 0 ? 
                round(($stats['products_with_clean_brand'] / $stats['total_master_products']) * 100, 2) : 0,
            'category_coverage_percentage' => $stats['total_master_products'] > 0 ? 
                round(($stats['products_with_category'] / $stats['total_master_products']) * 100, 2) : 0,
            'top_brand_by_products' => $stats['top_brand_by_products'],
            'brand_consolidation' => [
                'original_variations' => (int)$stats['original_brand_variations'],
                'consolidated_brands' => (int)$stats['consolidated_brands'],
                'consolidation_ratio' => $consolidationRatio
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'error' => 'Unable to calculate brand statistics: ' . $e->getMessage()
        ];
    }
}
?>