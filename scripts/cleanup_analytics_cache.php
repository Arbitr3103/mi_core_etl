#!/usr/bin/env php
<?php
/**
 * Analytics API Cache Cleanup Script
 * 
 * Cleans up expired cache files and manages cache size limits
 * 
 * Usage: php cleanup_analytics_cache.php [--force] [--dry-run]
 */

require_once __DIR__ . '/../config/analytics_api_cache.php';

// Parse command line arguments
$force = in_array('--force', $argv);
$dryRun = in_array('--dry-run', $argv);

$config = include __DIR__ . '/../config/analytics_api_cache.php';
$cacheDir = $config['cache_directory'];

echo "Analytics API Cache Cleanup\n";
echo "===========================\n";
echo "Cache directory: $cacheDir\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "CLEANUP") . "\n";
echo "\n";

$totalCleaned = 0;
$totalSize = 0;

foreach ($config['cache_types'] as $type => $typeConfig) {
    $typeDir = $cacheDir . '/' . $typeConfig['directory'];
    
    if (!is_dir($typeDir)) {
        echo "Creating cache directory: $typeDir\n";
        if (!$dryRun) {
            mkdir($typeDir, 0755, true);
        }
        continue;
    }
    
    echo "Checking cache type: $type\n";
    
    $files = glob($typeDir . '/*');
    $typeCleaned = 0;
    $typeSize = 0;
    
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        
        $fileSize = filesize($file);
        $fileAge = time() - filemtime($file);
        $ttl = $typeConfig['ttl'];
        
        $typeSize += $fileSize;
        
        if ($fileAge > $ttl || $force) {
            echo "  Removing expired file: " . basename($file) . " (age: {$fileAge}s, size: " . formatBytes($fileSize) . ")\n";
            
            if (!$dryRun) {
                unlink($file);
            }
            
            $typeCleaned++;
            $totalCleaned++;
        }
    }
    
    $totalSize += $typeSize;
    
    echo "  Files in $type: " . count($files) . "\n";
    echo "  Files cleaned: $typeCleaned\n";
    echo "  Total size: " . formatBytes($typeSize) . "\n";
    echo "\n";
}

echo "Summary:\n";
echo "--------\n";
echo "Total files cleaned: $totalCleaned\n";
echo "Total cache size: " . formatBytes($totalSize) . "\n";

if ($totalSize > $config['cleanup']['max_cache_size']) {
    echo "WARNING: Cache size exceeds limit (" . formatBytes($config['cleanup']['max_cache_size']) . ")\n";
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}