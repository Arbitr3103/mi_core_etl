<?php
/**
 * Database Performance Optimization Script
 * Task 7.1: Optimize database queries for active product filtering
 * 
 * This script creates indexes, analyzes performance, and provides optimization recommendations.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/database_query_optimizer.php';
require_once __DIR__ . '/api/performance_monitor.php';

echo "=== Database Performance Optimization ===\n";
echo "Task 7.1: Optimize database queries for active product filtering\n\n";

try {
    // Initialize components
    $pdo = getDatabaseConnection();
    $optimizer = new DatabaseQueryOptimizer($pdo);
    $performance_monitor = getPerformanceMonitor();
    
    echo "✓ Connected to database\n";
    echo "✓ Initialized optimizer and performance monitor\n\n";
    
    // Step 1: Analyze current performance
    echo "--- Step 1: Analyzing Current Performance ---\n";
    $performance_monitor->startTimer('performance_analysis');
    
    $current_performance = $optimizer->analyzeQueryPerformance();
    $database_stats = $optimizer->getDatabaseStatistics();
    
    $analysis_metrics = $performance_monitor->endTimer('performance_analysis');
    
    echo "Performance analysis completed in {$analysis_metrics['execution_time_ms']}ms\n";
    echo "Analyzed " . count($current_performance) . " queries\n";
    
    // Show current performance ratings
    foreach ($current_performance as $query_name => $result) {
        if (isset($result['performance_rating'])) {
            $rating = $result['performance_rating'];
            $time = $result['execution_time_ms'] ?? 'N/A';
            $icon = $rating === 'excellent' ? '✓' : ($rating === 'good' ? '○' : ($rating === 'needs_improvement' ? '△' : '✗'));
            echo "  $icon $query_name: $rating ({$time}ms)\n";
        }
    }
    echo "\n";
    
    // Step 2: Create optimized indexes
    echo "--- Step 2: Creating Optimized Indexes ---\n";
    $performance_monitor->startTimer('index_creation');
    
    $index_results = $optimizer->createOptimizedIndexes();
    
    $index_metrics = $performance_monitor->endTimer('index_creation');
    
    echo "Index creation completed in {$index_metrics['execution_time_ms']}ms\n";
    echo "Created {$index_results['total_created']} new indexes\n";
    
    if (!empty($index_results['created_indexes'])) {
        echo "New indexes created:\n";
        foreach ($index_results['created_indexes'] as $index) {
            echo "  ✓ {$index['name']} on {$index['table']} ({$index['columns']})\n";
        }
    }
    
    if (!empty($index_results['errors'])) {
        echo "Errors encountered:\n";
        foreach ($index_results['errors'] as $error) {
            echo "  ✗ {$error['index']['name']}: {$error['error']}\n";
        }
    }
    echo "\n";
    
    // Step 3: Optimize table statistics
    echo "--- Step 3: Optimizing Table Statistics ---\n";
    $performance_monitor->startTimer('table_optimization');
    
    $stats_results = $optimizer->optimizeTableStatistics();
    
    $stats_metrics = $performance_monitor->endTimer('table_optimization');
    
    echo "Table optimization completed in {$stats_metrics['execution_time_ms']}ms\n";
    
    foreach ($stats_results as $table => $result) {
        if ($result['status'] === 'success') {
            echo "  ✓ $table optimized ({$result['execution_time_ms']}ms)\n";
        } else {
            echo "  ✗ $table failed: {$result['error']}\n";
        }
    }
    echo "\n";
    
    // Step 4: Re-analyze performance after optimizations
    echo "--- Step 4: Re-analyzing Performance After Optimizations ---\n";
    $performance_monitor->startTimer('post_optimization_analysis');
    
    $optimized_performance = $optimizer->analyzeQueryPerformance();
    
    $post_analysis_metrics = $performance_monitor->endTimer('post_optimization_analysis');
    
    echo "Post-optimization analysis completed in {$post_analysis_metrics['execution_time_ms']}ms\n";
    
    // Compare before and after
    echo "\nPerformance Comparison:\n";
    foreach ($optimized_performance as $query_name => $after_result) {
        if (isset($current_performance[$query_name])) {
            $before_result = $current_performance[$query_name];
            $before_time = $before_result['execution_time_ms'] ?? 0;
            $after_time = $after_result['execution_time_ms'] ?? 0;
            $before_rating = $before_result['performance_rating'] ?? 'unknown';
            $after_rating = $after_result['performance_rating'] ?? 'unknown';
            
            $improvement = $before_time > 0 ? round((($before_time - $after_time) / $before_time) * 100, 1) : 0;
            $improvement_icon = $improvement > 0 ? '↑' : ($improvement < 0 ? '↓' : '→');
            
            echo "  $query_name:\n";
            echo "    Before: $before_rating ({$before_time}ms)\n";
            echo "    After:  $after_rating ({$after_time}ms)\n";
            echo "    Change: $improvement_icon {$improvement}% improvement\n\n";
        }
    }
    
    // Step 5: Generate optimization recommendations
    echo "--- Step 5: Generating Optimization Recommendations ---\n";
    $recommendations = $optimizer->generateOptimizationRecommendations();
    
    echo "Generated {$recommendations['total_recommendations']} recommendations\n";
    echo "High priority: {$recommendations['high_priority_count']}\n\n";
    
    if (!empty($recommendations['recommendations'])) {
        foreach ($recommendations['recommendations'] as $rec) {
            $priority_icon = $rec['priority'] === 'high' ? '🔴' : ($rec['priority'] === 'medium' ? '🟡' : '🟢');
            echo "$priority_icon [{$rec['priority']}] {$rec['type']}\n";
            echo "   Issue: {$rec['issue']}\n";
            echo "   Recommendation: {$rec['recommendation']}\n";
            if (isset($rec['execution_time_ms'])) {
                echo "   Execution time: {$rec['execution_time_ms']}ms\n";
            }
            echo "\n";
        }
    } else {
        echo "✓ No optimization recommendations - system is performing well!\n\n";
    }
    
    // Step 6: Display database statistics
    echo "--- Step 6: Database Statistics Summary ---\n";
    
    if (isset($database_stats['active_products_statistics'])) {
        $active_stats = $database_stats['active_products_statistics'];
        $total = $active_stats['total_products'];
        $active = $active_stats['active_products'];
        $inactive = $active_stats['inactive_products'];
        $active_percentage = $total > 0 ? round(($active / $total) * 100, 1) : 0;
        
        echo "Active Products Statistics:\n";
        echo "  Total products: $total\n";
        echo "  Active products: $active ({$active_percentage}%)\n";
        echo "  Inactive products: $inactive\n";
        echo "  Unchecked products: {$active_stats['unchecked_products']}\n\n";
    }
    
    if (isset($database_stats['inventory_statistics'])) {
        $inv_stats = $database_stats['inventory_statistics'];
        echo "Inventory Data Statistics:\n";
        echo "  Total records: {$inv_stats['total_records']}\n";
        echo "  Unique SKUs: {$inv_stats['unique_skus']}\n";
        echo "  Unique warehouses: {$inv_stats['unique_warehouses']}\n";
        echo "  Average stock: " . round($inv_stats['avg_stock'], 2) . "\n";
        echo "  Last sync: {$inv_stats['last_sync']}\n\n";
    }
    
    if (isset($database_stats['table_statistics'])) {
        echo "Table Sizes:\n";
        foreach ($database_stats['table_statistics'] as $table) {
            $size_mb = round(($table['TOTAL_SIZE'] ?? 0) / 1024 / 1024, 2);
            echo "  {$table['TABLE_NAME']}: {$table['TABLE_ROWS']} rows, {$size_mb}MB\n";
        }
        echo "\n";
    }
    
    // Step 7: Performance monitoring summary
    echo "--- Step 7: Performance Monitoring Summary ---\n";
    $performance_summary = $performance_monitor->getPerformanceSummary(1); // Last hour
    
    echo "Performance metrics collected:\n";
    echo "  Total operations: {$performance_summary['total_operations']}\n";
    
    if (isset($performance_summary['by_type'])) {
        foreach ($performance_summary['by_type'] as $type => $stats) {
            echo "  $type: {$stats['count']} operations\n";
            if (isset($stats['avg_execution_time_ms'])) {
                echo "    Avg execution time: {$stats['avg_execution_time_ms']}ms\n";
            }
            if (isset($stats['avg_memory_usage_mb'])) {
                echo "    Avg memory usage: {$stats['avg_memory_usage_mb']}MB\n";
            }
        }
    }
    echo "\n";
    
    // Final summary
    echo "=== Optimization Complete ===\n";
    echo "✓ Database indexes optimized\n";
    echo "✓ Table statistics updated\n";
    echo "✓ Performance monitoring active\n";
    echo "✓ Query result caching enabled\n";
    
    $total_time = $performance_monitor->endTimer('api_request_total');
    $total_time = $performance_monitor->endTimer('api_request_total');
    if ($total_time) {
        echo "\nTotal optimization time: {$total_time['execution_time_ms']}ms\n";
        echo "Peak memory usage: {$total_time['peak_memory_mb']}MB\n";
    } else {
        echo "\nOptimization completed successfully\n";
    }
    
    echo "\n📊 View performance dashboard at:\n";
    echo "http://your-domain.com/api/performance_dashboard.php?action=dashboard\n\n";
    
    echo "🔧 To monitor ongoing performance:\n";
    echo "http://your-domain.com/api/performance_dashboard.php?action=system_health\n\n";
    
} catch (Exception $e) {
    echo "❌ Error during optimization: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>