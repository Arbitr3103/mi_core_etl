<?php
/**
 * Automated Credential Rotation Script
 * 
 * Handles automatic rotation of API credentials based on expiry dates
 * and rotation policies. Can be run via cron job.
 */

require_once __DIR__ . '/CredentialManager.php';

class CredentialRotationService {
    private $credentialManager;
    private $config;
    
    public function __construct() {
        $this->credentialManager = new CredentialManager();
        $this->config = $this->loadRotationConfig();
    }
    
    /**
     * Load rotation configuration
     * @return array Configuration settings
     */
    private function loadRotationConfig() {
        return [
            'warning_days' => 7,        // Days before expiry to warn
            'auto_rotate_days' => 3,    // Days before expiry to auto-rotate
            'max_rotation_attempts' => 3,
            'notification_email' => getenv('EMAIL_ALERTS_TO') ?: 'admin@company.com',
            'slack_webhook' => getenv('SLACK_WEBHOOK_URL'),
            'rotation_policies' => [
                'ozon' => [
                    'auto_rotate' => false,  // Manual rotation required for Ozon
                    'notify_expiry' => true,
                    'expiry_warning_days' => 14
                ],
                'wildberries' => [
                    'auto_rotate' => false,  // Manual rotation required for WB
                    'notify_expiry' => true,
                    'expiry_warning_days' => 14
                ]
            ]
        ];
    }
    
    /**
     * Run the rotation check process
     */
    public function runRotationCheck() {
        logAnalyticsActivity('INFO', 'Starting credential rotation check');
        
        $results = [
            'checked' => 0,
            'warnings_sent' => 0,
            'rotations_attempted' => 0,
            'rotations_successful' => 0,
            'errors' => []
        ];
        
        try {
            // Check for expiring credentials
            $expiringCredentials = $this->credentialManager->getExpiringCredentials($this->config['warning_days']);
            $results['checked'] = count($expiringCredentials);
            
            foreach ($expiringCredentials as $credential) {
                $service = $credential['service_name'];
                $type = $credential['credential_type'];
                $daysLeft = $credential['days_until_expiry'];
                
                logAnalyticsActivity('INFO', 'Processing expiring credential', [
                    'service' => $service,
                    'type' => $type,
                    'days_left' => $daysLeft
                ]);
                
                // Get service-specific policy
                $policy = $this->config['rotation_policies'][$service] ?? [
                    'auto_rotate' => false,
                    'notify_expiry' => true,
                    'expiry_warning_days' => 7
                ];
                
                // Send warning notification if needed
                if ($policy['notify_expiry'] && $daysLeft <= ($policy['expiry_warning_days'] ?? 7)) {
                    $this->sendExpiryWarning($credential);
                    $results['warnings_sent']++;
                }
                
                // Attempt auto-rotation if enabled and close to expiry
                if ($policy['auto_rotate'] && $daysLeft <= $this->config['auto_rotate_days']) {
                    $results['rotations_attempted']++;
                    
                    if ($this->attemptAutoRotation($service, $type)) {
                        $results['rotations_successful']++;
                    } else {
                        $results['errors'][] = "Failed to auto-rotate {$service}.{$type}";
                    }
                }
            }
            
            // Clean up expired credentials
            $cleanedCount = $this->credentialManager->cleanupExpiredCredentials();
            if ($cleanedCount > 0) {
                logAnalyticsActivity('INFO', 'Cleaned up expired credentials', ['count' => $cleanedCount]);
            }
            
            // Send summary report if there were any actions
            if ($results['warnings_sent'] > 0 || $results['rotations_attempted'] > 0) {
                $this->sendRotationSummary($results);
            }
            
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            logAnalyticsActivity('ERROR', 'Credential rotation check failed: ' . $e->getMessage());
        }
        
        logAnalyticsActivity('INFO', 'Credential rotation check completed', $results);
        
        return $results;
    }
    
