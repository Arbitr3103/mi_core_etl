<?php
/**
 * Log Viewer API
 * 
 * Provides endpoints for viewing and searching logs
 * 
 * Requirements: 7.2, 7.3
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class LogViewer {
    private $log_base_path;
    
    public function __construct() {
        $this->log_base_path = __DIR__ . '/../logs';
    }
    
    /**
     * Get available log files
     */
    public function getLogFiles(string $component = null): array {
        $files = [];
        $pattern = $component ? "{$this->log_base_path}/{$component}/*.log" : "{$this->log_base_path}/**/*.log";
        
        foreach (glob($pattern) as $file) {
            $files[] = [
                'path' => str_replace($this->log_base_path . '/', '', $file),
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file),
                'component' => $this->getComponentFromPath($file)
            ];
        }
        
        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $files;
    }
    
    /**
     * Get component from file path
     */
    private function getComponentFromPath(string $path): string {
        if (strpos($path, '/frontend/') !== false) return 'frontend';
        if (strpos($path, '/api/') !== false) return 'api';
        if (strpos($path, '/etl/') !== false) return 'etl';
        if (strpos($path, '/monitoring/') !== false) return 'monitoring';
        return 'general';
    }
    
    /**
     * Read log file with pagination
     */
    public function readLogFile(string $file, int $limit = 100, int $offset = 0, string $level = null): array {
        $file_path = $this->log_base_path . '/' . $file;
        
        if (!file_exists($file_path)) {
            throw new Exception('Log file not found');
        }
        
        $entries = [];
        $total = 0;
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception('Failed to open log file');
        }
        
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            
            if (!$entry) {
                // Try parsing as plain text
                $entry = $this->parsePlainTextLog($line);
            }
            
            // Filter by level if specified
            if ($level && isset($entry['level']) && strtolower($entry['level']) !== strtolower($level)) {
                continue;
            }
            
            $total++;
            
            // Apply pagination
            if ($total > $offset && count($entries) < $limit) {
                $entries[] = $entry;
            }
        }
        
        fclose($handle);
        
        return [
            'entries' => $entries,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Parse plain text log line
     */
    private function parsePlainTextLog(string $line): array {
        // Try to extract timestamp, level, and message
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+): (.+)/';
        
        if (preg_match($pattern, $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => $matches[3],
                'raw' => $line
            ];
        }
        
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'INFO',
            'message' => trim($line),
            'raw' => $line
        ];
    }
    
    /**
     * Search logs
     */
    public function searchLogs(string $query, string $component = null, int $days = 7): array {
        $results = [];
        
        // Get log files to search
        $files = $this->getLogFiles($component);
        
        // Filter by date range
        $cutoff_time = strtotime("-{$days} days");
        $files = array_filter($files, function($file) use ($cutoff_time) {
            return $file['modified'] >= $cutoff_time;
        });
        
        // Search each file
        foreach ($files as $file) {
            $file_path = $this->log_base_path . '/' . $file['path'];
            $handle = fopen($file_path, 'r');
            
            if (!$handle) continue;
            
            $line_number = 0;
            while (($line = fgets($handle)) !== false) {
                $line_number++;
                
                if (stripos($line, $query) !== false) {
                    $entry = json_decode($line, true);
                    
                    if (!$entry) {
                        $entry = $this->parsePlainTextLog($line);
                    }
                    
                    $entry['file'] = $file['path'];
                    $entry['line_number'] = $line_number;
                    
                    $results[] = $entry;
                    
                    // Limit results
                    if (count($results) >= 100) {
                        break 2;
                    }
                }
            }
            
            fclose($handle);
        }
        
        return $results;
    }
    
    /**
     * Get log statistics
     */
    public function getLogStatistics(int $days = 7): array {
        $stats = [
            'total_logs' => 0,
            'by_level' => [],
            'by_component' => [],
            'by_day' => [],
            'total_size' => 0,
            'file_count' => 0
        ];
        
        $files = $this->getLogFiles();
        $cutoff_time = strtotime("-{$days} days");
        
        foreach ($files as $file) {
            if ($file['modified'] < $cutoff_time) {
                continue;
            }
            
            $stats['file_count']++;
            $stats['total_size'] += $file['size'];
            
            $file_path = $this->log_base_path . '/' . $file['path'];
            $handle = fopen($file_path, 'r');
            
            if (!$handle) continue;
            
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                
                if (!$entry) continue;
                
                $stats['total_logs']++;
                
                // Count by level
                $level = $entry['level'] ?? 'UNKNOWN';
                $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
                
                // Count by component
                $component = $entry['component'] ?? 'unknown';
                $stats['by_component'][$component] = ($stats['by_component'][$component] ?? 0) + 1;
                
                // Count by day
                $day = substr($entry['timestamp'] ?? '', 0, 10);
                $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
            }
            
            fclose($handle);
        }
        
        return $stats;
    }
    
    /**
     * Get error trends
     */
    public function getErrorTrends(int $days = 7): array {
        $trends = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trends[$date] = [
                'date' => $date,
                'total' => 0,
                'errors' => 0,
                'warnings' => 0,
                'critical' => 0
            ];
        }
        
        $files = $this->getLogFiles();
        $cutoff_time = strtotime("-{$days} days");
        
        foreach ($files as $file) {
            if ($file['modified'] < $cutoff_time) {
                continue;
            }
            
            $file_path = $this->log_base_path . '/' . $file['path'];
            $handle = fopen($file_path, 'r');
            
            if (!$handle) continue;
            
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                
                if (!$entry) continue;
                
                $day = substr($entry['timestamp'] ?? '', 0, 10);
                
                if (!isset($trends[$day])) continue;
                
                $trends[$day]['total']++;
                
                $level = strtolower($entry['level'] ?? '');
                if (in_array($level, ['error', 'critical', 'emergency', 'alert'])) {
                    $trends[$day]['errors']++;
                }
                if ($level === 'warning') {
                    $trends[$day]['warnings']++;
                }
                if (in_array($level, ['critical', 'emergency', 'alert'])) {
                    $trends[$day]['critical']++;
                }
            }
            
            fclose($handle);
        }
        
        return array_values($trends);
    }
}

// Handle requests
try {
    $log_viewer = new LogViewer();
    $action = $_GET['action'] ?? 'files';
    
    switch ($action) {
        case 'files':
            $component = $_GET['component'] ?? null;
            $files = $log_viewer->getLogFiles($component);
            echo json_encode(['success' => true, 'files' => $files]);
            break;
        
        case 'read':
            $file = $_GET['file'] ?? '';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $level = $_GET['level'] ?? null;
            
            if (empty($file)) {
                throw new Exception('File parameter is required');
            }
            
            $data = $log_viewer->readLogFile($file, $limit, $offset, $level);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
        
        case 'search':
            $query = $_GET['query'] ?? '';
            $component = $_GET['component'] ?? null;
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            
            if (empty($query)) {
                throw new Exception('Query parameter is required');
            }
            
            $results = $log_viewer->searchLogs($query, $component, $days);
            echo json_encode(['success' => true, 'results' => $results]);
            break;
        
        case 'stats':
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $stats = $log_viewer->getLogStatistics($days);
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
        
        case 'trends':
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $trends = $log_viewer->getErrorTrends($days);
            echo json_encode(['success' => true, 'trends' => $trends]);
            break;
        
        default:
            throw new Exception('Unknown action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
