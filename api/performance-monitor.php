<?php
/**
 * Performance Monitoring API
 * 
 * Provides endpoints for monitoring database and query performance
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Connect to PostgreSQL
    $dsn = "pgsql:host=localhost;port=5432;dbname=mi_core_db";
    $pdo = new PDO($dsn, 'mi_core_user', 'MiCore2025Secure', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            handleOverview($pdo);
            break;
        case 'slow_queries':
            handleSlowQueries($pdo);
            break;
        case 'index_usage':
            handleIndexUsage($pdo);
            break;
        case 'table_stats':
            handleTableStats($pdo);
            break;
        case 'query_performance':
            handleQueryPerformance($pdo);
            break;
        case 'cache_hit_ratio':
            handleCacheHitRatio($pdo);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

/**
 * Get performance overview
 */
function handleOverview($pdo) {
    $data = [];
    
    // Database size
    $stmt = $pdo->query("
        SELECT 
            pg_size_pretty(pg_database_size('mi_core_db')) as database_size
    ");
    $data['database_size'] = $stmt->fetch()['database_size'];
    
    // Table sizes
    $stmt = $pdo->query("
        SELECT 
            relname as tablename,
            pg_size_pretty(pg_total_relation_size(schemaname||'.'||relname)) as total_size
        FROM pg_stat_user_tables
        WHERE schemaname = 'public'
        ORDER BY pg_total_relation_size(schemaname||'.'||relname) DESC
        LIMIT 10
    ");
    $data['largest_tables'] = $stmt->fetchAll();
    
    // Active connections
    $stmt = $pdo->query("
        SELECT COUNT(*) as active_connections
        FROM pg_stat_activity
        WHERE state = 'active'
    ");
    $data['active_connections'] = $stmt->fetch()['active_connections'];
    
    // Cache hit ratio
    $stmt = $pdo->query("
        SELECT 
            ROUND(
                100.0 * sum(blks_hit) / NULLIF(sum(blks_hit) + sum(blks_read), 0),
                2
            ) as cache_hit_ratio
        FROM pg_stat_database
        WHERE datname = 'mi_core_db'
    ");
    $data['cache_hit_ratio'] = $stmt->fetch()['cache_hit_ratio'] . '%';
    
    // Recent query performance
    $stmt = $pdo->query("
        SELECT 
            query_name,
            COUNT(*) as executions,
            ROUND(AVG(execution_time_ms), 2) as avg_time_ms,
            ROUND(MAX(execution_time_ms), 2) as max_time_ms
        FROM query_performance_log
        WHERE executed_at > NOW() - INTERVAL '1 hour'
        GROUP BY query_name
        ORDER BY avg_time_ms DESC
        LIMIT 10
    ");
    $data['recent_query_performance'] = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get slow queries
 */
function handleSlowQueries($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                query,
                calls,
                ROUND(total_exec_time::numeric, 2) as total_time_ms,
                ROUND(mean_exec_time::numeric, 2) as mean_time_ms,
                ROUND(max_exec_time::numeric, 2) as max_time_ms,
                rows
            FROM pg_stat_statements
            WHERE mean_exec_time > 100
            ORDER BY mean_exec_time DESC
            LIMIT 20
        ");
        $data = $stmt->fetchAll();
    } catch (Exception $e) {
        $data = [
            'error' => 'pg_stat_statements extension not available',
            'message' => 'Enable with: CREATE EXTENSION pg_stat_statements;'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get index usage statistics
 */
function handleIndexUsage($pdo) {
    $tableName = $_GET['table'] ?? null;
    
    $query = "
        SELECT
            schemaname,
            relname as tablename,
            indexrelname as indexname,
            idx_scan as scans,
            idx_tup_read as tuples_read,
            idx_tup_fetch as tuples_fetched,
            pg_size_pretty(pg_relation_size(indexrelid)) as index_size,
            CASE 
                WHEN idx_scan = 0 THEN 'UNUSED'
                WHEN idx_scan < 100 THEN 'LOW_USAGE'
                ELSE 'ACTIVE'
            END as usage_status
        FROM pg_stat_user_indexes
        WHERE schemaname = 'public'
    ";
    
    if ($tableName) {
        $query .= " AND relname = :tablename";
    }
    
    $query .= " ORDER BY idx_scan DESC";
    
    $stmt = $pdo->prepare($query);
    if ($tableName) {
        $stmt->bindValue(':tablename', $tableName);
    }
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'meta' => [
            'table_filter' => $tableName,
            'total_indexes' => count($data)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get table statistics
 */
function handleTableStats($pdo) {
    $stmt = $pdo->query("
        SELECT
            schemaname,
            relname as tablename,
            n_live_tup as live_rows,
            n_dead_tup as dead_rows,
            ROUND(100.0 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 2) as dead_row_percent,
            n_tup_ins as inserts,
            n_tup_upd as updates,
            n_tup_del as deletes,
            last_vacuum,
            last_autovacuum,
            last_analyze,
            last_autoanalyze,
            pg_size_pretty(pg_total_relation_size(schemaname||'.'||relname)) as total_size,
            pg_size_pretty(pg_relation_size(schemaname||'.'||relname)) as table_size,
            pg_size_pretty(pg_total_relation_size(schemaname||'.'||relname) - 
                          pg_relation_size(schemaname||'.'||relname)) as indexes_size
        FROM pg_stat_user_tables
        WHERE schemaname = 'public'
        ORDER BY n_live_tup DESC
    ");
    $data = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get query performance history
 */
function handleQueryPerformance($pdo) {
    $hours = isset($_GET['hours']) ? min((int)$_GET['hours'], 168) : 24;
    $queryName = $_GET['query_name'] ?? null;
    
    $query = "
        SELECT 
            query_name,
            COUNT(*) as executions,
            ROUND(AVG(execution_time_ms), 2) as avg_time_ms,
            ROUND(MIN(execution_time_ms), 2) as min_time_ms,
            ROUND(MAX(execution_time_ms), 2) as max_time_ms,
            ROUND(STDDEV(execution_time_ms), 2) as stddev_time_ms,
            DATE_TRUNC('hour', executed_at) as hour
        FROM query_performance_log
        WHERE executed_at > NOW() - INTERVAL ':hours hours'
    ";
    
    if ($queryName) {
        $query .= " AND query_name = :query_name";
    }
    
    $query .= "
        GROUP BY query_name, DATE_TRUNC('hour', executed_at)
        ORDER BY hour DESC, avg_time_ms DESC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
    if ($queryName) {
        $stmt->bindValue(':query_name', $queryName);
    }
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'meta' => [
            'hours' => $hours,
            'query_name_filter' => $queryName
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get cache hit ratio details
 */
function handleCacheHitRatio($pdo) {
    // Overall cache hit ratio
    $stmt = $pdo->query("
        SELECT 
            datname as database,
            ROUND(
                100.0 * sum(blks_hit) / NULLIF(sum(blks_hit) + sum(blks_read), 0),
                2
            ) as cache_hit_ratio,
            sum(blks_hit) as blocks_hit,
            sum(blks_read) as blocks_read
        FROM pg_stat_database
        WHERE datname = 'mi_core_db'
        GROUP BY datname
    ");
    $overall = $stmt->fetch();
    
    // Per-table cache hit ratio
    $stmt = $pdo->query("
        SELECT 
            schemaname,
            relname as tablename,
            ROUND(
                100.0 * heap_blks_hit / NULLIF(heap_blks_hit + heap_blks_read, 0),
                2
            ) as cache_hit_ratio,
            heap_blks_hit as blocks_hit,
            heap_blks_read as blocks_read
        FROM pg_statio_user_tables
        WHERE schemaname = 'public'
        ORDER BY heap_blks_read DESC
        LIMIT 20
    ");
    $perTable = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'overall' => $overall,
            'per_table' => $perTable
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
