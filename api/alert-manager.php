<?php
/**
 * Alert Management System
 * 
 * Manages error alerts, notifications, and alert rules
 * 
 * Requirements: 7.4
 */

require_once __DIR__ . '/classes/ErrorLogger.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class AlertManager {
    private $config_file;
    private $alert_log_file;
    private $error_logger;
    
    public function __construct() {
        $this->config_file = __DIR__ . '/../config/alert_rules.json';
        $this->alert_log_file = __DIR__ . '/../logs/alerts-' . date('Y-m-d') . '.log';
        
        // Initialize error logger
        $this->error_logger = new ErrorLogger([
            'log_path' => __DIR__ . '/../logs',
            'max_log_size' => '50MB',
            'max_log_files' => 30
        ]);
    }
    
    /**
     * Get alert rules
     */
    public function getAlertRules(): array {
        if (!file_exists($this->config_file)) {
            return $this->getDefaultRules();
        }
        
        $content = file_get_contents($this->config_file);
        $rules = json_decode($content, true);
        
        return $rules ?: $this->getDefaultRules();
    }
    
    /**
     * Get default alert rules
     */
    private function getDefaultRules(): array {
        return [
            'rules' => [
                [
                    'id' => 'critical_errors',
                    'name' => 'Critical Errors',
                    'enabled' => true,
                    'conditions' => [
                        'level' => ['critical', 'emergency', 'alert']
                    ],
                    'actions' => [
                        'email' => true,
                        'slack' => true,
                        'telegram' => false
                    ],
                    'throttle' => 300 // 5 minutes
                ],
                [
                    'id' => 'high_error_rate',
                    'name' => 'High Error Rate',
                    'enabled' => true,
                    'conditions' => [
                        'error_count' => 10,
                        'time_window' => 300 // 5 minutes
                    ],
                    'actions' => [
                        'email' => true,
                        'slack' => true
                    ],
                    'throttle' => 600 // 10 minutes
                ],
                [
                    'id' => 'slow_api_response',
                    'name' => 'Slow API Response',
                    'enabled' => true,
                    'conditions' => [
                        'component' => 'api',
                        'duration_ms' => 5000
                    ],
                    'actions' => [
                        'slack' => true
                    ],
                    'throttle' => 900 // 15 minutes
                ],
                [
                    'id' => 'etl_failure',
                    'name' => 'ETL Process Failure',
                    'enabled' => true,
                    'conditions' => [
                        'component' => 'etl',
                        'level' => ['error', 'critical']
                    ],
                    'actions' => [
                        'email' => true,
                        'slack' => true
                    ],
                    'throttle' => 1800 // 30 minutes
                ],
                [
                    'id' => 'frontend_errors',
                    'name' => 'Frontend Errors',
                    'enabled' => true,
                    'conditions' => [
                        'component' => 'frontend',
                        'level' => ['error', 'critical']
                    ],
                    'actions' => [
                        'slack' => true
                    ],
                    'throttle' => 600 // 10 minutes
                ]
            ],
            'channels' => [
                'email' => [
                    'enabled' => !empty($_ENV['ALERT_EMAIL']),
                    'recipients' => explode(',', $_ENV['ALERT_EMAIL'] ?? '')
                ],
                'slack' => [
                    'enabled' => !empty($_ENV['SLACK_WEBHOOK_URL']),
                    'webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? ''
                ],
                'telegram' => [
                    'enabled' => !empty($_ENV['TELEGRAM_BOT_TOKEN']),
                    'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
                    'chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? ''
                ]
            ]
        ];
    }
    
    /**
     * Save alert rules
     */
    public function saveAlertRules(array $rules): bool {
        $json = json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($this->config_file, $json) !== false;
    }
    
    /**
     * Get recent alerts
     */
    public function getRecentAlerts(int $limit = 50): array {
        $alerts = [];
        
        // Read from alert log files (last 7 days)
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $log_file = __DIR__ . '/../logs/alerts-' . $date . '.log';
            
            if (file_exists($log_file)) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                foreach ($lines as $line) {
                    $alert = json_decode($line, true);
                    if ($alert) {
                        $alerts[] = $alert;
                    }
                }
            }
        }
        
        // Sort by timestamp (newest first)
        usort($alerts, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return array_slice($alerts, 0, $limit);
    }
    
    /**
     * Get alert statistics
     */
    public function getAlertStats(int $days = 7): array {
        $stats = [
            'total_alerts' => 0,
            'by_level' => [],
            'by_component' => [],
            'by_rule' => [],
            'by_day' => []
        ];
        
        // Read alert logs
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $log_file = __DIR__ . '/../logs/alerts-' . $date . '.log';
            
            if (file_exists($log_file)) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                foreach ($lines as $line) {
                    $alert = json_decode($line, true);
                    if (!$alert) continue;
                    
                    $stats['total_alerts']++;
                    
                    // Count by level
                    $level = $alert['level'] ?? 'UNKNOWN';
                    $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
                    
                    // Count by component
                    $component = $alert['component'] ?? 'unknown';
                    $stats['by_component'][$component] = ($stats['by_component'][$component] ?? 0) + 1;
                    
                    // Count by day
                    $day = substr($alert['timestamp'] ?? '', 0, 10);
                    $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Test alert channels
     */
    public function testAlertChannels(): array {
        $results = [];
        $rules = $this->getAlertRules();
        
        // Test email
        if ($rules['channels']['email']['enabled']) {
            $results['email'] = $this->testEmailChannel($rules['channels']['email']);
        }
        
        // Test Slack
        if ($rules['channels']['slack']['enabled']) {
            $results['slack'] = $this->testSlackChannel($rules['channels']['slack']);
        }
        
        // Test Telegram
        if ($rules['channels']['telegram']['enabled']) {
            $results['telegram'] = $this->testTelegramChannel($rules['channels']['telegram']);
        }
        
        return $results;
    }
    
    /**
     * Test email channel
     */
    private function testEmailChannel(array $config): array {
        try {
            $to = $config['recipients'][0] ?? '';
            if (empty($to)) {
                return ['success' => false, 'error' => 'No recipients configured'];
            }
            
            $subject = 'Test Alert - Warehouse Dashboard';
            $message = 'This is a test alert from the Warehouse Dashboard alert system.';
            
            $success = mail($to, $subject, $message);
            
            return [
                'success' => $success,
                'message' => $success ? 'Test email sent successfully' : 'Failed to send test email'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test Slack channel
     */
    private function testSlackChannel(array $config): array {
        try {
            $webhook_url = $config['webhook_url'] ?? '';
            if (empty($webhook_url)) {
                return ['success' => false, 'error' => 'No webhook URL configured'];
            }
            
            $payload = [
                'text' => '🧪 Test Alert - Warehouse Dashboard',
                'attachments' => [[
                    'color' => 'good',
                    'text' => 'This is a test alert from the Warehouse Dashboard alert system.',
                    'footer' => 'Alert Manager',
                    'ts' => time()
                ]]
            ];
            
            $ch = curl_init($webhook_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $success = $http_code === 200;
            
            return [
                'success' => $success,
                'message' => $success ? 'Test Slack message sent successfully' : 'Failed to send test Slack message',
                'http_code' => $http_code
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test Telegram channel
     */
    private function testTelegramChannel(array $config): array {
        try {
            $bot_token = $config['bot_token'] ?? '';
            $chat_id = $config['chat_id'] ?? '';
            
            if (empty($bot_token) || empty($chat_id)) {
                return ['success' => false, 'error' => 'Bot token or chat ID not configured'];
            }
            
            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            $message = "🧪 Test Alert - Warehouse Dashboard\n\nThis is a test alert from the Warehouse Dashboard alert system.";
            
            $data = [
                'chat_id' => $chat_id,
                'text' => $message
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $success = $http_code === 200;
            
            return [
                'success' => $success,
                'message' => $success ? 'Test Telegram message sent successfully' : 'Failed to send test Telegram message',
                'http_code' => $http_code
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// Handle requests
try {
    $alert_manager = new AlertManager();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? 'rules';
            
            switch ($action) {
                case 'rules':
                    $rules = $alert_manager->getAlertRules();
                    echo json_encode(['success' => true, 'rules' => $rules]);
                    break;
                
                case 'recent':
                    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                    $alerts = $alert_manager->getRecentAlerts($limit);
                    echo json_encode(['success' => true, 'alerts' => $alerts]);
                    break;
                
                case 'stats':
                    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
                    $stats = $alert_manager->getAlertStats($days);
                    echo json_encode(['success' => true, 'stats' => $stats]);
                    break;
                
                case 'test':
                    $results = $alert_manager->testAlertChannels();
                    echo json_encode(['success' => true, 'test_results' => $results]);
                    break;
                
                default:
                    throw new Exception('Unknown action');
            }
            break;
        
        case 'POST':
        case 'PUT':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                throw new Exception('Invalid JSON data');
            }
            
            $success = $alert_manager->saveAlertRules($data);
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Alert rules saved successfully']);
            } else {
                throw new Exception('Failed to save alert rules');
            }
            break;
        
        default:
            throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