    /**
     * Attempt automatic rotation for a credential
     * @param string $service Service name
     * @param string $type Credential type
     * @return bool Success status
     */
    private function attemptAutoRotation($service, $type) {
        try {
            // For now, we don't implement actual auto-rotation as it requires
            // API calls to the marketplace to generate new credentials
            // Instead, we send urgent notifications
            
            logAnalyticsActivity('WARNING', 'Auto-rotation not implemented, sending urgent notification', [
                'service' => $service,
                'type' => $type
            ]);
            
            $this->sendUrgentRotationNotification($service, $type);
            
            return false; // Return false as we didn't actually rotate
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Auto-rotation attempt failed', [
                'service' => $service,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Send expiry warning notification
     * @param array $credential Credential information
     */
    private function sendExpiryWarning($credential) {
        $service = $credential['service_name'];
        $type = $credential['credential_type'];
        $daysLeft = $credential['days_until_expiry'];
        $expiresAt = $credential['expires_at'];
        
        $message = "⚠️ Credential Expiry Warning\n\n";
        $message .= "Service: {$service}\n";
        $message .= "Type: {$type}\n";
        $message .= "Expires: {$expiresAt}\n";
        $message .= "Days remaining: {$daysLeft}\n\n";
        $message .= "Please rotate this credential before it expires to avoid service disruption.";
        
        // Send Slack notification if configured
        if ($this->config['slack_webhook']) {
            $this->sendSlackNotification($message, '⚠️ Credential Expiry Warning');
        }
        
        // Send email notification
        $this->sendEmailNotification(
            'Credential Expiry Warning',
            $message,
            $this->config['notification_email']
        );
        
        logAnalyticsActivity('INFO', 'Sent expiry warning notification', [
            'service' => $service,
            'type' => $type,
            'days_left' => $daysLeft
        ]);
    }
    
    /**
     * Send urgent rotation notification
     * @param string $service Service name
     * @param string $type Credential type
     */
    private function sendUrgentRotationNotification($service, $type) {
        $message = "🚨 URGENT: Credential Rotation Required\n\n";
        $message .= "Service: {$service}\n";
        $message .= "Type: {$type}\n\n";
        $message .= "This credential is expiring very soon and requires immediate manual rotation.\n";
        $message .= "Auto-rotation is not available for this service.\n\n";
        $message .= "Please rotate immediately to prevent service disruption.";
        
        // Send Slack notification if configured
        if ($this->config['slack_webhook']) {
            $this->sendSlackNotification($message, '🚨 URGENT: Credential Rotation Required');
        }
        
        // Send email notification
        $this->sendEmailNotification(
            'URGENT: Credential Rotation Required',
            $message,
            $this->config['notification_email']
        );
        
        logAnalyticsActivity('WARNING', 'Sent urgent rotation notification', [
            'service' => $service,
            'type' => $type
        ]);
    }
    
    /**
     * Send rotation summary report
     * @param array $results Rotation check results
     */
    private function sendRotationSummary($results) {
        $message = "📊 Credential Rotation Summary\n\n";
        $message .= "Credentials checked: {$results['checked']}\n";
        $message .= "Warnings sent: {$results['warnings_sent']}\n";
        $message .= "Rotations attempted: {$results['rotations_attempted']}\n";
        $message .= "Rotations successful: {$results['rotations_successful']}\n";
        
        if (!empty($results['errors'])) {
            $message .= "\nErrors:\n";
            foreach ($results['errors'] as $error) {
                $message .= "- {$error}\n";
            }
        }
        
        $message .= "\nTime: " . date('Y-m-d H:i:s');
        
        // Send Slack notification if configured
        if ($this->config['slack_webhook']) {
            $this->sendSlackNotification($message, '📊 Credential Rotation Summary');
        }
        
        logAnalyticsActivity('INFO', 'Sent rotation summary report', $results);
    }
    
    /**
     * Send Slack notification
     * @param string $message Message text
     * @param string $title Message title
     */
    private function sendSlackNotification($message, $title) {
        if (!$this->config['slack_webhook']) {
            return;
        }
        
        try {
            $payload = [
                'text' => $title,
                'attachments' => [
                    [
                        'color' => 'warning',
                        'text' => $message,
                        'ts' => time()
                    ]
                ]
            ];
            
            $ch = curl_init($this->config['slack_webhook']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new Exception("Slack notification failed with HTTP code: {$httpCode}");
            }
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to send Slack notification: ' . $e->getMessage());
        }
    }
    
    /**
     * Send email notification
     * @param string $subject Email subject
     * @param string $message Email message
     * @param string $to Recipient email
     */
    private function sendEmailNotification($subject, $message, $to) {
        try {
            // Simple mail function - in production, use a proper mail library
            $headers = [
                'From: Regional Analytics System <noreply@company.com>',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: Regional Analytics Credential Manager'
            ];
            
            $success = mail($to, $subject, $message, implode("\r\n", $headers));
            
            if (!$success) {
                throw new Exception('Mail function returned false');
            }
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to send email notification: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate rotation report
     * @return array Detailed rotation status report
     */
    public function generateRotationReport() {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'services' => [],
            'summary' => [
                'total_credentials' => 0,
                'active_credentials' => 0,
                'expiring_soon' => 0,
                'expired' => 0
            ]
        ];
        
        // Get all services
        $services = ['ozon', 'wildberries'];
        
        foreach ($services as $service) {
            $credentials = $this->credentialManager->listServiceCredentials($service);
            
            $serviceReport = [
                'service_name' => $service,
                'credentials' => [],
                'total' => count($credentials),
                'active' => 0,
                'expiring' => 0,
                'expired' => 0
            ];
            
            foreach ($credentials as $cred) {
                $credReport = [
                    'type' => $cred['credential_type'],
                    'active' => $cred['is_active'],
                    'created' => $cred['created_at'],
                    'updated' => $cred['updated_at'],
                    'expires' => $cred['expires_at'],
                    'rotations' => $cred['rotation_count'],
                    'last_used' => $cred['last_used_at'],
                    'status' => 'active'
                ];
                
                if ($cred['is_active']) {
                    $serviceReport['active']++;
                    
                    if ($cred['expires_at']) {
                        $daysUntilExpiry = (strtotime($cred['expires_at']) - time()) / (24 * 60 * 60);
                        
                        if ($daysUntilExpiry <= 0) {
                            $credReport['status'] = 'expired';
                            $serviceReport['expired']++;
                        } elseif ($daysUntilExpiry <= 7) {
                            $credReport['status'] = 'expiring_soon';
                            $serviceReport['expiring']++;
                        }
                        
                        $credReport['days_until_expiry'] = round($daysUntilExpiry, 1);
                    }
                } else {
                    $credReport['status'] = 'inactive';
                }
                
                $serviceReport['credentials'][] = $credReport;
            }
            
            $report['services'][] = $serviceReport;
            
            // Update summary
            $report['summary']['total_credentials'] += $serviceReport['total'];
            $report['summary']['active_credentials'] += $serviceReport['active'];
            $report['summary']['expiring_soon'] += $serviceReport['expiring'];
            $report['summary']['expired'] += $serviceReport['expired'];
        }
        
        return $report;
    }
}

// If running from command line, execute rotation check
if (php_sapi_name() === 'cli') {
    $rotationService = new CredentialRotationService();
    
    // Check command line arguments
    $command = isset($argv[1]) ? $argv[1] : 'check';
    
    switch ($command) {
        case 'check':
            echo "🔄 Running credential rotation check...\n";
            $results = $rotationService->runRotationCheck();
            
            echo "✅ Rotation check completed:\n";
            echo "   Credentials checked: {$results['checked']}\n";
            echo "   Warnings sent: {$results['warnings_sent']}\n";
            echo "   Rotations attempted: {$results['rotations_attempted']}\n";
            echo "   Rotations successful: {$results['rotations_successful']}\n";
            
            if (!empty($results['errors'])) {
                echo "❌ Errors:\n";
                foreach ($results['errors'] as $error) {
                    echo "   - {$error}\n";
                }
            }
            break;
            
        case 'report':
            echo "📊 Generating credential rotation report...\n";
            $report = $rotationService->generateRotationReport();
            
            echo "📋 Credential Status Report - {$report['timestamp']}\n";
            echo str_repeat('=', 60) . "\n";
            
            foreach ($report['services'] as $service) {
                echo "\n🔧 {$service['service_name']} ({$service['total']} credentials)\n";
                echo "   Active: {$service['active']}\n";
                echo "   Expiring Soon: {$service['expiring']}\n";
                echo "   Expired: {$service['expired']}\n";
                
                foreach ($service['credentials'] as $cred) {
                    $status = match($cred['status']) {
                        'active' => '✅',
                        'expiring_soon' => '⚠️',
                        'expired' => '❌',
                        'inactive' => '⏸️',
                        default => '❓'
                    };
                    
                    echo "   {$status} {$cred['type']} - {$cred['status']}";
                    if (isset($cred['days_until_expiry'])) {
                        echo " ({$cred['days_until_expiry']} days)";
                    }
                    echo "\n";
                }
            }
            
            echo "\n📊 Summary:\n";
            echo "   Total: {$report['summary']['total_credentials']}\n";
            echo "   Active: {$report['summary']['active_credentials']}\n";
            echo "   Expiring Soon: {$report['summary']['expiring_soon']}\n";
            echo "   Expired: {$report['summary']['expired']}\n";
            break;
            
        default:
            echo "Usage: php credential_rotation.php [check|report]\n";
            echo "  check  - Run rotation check and send notifications\n";
            echo "  report - Generate detailed status report\n";
            break;
    }
}

?>