<?php
/**
 * StockAlertManager Class - Stock alert and notification system
 * 
 * Manages critical stock monitoring, alert generation, and notification delivery
 * for warehouse stock levels based on configurable thresholds and sales velocity
 * 
 * @version 1.0
 * @author Manhattan System
 */

class StockAlertManager {
    
    private $pdo;
    private $logger;
    private $defaultThresholds;
    
    public function __construct(PDO $pdo, $logger = null) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        
        // Default thresholds if not configured in database
        $this->defaultThresholds = [
            'critical_stockout_threshold' => 3,
            'high_priority_threshold' => 7,
            'slow_moving_threshold_days' => 30,
            'overstocked_threshold_days' => 90,
            'min_sales_history_days' => 14
        ];
    }
    
    /**
     * Analyze stock levels for critical conditions
     * 
     * @param array $filters - analysis filters (warehouse, source, product_id)
     * @return array critical stock data with analysis results
     */
    public function analyzeStockLevels(array $filters = []): array {
        try {
            $this->log('INFO', 'Starting stock level analysis', $filters);
            
            // Get configuration thresholds
            $thresholds = $this->getAlertThresholds();
            
            // Build query with filters
            $whereConditions = ['i.quantity_present >= 0'];
            $params = [];
            
            if (!empty($filters['warehouse'])) {
                $whereConditions[] = 'i.warehouse_name = :warehouse';
                $params['warehouse'] = $filters['warehouse'];
            }
            
            if (!empty($filters['source'])) {
                $whereConditions[] = 'i.source = :source';
                $params['source'] = $filters['source'];
            }
            
            if (!empty($filters['product_id'])) {
                $whereConditions[] = 'i.product_id = :product_id';
                $params['product_id'] = $filters['product_id'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // Main analysis query with sales velocity
            $sql = "
                SELECT 
                    i.id,
                    i.product_id,
                    dp.sku_ozon,
                    dp.sku_wb,
                    dp.product_name,
                    i.warehouse_name,
                    i.stock_type,
                    i.quantity_present,
                    i.quantity_reserved,
                    i.source,
                    i.updated_at,
                    dp.cost_price,
                    
                    -- Sales velocity calculation (last 30 days)
                    COALESCE(sales.total_sold_30d, 0) as total_sold_30d,
                    COALESCE(sales.avg_daily_sales, 0) as avg_daily_sales,
                    COALESCE(sales.active_sales_days, 0) as active_sales_days,
                    
                    -- Days until stockout calculation
                    CASE 
                        WHEN COALESCE(sales.avg_daily_sales, 0) > 0 
                        THEN ROUND(i.quantity_present / sales.avg_daily_sales, 1)
                        ELSE NULL 
                    END as days_until_stockout,
                    
                    -- Alert level determination
                    CASE 
                        WHEN i.quantity_present = 0 THEN 'CRITICAL'
                        WHEN COALESCE(sales.avg_daily_sales, 0) > 0 AND 
                             (i.quantity_present / sales.avg_daily_sales) <= :critical_threshold THEN 'CRITICAL'
                        WHEN COALESCE(sales.avg_daily_sales, 0) > 0 AND 
                             (i.quantity_present / sales.avg_daily_sales) <= :high_threshold THEN 'HIGH'
                        WHEN COALESCE(sales.total_sold_30d, 0) = 0 AND 
                             i.updated_at < DATE_SUB(NOW(), INTERVAL :slow_moving_days DAY) THEN 'MEDIUM'
                        WHEN COALESCE(sales.avg_daily_sales, 0) > 0 AND 
                             (i.quantity_present / sales.avg_daily_sales) > :overstocked_days THEN 'LOW'
                        ELSE 'NORMAL'
                    END as alert_level
                    
                FROM inventory i
                JOIN dim_products dp ON i.product_id = dp.id
                LEFT JOIN (
                    SELECT 
                        sm.product_id,
                        SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_sold_30d,
                        AVG(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as avg_daily_sales,
                        COUNT(DISTINCT DATE(sm.movement_date)) as active_sales_days
                    FROM stock_movements sm
                    WHERE sm.movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND sm.movement_type IN ('sale', 'order')
                    GROUP BY sm.product_id
                ) sales ON i.product_id = sales.product_id
                WHERE {$whereClause}
                ORDER BY 
                    CASE alert_level
                        WHEN 'CRITICAL' THEN 1
                        WHEN 'HIGH' THEN 2
                        WHEN 'MEDIUM' THEN 3
                        WHEN 'LOW' THEN 4
                        ELSE 5
                    END,
                    days_until_stockout ASC NULLS LAST,
                    i.quantity_present ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            // Bind threshold parameters
            $stmt->bindValue(':critical_threshold', $thresholds['critical_stockout_threshold'], PDO::PARAM_INT);
            $stmt->bindValue(':high_threshold', $thresholds['high_priority_threshold'], PDO::PARAM_INT);
            $stmt->bindValue(':slow_moving_days', $thresholds['slow_moving_threshold_days'], PDO::PARAM_INT);
            $stmt->bindValue(':overstocked_days', $thresholds['overstocked_threshold_days'], PDO::PARAM_INT);
            
            // Bind filter parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group results by alert level for summary
            $summary = [
                'total_analyzed' => count($results),
                'by_alert_level' => [],
                'by_warehouse' => [],
                'critical_items' => []
            ];
            
            foreach ($results as $item) {
                // Count by alert level
                $level = $item['alert_level'];
                if (!isset($summary['by_alert_level'][$level])) {
                    $summary['by_alert_level'][$level] = 0;
                }
                $summary['by_alert_level'][$level]++;
                
                // Count by warehouse
                $warehouse = $item['warehouse_name'];
                if (!isset($summary['by_warehouse'][$warehouse])) {
                    $summary['by_warehouse'][$warehouse] = ['total' => 0, 'critical' => 0, 'high' => 0];
                }
                $summary['by_warehouse'][$warehouse]['total']++;
                if ($level === 'CRITICAL') {
                    $summary['by_warehouse'][$warehouse]['critical']++;
                    $summary['critical_items'][] = $item;
                } elseif ($level === 'HIGH') {
                    $summary['by_warehouse'][$warehouse]['high']++;
                }
            }
            
            $this->log('INFO', 'Stock level analysis completed', [
                'total_analyzed' => $summary['total_analyzed'],
                'critical_count' => $summary['by_alert_level']['CRITICAL'] ?? 0,
                'high_count' => $summary['by_alert_level']['HIGH'] ?? 0
            ]);
            
            return [
                'analysis_timestamp' => date('Y-m-d H:i:s'),
                'thresholds_used' => $thresholds,
                'filters_applied' => $filters,
                'summary' => $summary,
                'detailed_results' => $results
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Stock level analysis failed', ['error' => $e->getMessage()]);
            throw new Exception("Stock level analysis failed: " . $e->getMessage());
        }
    }
    
    /**
     * Generate critical stock alerts with configurable thresholds
     * 
     * @param array $options - alert generation options
     * @return array generated alerts
     */
    public function generateCriticalStockAlerts(array $options = []): array {
        try {
            $this->log('INFO', 'Starting critical stock alert generation', $options);
            
            // Get current stock analysis
            $analysis = $this->analyzeStockLevels($options['filters'] ?? []);
            
            $alerts = [];
            $alertsToSave = [];
            
            foreach ($analysis['detailed_results'] as $item) {
                if (in_array($item['alert_level'], ['CRITICAL', 'HIGH'])) {
                    
                    // Determine alert type and message
                    $alertData = $this->determineAlertTypeAndMessage($item);
                    
                    // Check if similar alert already exists and is recent
                    if (!$this->isDuplicateAlert($item['product_id'], $alertData['alert_type'], $item['warehouse_name'])) {
                        
                        $alert = [
                            'product_id' => $item['product_id'],
                            'sku' => $item['sku_ozon'] ?: $item['sku_wb'],
                            'product_name' => $item['product_name'],
                            'warehouse_name' => $item['warehouse_name'],
                            'source' => $item['source'],
                            'alert_type' => $alertData['alert_type'],
                            'alert_level' => $item['alert_level'],
                            'message' => $alertData['message'],
                            'current_stock' => $item['quantity_present'],
                            'reserved_stock' => $item['quantity_reserved'],
                            'days_until_stockout' => $item['days_until_stockout'],
                            'avg_daily_sales' => $item['avg_daily_sales'],
                            'recommended_action' => $alertData['recommended_action'],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $alerts[] = $alert;
                        $alertsToSave[] = $alert;
                    }
                }
            }
            
            // Save alerts to database
            if (!empty($alertsToSave)) {
                $this->saveAlertsToDatabase($alertsToSave);
            }
            
            // Group alerts by warehouse for efficient processing
            $groupedAlerts = $this->groupAlertsByWarehouse($alerts);
            
            $this->log('INFO', 'Critical stock alert generation completed', [
                'total_alerts' => count($alerts),
                'warehouses_affected' => count($groupedAlerts)
            ]);
            
            return [
                'generation_timestamp' => date('Y-m-d H:i:s'),
                'total_alerts' => count($alerts),
                'alerts' => $alerts,
                'grouped_by_warehouse' => $groupedAlerts,
                'analysis_summary' => $analysis['summary']
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Critical stock alert generation failed', ['error' => $e->getMessage()]);
            throw new Exception("Critical stock alert generation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get alert thresholds from database configuration
     * 
     * @return array threshold values
     */
    private function getAlertThresholds(): array {
        try {
            $sql = "
                SELECT setting_key, setting_value, setting_type 
                FROM replenishment_settings 
                WHERE category = 'ANALYSIS' 
                AND is_active = 1
                AND setting_key IN ('critical_stockout_threshold', 'high_priority_threshold', 
                                   'slow_moving_threshold_days', 'overstocked_threshold_days', 
                                   'min_sales_history_days')
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $thresholds = $this->defaultThresholds;
            
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                if ($setting['setting_type'] === 'INTEGER') {
                    $value = (int)$value;
                } elseif ($setting['setting_type'] === 'DECIMAL') {
                    $value = (float)$value;
                }
                $thresholds[$setting['setting_key']] = $value;
            }
            
            return $thresholds;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to load thresholds from database, using defaults', ['error' => $e->getMessage()]);
            return $this->defaultThresholds;
        }
    }
    
    /**
     * Determine alert type and generate appropriate message
     * 
     * @param array $item - stock item data
     * @return array alert type and message data
     */
    private function determineAlertTypeAndMessage(array $item): array {
        if ($item['quantity_present'] == 0) {
            return [
                'alert_type' => 'STOCKOUT_CRITICAL',
                'message' => "КРИТИЧЕСКИЙ: Товар '{$item['product_name']}' полностью закончился на складе '{$item['warehouse_name']}'",
                'recommended_action' => 'Срочно пополнить запасы или снять товар с продажи'
            ];
        }
        
        if ($item['alert_level'] === 'CRITICAL' && $item['days_until_stockout'] !== null) {
            $days = round($item['days_until_stockout'], 1);
            return [
                'alert_type' => 'STOCKOUT_CRITICAL',
                'message' => "КРИТИЧЕСКИЙ: Товар '{$item['product_name']}' на складе '{$item['warehouse_name']}' закончится через {$days} дней (остаток: {$item['quantity_present']} шт.)",
                'recommended_action' => "Срочно заказать пополнение. Средние продажи: {$item['avg_daily_sales']} шт/день"
            ];
        }
        
        if ($item['alert_level'] === 'HIGH' && $item['days_until_stockout'] !== null) {
            $days = round($item['days_until_stockout'], 1);
            return [
                'alert_type' => 'STOCKOUT_WARNING',
                'message' => "ВНИМАНИЕ: Товар '{$item['product_name']}' на складе '{$item['warehouse_name']}' закончится через {$days} дней (остаток: {$item['quantity_present']} шт.)",
                'recommended_action' => "Запланировать пополнение запасов. Средние продажи: {$item['avg_daily_sales']} шт/день"
            ];
        }
        
        if ($item['total_sold_30d'] == 0) {
            return [
                'alert_type' => 'NO_SALES',
                'message' => "Товар '{$item['product_name']}' на складе '{$item['warehouse_name']}' не продавался последние 30 дней (остаток: {$item['quantity_present']} шт.)",
                'recommended_action' => 'Проверить актуальность товара и рассмотреть маркетинговые активности'
            ];
        }
        
        return [
            'alert_type' => 'STOCKOUT_WARNING',
            'message' => "Товар '{$item['product_name']}' на складе '{$item['warehouse_name']}' требует внимания (остаток: {$item['quantity_present']} шт.)",
            'recommended_action' => 'Проанализировать ситуацию и принять соответствующие меры'
        ];
    }
    
    /**
     * Check if similar alert already exists recently
     * 
     * @param int $productId
     * @param string $alertType
     * @param string $warehouseName
     * @return bool true if duplicate exists
     */
    private function isDuplicateAlert(int $productId, string $alertType, string $warehouseName): bool {
        try {
            $sql = "
                SELECT COUNT(*) as count
                FROM replenishment_alerts 
                WHERE product_id = :product_id 
                AND alert_type = :alert_type
                AND message LIKE :warehouse_pattern
                AND status IN ('NEW', 'ACKNOWLEDGED')
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':alert_type', $alertType);
            $stmt->bindValue(':warehouse_pattern', "%{$warehouseName}%");
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to check for duplicate alerts', ['error' => $e->getMessage()]);
            return false; // If check fails, allow alert to be created
        }
    }
    
    /**
     * Save alerts to database
     * 
     * @param array $alerts
     * @return bool success status
     */
    private function saveAlertsToDatabase(array $alerts): bool {
        try {
            $sql = "
                INSERT INTO replenishment_alerts 
                (product_id, sku, product_name, alert_type, alert_level, message, 
                 current_stock, days_until_stockout, recommended_action, status, created_at)
                VALUES 
                (:product_id, :sku, :product_name, :alert_type, :alert_level, :message,
                 :current_stock, :days_until_stockout, :recommended_action, 'NEW', :created_at)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($alerts as $alert) {
                $stmt->execute([
                    ':product_id' => $alert['product_id'],
                    ':sku' => $alert['sku'],
                    ':product_name' => $alert['product_name'],
                    ':alert_type' => $alert['alert_type'],
                    ':alert_level' => $alert['alert_level'],
                    ':message' => $alert['message'],
                    ':current_stock' => $alert['current_stock'],
                    ':days_until_stockout' => $alert['days_until_stockout'],
                    ':recommended_action' => $alert['recommended_action'],
                    ':created_at' => $alert['created_at']
                ]);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to save alerts to database', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Group alerts by warehouse for efficient processing
     * 
     * @param array $alerts
     * @return array grouped alerts
     */
    private function groupAlertsByWarehouse(array $alerts): array {
        $grouped = [];
        
        foreach ($alerts as $alert) {
            $warehouse = $alert['warehouse_name'];
            if (!isset($grouped[$warehouse])) {
                $grouped[$warehouse] = [
                    'warehouse_name' => $warehouse,
                    'total_alerts' => 0,
                    'critical_count' => 0,
                    'high_count' => 0,
                    'alerts' => []
                ];
            }
            
            $grouped[$warehouse]['total_alerts']++;
            $grouped[$warehouse]['alerts'][] = $alert;
            
            if ($alert['alert_level'] === 'CRITICAL') {
                $grouped[$warehouse]['critical_count']++;
            } elseif ($alert['alert_level'] === 'HIGH') {
                $grouped[$warehouse]['high_count']++;
            }
        }
        
        // Sort warehouses by urgency (critical alerts first)
        uasort($grouped, function($a, $b) {
            if ($a['critical_count'] !== $b['critical_count']) {
                return $b['critical_count'] - $a['critical_count'];
            }
            return $b['high_count'] - $a['high_count'];
        });
        
        return $grouped;
    }
    
    /**
     * Send stock alert notifications for email/SMS alerts
     * 
     * @param array $alerts - alerts to send (can be raw alerts or grouped alerts)
     * @return bool notification result
     */
    public function sendStockAlertNotifications(array $alerts): bool {
        try {
            $this->log('INFO', 'Starting stock alert notifications', ['alert_count' => count($alerts)]);
            
            // If alerts are not grouped, group them by warehouse
            $groupedAlerts = $this->isGroupedAlerts($alerts) ? $alerts : $this->groupAlertsByWarehouse($alerts);
            
            $notificationResults = [];
            $totalSent = 0;
            $totalFailed = 0;
            
            // Get notification settings
            $notificationSettings = $this->getNotificationSettings();
            
            foreach ($groupedAlerts as $warehouseGroup) {
                try {
                    // Create warehouse-specific notification
                    $notification = $this->createWarehouseNotification($warehouseGroup, $notificationSettings);
                    
                    // Send email notification if enabled
                    if ($notificationSettings['email_enabled']) {
                        $emailResult = $this->sendEmailNotification($notification);
                        $notificationResults[] = [
                            'warehouse' => $warehouseGroup['warehouse_name'],
                            'type' => 'email',
                            'success' => $emailResult['success'],
                            'message' => $emailResult['message']
                        ];
                        
                        if ($emailResult['success']) {
                            $totalSent++;
                        } else {
                            $totalFailed++;
                        }
                    }
                    
                    // Send SMS notification if enabled and critical alerts exist
                    if ($notificationSettings['sms_enabled'] && $warehouseGroup['critical_count'] > 0) {
                        $smsResult = $this->sendSMSNotification($notification);
                        $notificationResults[] = [
                            'warehouse' => $warehouseGroup['warehouse_name'],
                            'type' => 'sms',
                            'success' => $smsResult['success'],
                            'message' => $smsResult['message']
                        ];
                        
                        if ($smsResult['success']) {
                            $totalSent++;
                        } else {
                            $totalFailed++;
                        }
                    }
                    
                    // Log notification to database
                    $this->logNotificationToDatabase($warehouseGroup, $notificationResults);
                    
                } catch (Exception $e) {
                    $this->log('ERROR', 'Failed to send notification for warehouse', [
                        'warehouse' => $warehouseGroup['warehouse_name'],
                        'error' => $e->getMessage()
                    ]);
                    $totalFailed++;
                }
            }
            
            $success = $totalFailed === 0;
            
            $this->log('INFO', 'Stock alert notifications completed', [
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'success' => $success
            ]);
            
            return $success;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Stock alert notification system failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Get stock alert history for alert audit trail
     * 
     * @param int $days - number of days to retrieve
     * @param array $filters - optional filters (warehouse, alert_type, status, product_id)
     * @return array alert history with metrics
     */
    public function getStockAlertHistory(int $days = 7, array $filters = []): array {
        try {
            $this->log('INFO', 'Retrieving stock alert history', ['days' => $days, 'filters' => $filters]);
            
            // Build query with filters
            $whereConditions = ['ra.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)'];
            $params = ['days' => $days];
            
            if (!empty($filters['warehouse'])) {
                $whereConditions[] = 'ra.message LIKE :warehouse_pattern';
                $params['warehouse_pattern'] = "%{$filters['warehouse']}%";
            }
            
            if (!empty($filters['alert_type'])) {
                $whereConditions[] = 'ra.alert_type = :alert_type';
                $params['alert_type'] = $filters['alert_type'];
            }
            
            if (!empty($filters['status'])) {
                $whereConditions[] = 'ra.status = :status';
                $params['status'] = $filters['status'];
            }
            
            if (!empty($filters['product_id'])) {
                $whereConditions[] = 'ra.product_id = :product_id';
                $params['product_id'] = $filters['product_id'];
            }
            
            if (!empty($filters['alert_level'])) {
                $whereConditions[] = 'ra.alert_level = :alert_level';
                $params['alert_level'] = $filters['alert_level'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // Main history query
            $sql = "
                SELECT 
                    ra.id,
                    ra.product_id,
                    ra.sku,
                    ra.product_name,
                    ra.alert_type,
                    ra.alert_level,
                    ra.message,
                    ra.current_stock,
                    ra.days_until_stockout,
                    ra.recommended_action,
                    ra.status,
                    ra.acknowledged_by,
                    ra.acknowledged_at,
                    ra.created_at,
                    ra.updated_at,
                    
                    -- Calculate response time for acknowledged alerts
                    CASE 
                        WHEN ra.acknowledged_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(MINUTE, ra.created_at, ra.acknowledged_at)
                        ELSE NULL 
                    END as response_time_minutes,
                    
                    -- Extract warehouse name from message
                    CASE 
                        WHEN ra.message LIKE '%складе \\'%\\' %' 
                        THEN SUBSTRING_INDEX(SUBSTRING_INDEX(ra.message, 'складе \\'', -1), '\\'', 1)
                        ELSE 'Unknown'
                    END as warehouse_name
                    
                FROM replenishment_alerts ra
                WHERE {$whereClause}
                ORDER BY ra.created_at DESC, ra.alert_level ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->execute();
            
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate metrics and statistics
            $metrics = $this->calculateAlertMetrics($alerts, $days);
            
            // Group alerts for better analysis
            $groupedData = $this->groupAlertHistoryData($alerts);
            
            $this->log('INFO', 'Stock alert history retrieved successfully', [
                'total_alerts' => count($alerts),
                'days_analyzed' => $days
            ]);
            
            return [
                'query_period' => [
                    'days' => $days,
                    'start_date' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
                    'end_date' => date('Y-m-d H:i:s')
                ],
                'filters_applied' => $filters,
                'total_alerts' => count($alerts),
                'alerts' => $alerts,
                'metrics' => $metrics,
                'grouped_data' => $groupedData
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to retrieve stock alert history', ['error' => $e->getMessage()]);
            throw new Exception("Failed to retrieve stock alert history: " . $e->getMessage());
        }
    }
    
    /**
     * Acknowledge alert and track resolution
     * 
     * @param int $alertId - alert ID to acknowledge
     * @param string $acknowledgedBy - user who acknowledged the alert
     * @param string $notes - optional notes about the acknowledgment
     * @return bool success status
     */
    public function acknowledgeAlert(int $alertId, string $acknowledgedBy, string $notes = ''): bool {
        try {
            $this->log('INFO', 'Acknowledging alert', [
                'alert_id' => $alertId,
                'acknowledged_by' => $acknowledgedBy
            ]);
            
            $sql = "
                UPDATE replenishment_alerts 
                SET status = 'ACKNOWLEDGED',
                    acknowledged_by = :acknowledged_by,
                    acknowledged_at = NOW(),
                    updated_at = NOW()
                WHERE id = :alert_id
                AND status = 'NEW'
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':alert_id' => $alertId,
                ':acknowledged_by' => $acknowledgedBy
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            if ($rowsAffected > 0) {
                // Log acknowledgment if notes provided
                if (!empty($notes)) {
                    $this->logAlertAction($alertId, 'ACKNOWLEDGED', $acknowledgedBy, $notes);
                }
                
                $this->log('INFO', 'Alert acknowledged successfully', [
                    'alert_id' => $alertId,
                    'acknowledged_by' => $acknowledgedBy
                ]);
                
                return true;
            } else {
                $this->log('WARNING', 'Alert not found or already acknowledged', ['alert_id' => $alertId]);
                return false;
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to acknowledge alert', [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Resolve alert and track resolution
     * 
     * @param int $alertId - alert ID to resolve
     * @param string $resolvedBy - user who resolved the alert
     * @param string $resolutionNotes - notes about how the alert was resolved
     * @return bool success status
     */
    public function resolveAlert(int $alertId, string $resolvedBy, string $resolutionNotes = ''): bool {
        try {
            $this->log('INFO', 'Resolving alert', [
                'alert_id' => $alertId,
                'resolved_by' => $resolvedBy
            ]);
            
            $sql = "
                UPDATE replenishment_alerts 
                SET status = 'RESOLVED',
                    acknowledged_by = COALESCE(acknowledged_by, :resolved_by),
                    acknowledged_at = COALESCE(acknowledged_at, NOW()),
                    updated_at = NOW()
                WHERE id = :alert_id
                AND status IN ('NEW', 'ACKNOWLEDGED')
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':alert_id' => $alertId,
                ':resolved_by' => $resolvedBy
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            if ($rowsAffected > 0) {
                // Log resolution
                $this->logAlertAction($alertId, 'RESOLVED', $resolvedBy, $resolutionNotes);
                
                $this->log('INFO', 'Alert resolved successfully', [
                    'alert_id' => $alertId,
                    'resolved_by' => $resolvedBy
                ]);
                
                return true;
            } else {
                $this->log('WARNING', 'Alert not found or already resolved', ['alert_id' => $alertId]);
                return false;
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to resolve alert', [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get alert response metrics and effectiveness
     * 
     * @param int $days - analysis period in days
     * @return array metrics data
     */
    public function getAlertResponseMetrics(int $days = 30): array {
        try {
            $this->log('INFO', 'Calculating alert response metrics', ['days' => $days]);
            
            $sql = "
                SELECT 
                    COUNT(*) as total_alerts,
                    SUM(CASE WHEN status = 'NEW' THEN 1 ELSE 0 END) as new_alerts,
                    SUM(CASE WHEN status = 'ACKNOWLEDGED' THEN 1 ELSE 0 END) as acknowledged_alerts,
                    SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_alerts,
                    SUM(CASE WHEN status = 'IGNORED' THEN 1 ELSE 0 END) as ignored_alerts,
                    
                    -- Response time metrics (in minutes)
                    AVG(CASE 
                        WHEN acknowledged_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at)
                        ELSE NULL 
                    END) as avg_response_time_minutes,
                    
                    MIN(CASE 
                        WHEN acknowledged_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at)
                        ELSE NULL 
                    END) as min_response_time_minutes,
                    
                    MAX(CASE 
                        WHEN acknowledged_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at)
                        ELSE NULL 
                    END) as max_response_time_minutes,
                    
                    -- Alert level breakdown
                    SUM(CASE WHEN alert_level = 'CRITICAL' THEN 1 ELSE 0 END) as critical_alerts,
                    SUM(CASE WHEN alert_level = 'HIGH' THEN 1 ELSE 0 END) as high_alerts,
                    SUM(CASE WHEN alert_level = 'MEDIUM' THEN 1 ELSE 0 END) as medium_alerts,
                    SUM(CASE WHEN alert_level = 'LOW' THEN 1 ELSE 0 END) as low_alerts,
                    
                    -- Alert type breakdown
                    SUM(CASE WHEN alert_type = 'STOCKOUT_CRITICAL' THEN 1 ELSE 0 END) as stockout_critical,
                    SUM(CASE WHEN alert_type = 'STOCKOUT_WARNING' THEN 1 ELSE 0 END) as stockout_warning,
                    SUM(CASE WHEN alert_type = 'NO_SALES' THEN 1 ELSE 0 END) as no_sales_alerts,
                    SUM(CASE WHEN alert_type = 'SLOW_MOVING' THEN 1 ELSE 0 END) as slow_moving_alerts
                    
                FROM replenishment_alerts
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate percentages and additional metrics
            $totalAlerts = $metrics['total_alerts'];
            $processedMetrics = [
                'period_days' => $days,
                'total_alerts' => $totalAlerts,
                'status_breakdown' => [
                    'new' => $metrics['new_alerts'],
                    'acknowledged' => $metrics['acknowledged_alerts'],
                    'resolved' => $metrics['resolved_alerts'],
                    'ignored' => $metrics['ignored_alerts']
                ],
                'status_percentages' => [
                    'new' => $totalAlerts > 0 ? round(($metrics['new_alerts'] / $totalAlerts) * 100, 1) : 0,
                    'acknowledged' => $totalAlerts > 0 ? round(($metrics['acknowledged_alerts'] / $totalAlerts) * 100, 1) : 0,
                    'resolved' => $totalAlerts > 0 ? round(($metrics['resolved_alerts'] / $totalAlerts) * 100, 1) : 0,
                    'ignored' => $totalAlerts > 0 ? round(($metrics['ignored_alerts'] / $totalAlerts) * 100, 1) : 0
                ],
                'response_time_metrics' => [
                    'average_minutes' => $metrics['avg_response_time_minutes'] ? round($metrics['avg_response_time_minutes'], 1) : null,
                    'average_hours' => $metrics['avg_response_time_minutes'] ? round($metrics['avg_response_time_minutes'] / 60, 1) : null,
                    'min_minutes' => $metrics['min_response_time_minutes'],
                    'max_minutes' => $metrics['max_response_time_minutes'],
                    'max_hours' => $metrics['max_response_time_minutes'] ? round($metrics['max_response_time_minutes'] / 60, 1) : null
                ],
                'alert_level_breakdown' => [
                    'critical' => $metrics['critical_alerts'],
                    'high' => $metrics['high_alerts'],
                    'medium' => $metrics['medium_alerts'],
                    'low' => $metrics['low_alerts']
                ],
                'alert_type_breakdown' => [
                    'stockout_critical' => $metrics['stockout_critical'],
                    'stockout_warning' => $metrics['stockout_warning'],
                    'no_sales' => $metrics['no_sales_alerts'],
                    'slow_moving' => $metrics['slow_moving_alerts']
                ]
            ];
            
            // Calculate effectiveness score
            $acknowledgedOrResolved = $metrics['acknowledged_alerts'] + $metrics['resolved_alerts'];
            $processedMetrics['effectiveness_score'] = $totalAlerts > 0 ? 
                round(($acknowledgedOrResolved / $totalAlerts) * 100, 1) : 0;
            
            $this->log('INFO', 'Alert response metrics calculated', [
                'total_alerts' => $totalAlerts,
                'effectiveness_score' => $processedMetrics['effectiveness_score']
            ]);
            
            return $processedMetrics;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to calculate alert response metrics', ['error' => $e->getMessage()]);
            throw new Exception("Failed to calculate alert response metrics: " . $e->getMessage());
        }
    }
    
    /**
     * Check if alerts array is already grouped by warehouse
     * 
     * @param array $alerts
     * @return bool
     */
    private function isGroupedAlerts(array $alerts): bool {
        if (empty($alerts)) {
            return false;
        }
        
        $firstItem = reset($alerts);
        return isset($firstItem['warehouse_name']) && isset($firstItem['alerts']) && is_array($firstItem['alerts']);
    }
    
    /**
     * Get notification settings from database
     * 
     * @return array notification configuration
     */
    private function getNotificationSettings(): array {
        try {
            $sql = "
                SELECT setting_key, setting_value, setting_type 
                FROM replenishment_settings 
                WHERE category = 'NOTIFICATIONS' 
                AND is_active = 1
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $config = [
                'email_enabled' => true,
                'sms_enabled' => false,
                'email_recipients' => ['manager@company.com'],
                'sms_recipients' => [],
                'email_template' => 'warehouse_stock_alert',
                'sms_template' => 'critical_stock_alert'
            ];
            
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                
                switch ($setting['setting_type']) {
                    case 'BOOLEAN':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'JSON':
                        $value = json_decode($value, true) ?: [];
                        break;
                    case 'INTEGER':
                        $value = (int)$value;
                        break;
                }
                
                $config[$setting['setting_key']] = $value;
            }
            
            return $config;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to load notification settings, using defaults', ['error' => $e->getMessage()]);
            return [
                'email_enabled' => true,
                'sms_enabled' => false,
                'email_recipients' => ['manager@company.com'],
                'sms_recipients' => [],
                'email_template' => 'warehouse_stock_alert',
                'sms_template' => 'critical_stock_alert'
            ];
        }
    }
    
    /**
     * Create warehouse-specific notification with alert templates
     * 
     * @param array $warehouseGroup
     * @param array $settings
     * @return array notification data
     */
    private function createWarehouseNotification(array $warehouseGroup, array $settings): array {
        $warehouse = $warehouseGroup['warehouse_name'];
        $criticalCount = $warehouseGroup['critical_count'];
        $highCount = $warehouseGroup['high_count'];
        $totalCount = $warehouseGroup['total_alerts'];
        
        // Create email content
        $emailSubject = "Критические остатки на складе: {$warehouse}";
        if ($criticalCount > 0) {
            $emailSubject = "🚨 КРИТИЧНО: {$criticalCount} товаров заканчиваются на складе {$warehouse}";
        } elseif ($highCount > 0) {
            $emailSubject = "⚠️ ВНИМАНИЕ: {$highCount} товаров требуют пополнения на складе {$warehouse}";
        }
        
        $emailBody = $this->generateEmailTemplate($warehouseGroup);
        
        // Create SMS content (only for critical alerts)
        $smsText = '';
        if ($criticalCount > 0) {
            $smsText = "КРИТИЧНО: {$criticalCount} товаров заканчиваются на складе {$warehouse}. Требуется срочное пополнение.";
        }
        
        return [
            'warehouse_name' => $warehouse,
            'alert_summary' => [
                'critical' => $criticalCount,
                'high' => $highCount,
                'total' => $totalCount
            ],
            'email' => [
                'subject' => $emailSubject,
                'body' => $emailBody,
                'recipients' => $settings['email_recipients']
            ],
            'sms' => [
                'text' => $smsText,
                'recipients' => $settings['sms_recipients']
            ],
            'alerts' => $warehouseGroup['alerts']
        ];
    }
    
    /**
     * Generate email template with warehouse-specific information
     * 
     * @param array $warehouseGroup
     * @return string email HTML content
     */
    private function generateEmailTemplate(array $warehouseGroup): string {
        $warehouse = $warehouseGroup['warehouse_name'];
        $criticalCount = $warehouseGroup['critical_count'];
        $highCount = $warehouseGroup['high_count'];
        $alerts = $warehouseGroup['alerts'];
        
        $html = "
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .critical { background-color: #dc3545; color: white; }
                .high { background-color: #fd7e14; color: white; }
                .alert-item { margin: 10px 0; padding: 10px; border-left: 4px solid #ccc; }
                .alert-critical { border-left-color: #dc3545; background-color: #f8d7da; }
                .alert-high { border-left-color: #fd7e14; background-color: #fff3cd; }
                .summary-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .summary-table th, .summary-table td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                .summary-table th { background-color: #f8f9fa; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>Отчет по критическим остаткам</h2>
                <p><strong>Склад:</strong> {$warehouse}</p>
                <p><strong>Дата формирования:</strong> " . date('d.m.Y H:i:s') . "</p>
            </div>
            
            <div class='summary'>
                <h3>Сводка по алертам</h3>
                <table class='summary-table'>
                    <tr>
                        <th>Уровень</th>
                        <th>Количество товаров</th>
                        <th>Описание</th>
                    </tr>";
        
        if ($criticalCount > 0) {
            $html .= "
                    <tr>
                        <td style='color: #dc3545; font-weight: bold;'>КРИТИЧЕСКИЙ</td>
                        <td>{$criticalCount}</td>
                        <td>Товары заканчиваются в ближайшие дни или уже закончились</td>
                    </tr>";
        }
        
        if ($highCount > 0) {
            $html .= "
                    <tr>
                        <td style='color: #fd7e14; font-weight: bold;'>ВЫСОКИЙ</td>
                        <td>{$highCount}</td>
                        <td>Товары требуют планирования пополнения</td>
                    </tr>";
        }
        
        $html .= "
                </table>
            </div>
            
            <div class='alerts-detail'>
                <h3>Детальная информация по товарам</h3>";
        
        foreach ($alerts as $alert) {
            $alertClass = $alert['alert_level'] === 'CRITICAL' ? 'alert-critical' : 'alert-high';
            $daysText = $alert['days_until_stockout'] ? 
                "Закончится через: " . round($alert['days_until_stockout'], 1) . " дней" : 
                "Данные о продажах отсутствуют";
            
            $html .= "
                <div class='alert-item {$alertClass}'>
                    <h4>{$alert['product_name']}</h4>
                    <p><strong>SKU:</strong> {$alert['sku']}</p>
                    <p><strong>Текущий остаток:</strong> {$alert['current_stock']} шт.</p>
                    <p><strong>Зарезервировано:</strong> {$alert['reserved_stock']} шт.</p>
                    <p><strong>{$daysText}</strong></p>
                    <p><strong>Средние продажи:</strong> " . round($alert['avg_daily_sales'], 2) . " шт/день</p>
                    <p><strong>Рекомендуемые действия:</strong> {$alert['recommended_action']}</p>
                </div>";
        }
        
        $html .= "
            </div>
            
            <div style='margin-top: 30px; padding: 15px; background-color: #e9ecef; border-radius: 5px;'>
                <p><small>Этот отчет сгенерирован автоматически системой управления запасами Manhattan.</small></p>
                <p><small>Для получения актуальной информации обратитесь к системе управления складом.</small></p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Send email notification
     * 
     * @param array $notification
     * @return array result with success status and message
     */
    private function sendEmailNotification(array $notification): array {
        try {
            // In a real implementation, this would use a proper email service
            // For now, we'll simulate the email sending and log the attempt
            
            $recipients = implode(', ', $notification['email']['recipients']);
            $subject = $notification['email']['subject'];
            
            // Simulate email sending (replace with actual email service)
            $emailSent = $this->simulateEmailSending($notification['email']);
            
            if ($emailSent) {
                $this->log('INFO', 'Email notification sent successfully', [
                    'warehouse' => $notification['warehouse_name'],
                    'recipients' => $recipients,
                    'subject' => $subject
                ]);
                
                return [
                    'success' => true,
                    'message' => "Email sent to: {$recipients}"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Email service unavailable'
                ];
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Email notification failed', [
                'warehouse' => $notification['warehouse_name'],
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Email sending failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send SMS notification
     * 
     * @param array $notification
     * @return array result with success status and message
     */
    private function sendSMSNotification(array $notification): array {
        try {
            if (empty($notification['sms']['text'])) {
                return [
                    'success' => true,
                    'message' => 'No SMS content to send'
                ];
            }
            
            $recipients = $notification['sms']['recipients'];
            if (empty($recipients)) {
                return [
                    'success' => true,
                    'message' => 'No SMS recipients configured'
                ];
            }
            
            // Simulate SMS sending (replace with actual SMS service)
            $smsSent = $this->simulateSMSSending($notification['sms']);
            
            if ($smsSent) {
                $recipientList = implode(', ', $recipients);
                $this->log('INFO', 'SMS notification sent successfully', [
                    'warehouse' => $notification['warehouse_name'],
                    'recipients' => $recipientList,
                    'text' => $notification['sms']['text']
                ]);
                
                return [
                    'success' => true,
                    'message' => "SMS sent to: {$recipientList}"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'SMS service unavailable'
                ];
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'SMS notification failed', [
                'warehouse' => $notification['warehouse_name'],
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'SMS sending failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Simulate email sending (replace with actual email service integration)
     * 
     * @param array $emailData
     * @return bool success status
     */
    private function simulateEmailSending(array $emailData): bool {
        // In a real implementation, integrate with services like:
        // - PHPMailer
        // - SwiftMailer
        // - SendGrid API
        // - Amazon SES
        // - Mailgun API
        
        // For simulation, we'll just validate the email data and return success
        return !empty($emailData['recipients']) && 
               !empty($emailData['subject']) && 
               !empty($emailData['body']);
    }
    
    /**
     * Simulate SMS sending (replace with actual SMS service integration)
     * 
     * @param array $smsData
     * @return bool success status
     */
    private function simulateSMSSending(array $smsData): bool {
        // In a real implementation, integrate with services like:
        // - Twilio API
        // - Amazon SNS
        // - Nexmo/Vonage API
        // - SMS.ru API
        
        // For simulation, we'll just validate the SMS data and return success
        return !empty($smsData['recipients']) && !empty($smsData['text']);
    }
    
    /**
     * Log notification attempt to database for audit trail
     * 
     * @param array $warehouseGroup
     * @param array $results
     * @return bool success status
     */
    private function logNotificationToDatabase(array $warehouseGroup, array $results): bool {
        try {
            // Check if notification log table exists, if not, we'll skip logging
            $checkTableSql = "SHOW TABLES LIKE 'notification_log'";
            $stmt = $this->pdo->prepare($checkTableSql);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                // Table doesn't exist, skip logging but don't fail
                return true;
            }
            
            $sql = "
                INSERT INTO notification_log 
                (warehouse_name, alert_count, critical_count, high_count, 
                 notification_type, status, message, created_at)
                VALUES 
                (:warehouse_name, :alert_count, :critical_count, :high_count,
                 :notification_type, :status, :message, NOW())
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($results as $result) {
                $stmt->execute([
                    ':warehouse_name' => $warehouseGroup['warehouse_name'],
                    ':alert_count' => $warehouseGroup['total_alerts'],
                    ':critical_count' => $warehouseGroup['critical_count'],
                    ':high_count' => $warehouseGroup['high_count'],
                    ':notification_type' => $result['type'],
                    ':status' => $result['success'] ? 'SUCCESS' : 'FAILED',
                    ':message' => $result['message']
                ]);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to log notification to database', ['error' => $e->getMessage()]);
            return false; // Don't fail the whole process if logging fails
        }
    }
    
    /**
     * Calculate alert metrics from history data
     * 
     * @param array $alerts
     * @param int $days
     * @return array calculated metrics
     */
    private function calculateAlertMetrics(array $alerts, int $days): array {
        $metrics = [
            'total_alerts' => count($alerts),
            'alerts_per_day' => count($alerts) / max($days, 1),
            'status_distribution' => [],
            'alert_level_distribution' => [],
            'alert_type_distribution' => [],
            'warehouse_distribution' => [],
            'response_times' => [],
            'unresolved_alerts' => 0
        ];
        
        $responseTimes = [];
        
        foreach ($alerts as $alert) {
            // Status distribution
            $status = $alert['status'];
            if (!isset($metrics['status_distribution'][$status])) {
                $metrics['status_distribution'][$status] = 0;
            }
            $metrics['status_distribution'][$status]++;
            
            // Alert level distribution
            $level = $alert['alert_level'];
            if (!isset($metrics['alert_level_distribution'][$level])) {
                $metrics['alert_level_distribution'][$level] = 0;
            }
            $metrics['alert_level_distribution'][$level]++;
            
            // Alert type distribution
            $type = $alert['alert_type'];
            if (!isset($metrics['alert_type_distribution'][$type])) {
                $metrics['alert_type_distribution'][$type] = 0;
            }
            $metrics['alert_type_distribution'][$type]++;
            
            // Warehouse distribution
            $warehouse = $alert['warehouse_name'];
            if (!isset($metrics['warehouse_distribution'][$warehouse])) {
                $metrics['warehouse_distribution'][$warehouse] = 0;
            }
            $metrics['warehouse_distribution'][$warehouse]++;
            
            // Response time analysis
            if ($alert['response_time_minutes'] !== null) {
                $responseTimes[] = (float)$alert['response_time_minutes'];
            }
            
            // Count unresolved alerts
            if (in_array($status, ['NEW', 'ACKNOWLEDGED'])) {
                $metrics['unresolved_alerts']++;
            }
        }
        
        // Calculate response time statistics
        if (!empty($responseTimes)) {
            sort($responseTimes);
            $count = count($responseTimes);
            
            $metrics['response_times'] = [
                'count' => $count,
                'average_minutes' => round(array_sum($responseTimes) / $count, 1),
                'median_minutes' => $count % 2 === 0 ? 
                    round(($responseTimes[$count/2 - 1] + $responseTimes[$count/2]) / 2, 1) :
                    round($responseTimes[floor($count/2)], 1),
                'min_minutes' => min($responseTimes),
                'max_minutes' => max($responseTimes),
                'percentile_90' => round($responseTimes[floor($count * 0.9)], 1)
            ];
            
            // Convert to hours for readability
            $metrics['response_times']['average_hours'] = round($metrics['response_times']['average_minutes'] / 60, 1);
            $metrics['response_times']['median_hours'] = round($metrics['response_times']['median_minutes'] / 60, 1);
        }
        
        return $metrics;
    }
    
    /**
     * Group alert history data for analysis
     * 
     * @param array $alerts
     * @return array grouped data
     */
    private function groupAlertHistoryData(array $alerts): array {
        $grouped = [
            'by_warehouse' => [],
            'by_product' => [],
            'by_date' => [],
            'critical_trends' => []
        ];
        
        foreach ($alerts as $alert) {
            $warehouse = $alert['warehouse_name'];
            $productId = $alert['product_id'];
            $date = date('Y-m-d', strtotime($alert['created_at']));
            
            // Group by warehouse
            if (!isset($grouped['by_warehouse'][$warehouse])) {
                $grouped['by_warehouse'][$warehouse] = [
                    'total' => 0,
                    'critical' => 0,
                    'high' => 0,
                    'resolved' => 0,
                    'avg_response_time' => null
                ];
            }
            
            $grouped['by_warehouse'][$warehouse]['total']++;
            if ($alert['alert_level'] === 'CRITICAL') {
                $grouped['by_warehouse'][$warehouse]['critical']++;
            } elseif ($alert['alert_level'] === 'HIGH') {
                $grouped['by_warehouse'][$warehouse]['high']++;
            }
            if ($alert['status'] === 'RESOLVED') {
                $grouped['by_warehouse'][$warehouse]['resolved']++;
            }
            
            // Group by product
            if (!isset($grouped['by_product'][$productId])) {
                $grouped['by_product'][$productId] = [
                    'product_name' => $alert['product_name'],
                    'sku' => $alert['sku'],
                    'alert_count' => 0,
                    'last_alert' => null,
                    'warehouses_affected' => []
                ];
            }
            
            $grouped['by_product'][$productId]['alert_count']++;
            $grouped['by_product'][$productId]['last_alert'] = $alert['created_at'];
            if (!in_array($warehouse, $grouped['by_product'][$productId]['warehouses_affected'])) {
                $grouped['by_product'][$productId]['warehouses_affected'][] = $warehouse;
            }
            
            // Group by date
            if (!isset($grouped['by_date'][$date])) {
                $grouped['by_date'][$date] = [
                    'total' => 0,
                    'critical' => 0,
                    'high' => 0
                ];
            }
            
            $grouped['by_date'][$date]['total']++;
            if ($alert['alert_level'] === 'CRITICAL') {
                $grouped['by_date'][$date]['critical']++;
            } elseif ($alert['alert_level'] === 'HIGH') {
                $grouped['by_date'][$date]['high']++;
            }
        }
        
        // Sort by date for trend analysis
        ksort($grouped['by_date']);
        
        // Calculate trends for critical alerts
        $dates = array_keys($grouped['by_date']);
        if (count($dates) >= 2) {
            $recentDays = array_slice($dates, -3, 3, true); // Last 3 days
            $earlierDays = array_slice($dates, 0, 3, true); // First 3 days
            
            $recentCritical = array_sum(array_map(function($date) use ($grouped) {
                return $grouped['by_date'][$date]['critical'];
            }, $recentDays));
            
            $earlierCritical = array_sum(array_map(function($date) use ($grouped) {
                return $grouped['by_date'][$date]['critical'];
            }, $earlierDays));
            
            $grouped['critical_trends'] = [
                'recent_period' => $recentCritical,
                'earlier_period' => $earlierCritical,
                'trend' => $earlierCritical > 0 ? 
                    ($recentCritical > $earlierCritical ? 'INCREASING' : 
                     ($recentCritical < $earlierCritical ? 'DECREASING' : 'STABLE')) : 
                    'INSUFFICIENT_DATA'
            ];
        }
        
        return $grouped;
    }
    
    /**
     * Log alert action for audit trail
     * 
     * @param int $alertId
     * @param string $action
     * @param string $user
     * @param string $notes
     * @return bool success status
     */
    private function logAlertAction(int $alertId, string $action, string $user, string $notes = ''): bool {
        try {
            // Check if alert_actions table exists
            $checkTableSql = "SHOW TABLES LIKE 'alert_actions'";
            $stmt = $this->pdo->prepare($checkTableSql);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                // Table doesn't exist, create it
                $this->createAlertActionsTable();
            }
            
            $sql = "
                INSERT INTO alert_actions 
                (alert_id, action_type, performed_by, notes, created_at)
                VALUES 
                (:alert_id, :action_type, :performed_by, :notes, NOW())
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':alert_id' => $alertId,
                ':action_type' => $action,
                ':performed_by' => $user,
                ':notes' => $notes
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to log alert action', [
                'alert_id' => $alertId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Create alert_actions table if it doesn't exist
     * 
     * @return bool success status
     */
    private function createAlertActionsTable(): bool {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS alert_actions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    alert_id INT NOT NULL,
                    action_type ENUM('ACKNOWLEDGED', 'RESOLVED', 'IGNORED', 'REOPENED') NOT NULL,
                    performed_by VARCHAR(255) NOT NULL,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_alert_id (alert_id),
                    INDEX idx_action_type (action_type),
                    INDEX idx_performed_by (performed_by),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Audit trail for alert actions'
            ";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute();
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to create alert_actions table', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Log messages if logger is available
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger && method_exists($this->logger, 'log')) {
            $this->logger->log($level, $message, $context);
        }
    }
}