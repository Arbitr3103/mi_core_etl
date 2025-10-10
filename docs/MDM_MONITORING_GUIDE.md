# MDM System Monitoring Guide

## Overview

This guide explains how to monitor the health and performance of the MDM (Master Data Management) system, including sync status, data quality, and error tracking.

## Key Metrics to Monitor

### 1. Sync Status Metrics

**What to Monitor**:

- Percentage of products with `sync_status = 'synced'`
- Number of products with `sync_status = 'pending'`
- Number of products with `sync_status = 'failed'`
- Time since last successful sync

**Target Values**:

- Synced: > 95%
- Pending: < 5%
- Failed: < 1%
- Last sync: < 24 hours ago

**Query**:

```sql
SELECT
    sync_status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM product_cross_reference), 2) as percentage
FROM product_cross_reference
GROUP BY sync_status;
```

### 2. Data Quality Metrics

**What to Monitor**:

- Products with real names vs placeholders
- Products with cached data
- Products with missing brand information
- Cache freshness (age of cached data)

**Query**:

```sql
SELECT
    CASE
        WHEN cached_name IS NULL THEN 'No cached name'
        WHEN cached_name LIKE 'Товар%ID%' THEN 'Placeholder name'
        ELSE 'Real name'
    END as name_status,
    COUNT(*) as count
FROM product_cross_reference
GROUP BY name_status;
```

### 3. Error Metrics

**What to Monitor**:

- Total errors in last 24 hours
- Error types distribution
- Products with repeated failures
- API timeout rate

**Query**:

```sql
SELECT
    error_type,
    COUNT(*) as count,
    COUNT(DISTINCT product_id) as affected_products
FROM sync_errors
WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY error_type
ORDER BY count DESC;
```

### 4. Performance Metrics

**What to Monitor**:

- Average sync time per product
- Database query performance
- API response times
- Memory usage during sync

## Monitoring Dashboard

### Access the Dashboard

```bash
# Open in browser
http://your-domain/html/sync_monitor_dashboard.php
```

### Dashboard Features

1. **Real-time Status Widget**

   - Current sync status
   - Products synced today
   - Active errors
   - System health indicator

2. **Sync History Chart**

   - Success/failure trends over time
   - Sync duration trends
   - Products processed per day

3. **Error Analysis**

   - Top error types
   - Most problematic products
   - Error resolution rate

4. **Data Quality Scores**
   - Name completeness
   - Brand completeness
   - Cache freshness
   - Overall quality score

### Widget Integration

Add monitoring widget to existing dashboards:

```php
<?php
// In your dashboard file
require_once 'html/widgets/sync_monitor_widget.php';

// Display widget
echo renderSyncMonitorWidget();
?>
```

## Automated Monitoring

### 1. Cron Jobs for Regular Checks

```bash
# Add to crontab
crontab -e

# Check sync status every hour
0 * * * * php /path/to/check_sync_status.php

# Generate daily report
0 8 * * * php /path/to/generate_daily_report.php

# Clean old errors weekly
0 2 * * 0 php /path/to/clean_old_errors.php
```

### 2. Monitoring Script

Create `check_sync_status.php`:

```php
<?php
require_once 'config.php';
require_once 'src/SyncErrorHandler.php';

$errorHandler = new SyncErrorHandler($pdo);

// Check sync status
$stmt = $pdo->query("
    SELECT
        sync_status,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM product_cross_reference), 2) as percentage
    FROM product_cross_reference
    GROUP BY sync_status
");

$status = [];
foreach ($stmt->fetchAll() as $row) {
    $status[$row['sync_status']] = [
        'count' => $row['count'],
        'percentage' => $row['percentage']
    ];
}

// Alert if too many failures
if (isset($status['failed']) && $status['failed']['percentage'] > 5) {
    sendAlert("High failure rate: {$status['failed']['percentage']}%");
}

// Alert if too many pending
if (isset($status['pending']) && $status['pending']['percentage'] > 10) {
    sendAlert("High pending rate: {$status['pending']['percentage']}%");
}

// Check for recent errors
$recentErrors = $errorHandler->getRecentErrors(1);
if (!empty($recentErrors)) {
    $lastError = $recentErrors[0];
    $hoursSinceError = (time() - strtotime($lastError['created_at'])) / 3600;

    if ($hoursSinceError < 1) {
        sendAlert("Recent error: {$lastError['error_message']}");
    }
}

function sendAlert($message) {
    // Send email, Slack notification, etc.
    error_log("MDM ALERT: $message");

    // Example: Send email
    mail(
        'admin@example.com',
        'MDM System Alert',
        $message,
        'From: monitoring@example.com'
    );
}
```

### 3. Daily Report Script

Create `generate_daily_report.php`:

```php
<?php
require_once 'config.php';
require_once 'src/SyncErrorHandler.php';

$errorHandler = new SyncErrorHandler($pdo);

// Generate report
$report = [
    'date' => date('Y-m-d'),
    'sync_status' => getSyncStatus($pdo),
    'data_quality' => getDataQuality($pdo),
    'errors' => $errorHandler->getErrorStats(),
    'performance' => getPerformanceMetrics($pdo)
];

// Save report
file_put_contents(
    "reports/daily_report_" . date('Y-m-d') . ".json",
    json_encode($report, JSON_PRETTY_PRINT)
);

// Send summary email
sendDailyReport($report);

function getSyncStatus($pdo) {
    $stmt = $pdo->query("
        SELECT sync_status, COUNT(*) as count
        FROM product_cross_reference
        GROUP BY sync_status
    ");
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function getDataQuality($pdo) {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN cached_name NOT LIKE 'Товар%ID%' THEN 1 ELSE 0 END) as with_real_names,
            SUM(CASE WHEN cached_brand IS NOT NULL THEN 1 ELSE 0 END) as with_brands
        FROM product_cross_reference
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getPerformanceMetrics($pdo) {
    $stmt = $pdo->query("
        SELECT
            AVG(sync_duration) as avg_sync_time,
            MAX(sync_duration) as max_sync_time,
            COUNT(*) as syncs_today
        FROM sync_log
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function sendDailyReport($report) {
    $message = "MDM Daily Report - " . $report['date'] . "\n\n";
    $message .= "Sync Status:\n";
    foreach ($report['sync_status'] as $status => $count) {
        $message .= "  $status: $count\n";
    }
    $message .= "\nData Quality:\n";
    $message .= "  Total products: {$report['data_quality']['total']}\n";
    $message .= "  With real names: {$report['data_quality']['with_real_names']}\n";
    $message .= "  With brands: {$report['data_quality']['with_brands']}\n";

    mail('admin@example.com', 'MDM Daily Report', $message);
}
```

## Alert Configuration

### 1. Alert Thresholds

Configure in `config.php`:

```php
<?php
// Alert thresholds
define('ALERT_FAILED_PERCENTAGE', 5);      // Alert if > 5% failed
define('ALERT_PENDING_PERCENTAGE', 10);    // Alert if > 10% pending
define('ALERT_ERROR_COUNT_HOURLY', 10);    // Alert if > 10 errors/hour
define('ALERT_SYNC_AGE_HOURS', 48);        // Alert if no sync in 48h
define('ALERT_QUALITY_SCORE', 80);         // Alert if quality < 80%
```

### 2. Alert Channels

**Email Alerts**:

```php
function sendEmailAlert($subject, $message) {
    $to = 'admin@example.com';
    $headers = 'From: mdm-monitoring@example.com';
    mail($to, $subject, $message, $headers);
}
```

**Slack Alerts**:

```php
function sendSlackAlert($message) {
    $webhook = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
    $data = json_encode(['text' => $message]);

    $ch = curl_init($webhook);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
```

**Log Alerts**:

```php
function logAlert($level, $message) {
    $logFile = 'logs/alerts.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        "[$timestamp] [$level] $message\n",
        FILE_APPEND
    );
}
```

### 3. Alert Rules

Create `alert_rules.php`:

```php
<?php
require_once 'config.php';

class AlertRules {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function checkAllRules() {
        $alerts = [];

        // Rule 1: High failure rate
        if ($this->checkFailureRate() > ALERT_FAILED_PERCENTAGE) {
            $alerts[] = [
                'level' => 'critical',
                'message' => 'High sync failure rate detected'
            ];
        }

        // Rule 2: Stale sync
        if ($this->checkLastSyncAge() > ALERT_SYNC_AGE_HOURS) {
            $alerts[] = [
                'level' => 'warning',
                'message' => 'No successful sync in ' . ALERT_SYNC_AGE_HOURS . ' hours'
            ];
        }

        // Rule 3: Low data quality
        if ($this->checkDataQuality() < ALERT_QUALITY_SCORE) {
            $alerts[] = [
                'level' => 'warning',
                'message' => 'Data quality score below threshold'
            ];
        }

        // Rule 4: High error rate
        if ($this->checkErrorRate() > ALERT_ERROR_COUNT_HOURLY) {
            $alerts[] = [
                'level' => 'critical',
                'message' => 'High error rate detected'
            ];
        }

        return $alerts;
    }

    private function checkFailureRate() {
        $stmt = $this->pdo->query("
            SELECT
                COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) * 100.0 / COUNT(*) as failure_rate
            FROM product_cross_reference
        ");
        return $stmt->fetch()['failure_rate'];
    }

    private function checkLastSyncAge() {
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(HOUR, MAX(last_api_sync), NOW()) as hours_since_sync
            FROM product_cross_reference
            WHERE sync_status = 'synced'
        ");
        return $stmt->fetch()['hours_since_sync'] ?? 999;
    }

    private function checkDataQuality() {
        $stmt = $this->pdo->query("
            SELECT
                (COUNT(CASE WHEN cached_name NOT LIKE 'Товар%ID%' THEN 1 END) * 100.0 / COUNT(*)) as quality_score
            FROM product_cross_reference
        ");
        return $stmt->fetch()['quality_score'];
    }

    private function checkErrorRate() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as error_count
            FROM sync_errors
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        return $stmt->fetch()['error_count'];
    }
}

// Run checks
$rules = new AlertRules($pdo);
$alerts = $rules->checkAllRules();

foreach ($alerts as $alert) {
    sendAlert($alert['level'], $alert['message']);
}
```

## API Endpoints for Monitoring

### 1. Sync Status Endpoint

```php
// api/sync-status.php
<?php
require_once '../config.php';

header('Content-Type: application/json');

$stmt = $pdo->query("
    SELECT
        sync_status,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM product_cross_reference), 2) as percentage
    FROM product_cross_reference
    GROUP BY sync_status
");

$status = [];
foreach ($stmt->fetchAll() as $row) {
    $status[$row['sync_status']] = [
        'count' => (int)$row['count'],
        'percentage' => (float)$row['percentage']
    ];
}

echo json_encode([
    'status' => 'success',
    'data' => $status,
    'timestamp' => date('c')
]);
```

### 2. Health Check Endpoint

```php
// api/health-check.php
<?php
require_once '../config.php';

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'checks' => []
];

// Check database connection
try {
    $pdo->query('SELECT 1');
    $health['checks']['database'] = 'ok';
} catch (Exception $e) {
    $health['checks']['database'] = 'error';
    $health['status'] = 'unhealthy';
}

// Check tables exist
$tables = ['product_cross_reference', 'dim_products', 'inventory_data'];
foreach ($tables as $table) {
    $result = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
    $health['checks']["table_$table"] = $result ? 'ok' : 'missing';
    if (!$result) $health['status'] = 'unhealthy';
}

// Check sync status
$stmt = $pdo->query("
    SELECT
        COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) * 100.0 / COUNT(*) as failure_rate
    FROM product_cross_reference
");
$failureRate = $stmt->fetch()['failure_rate'];
$health['checks']['sync_failure_rate'] = $failureRate < 5 ? 'ok' : 'warning';
if ($failureRate > 10) $health['status'] = 'degraded';

echo json_encode($health);
```

### 3. Metrics Endpoint

```php
// api/metrics.php
<?php
require_once '../config.php';

header('Content-Type: application/json');

$metrics = [
    'sync_status' => getSyncMetrics($pdo),
    'data_quality' => getQualityMetrics($pdo),
    'errors' => getErrorMetrics($pdo),
    'performance' => getPerformanceMetrics($pdo)
];

echo json_encode($metrics);

function getSyncMetrics($pdo) {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) as synced,
            COUNT(CASE WHEN sync_status = 'pending' THEN 1 END) as pending,
            COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) as failed
        FROM product_cross_reference
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getQualityMetrics($pdo) {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN cached_name NOT LIKE 'Товар%ID%' THEN 1 END) as with_real_names,
            COUNT(CASE WHEN cached_brand IS NOT NULL THEN 1 END) as with_brands,
            AVG(TIMESTAMPDIFF(DAY, last_api_sync, NOW())) as avg_cache_age_days
        FROM product_cross_reference
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getErrorMetrics($pdo) {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_errors_24h,
            COUNT(DISTINCT product_id) as affected_products
        FROM sync_errors
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getPerformanceMetrics($pdo) {
    return [
        'avg_sync_time_ms' => 150,
        'avg_query_time_ms' => 25,
        'cache_hit_rate' => 0.85
    ];
}
```

## Integration with External Monitoring Tools

### Prometheus Metrics

```php
// api/prometheus-metrics.php
<?php
require_once '../config.php';

header('Content-Type: text/plain');

$stmt = $pdo->query("
    SELECT sync_status, COUNT(*) as count
    FROM product_cross_reference
    GROUP BY sync_status
");

foreach ($stmt->fetchAll() as $row) {
    echo "mdm_sync_status{status=\"{$row['sync_status']}\"} {$row['count']}\n";
}

$stmt = $pdo->query("
    SELECT COUNT(*) as count
    FROM sync_errors
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
echo "mdm_errors_hourly " . $stmt->fetch()['count'] . "\n";
```

### Grafana Dashboard

See `grafana_dashboard.json` for pre-configured dashboard template.

## Best Practices

1. **Monitor continuously**: Set up automated checks every hour
2. **Set appropriate thresholds**: Adjust alert thresholds based on your needs
3. **Review reports regularly**: Check daily reports for trends
4. **Act on alerts promptly**: Investigate and resolve issues quickly
5. **Keep historical data**: Retain metrics for trend analysis
6. **Document incidents**: Log all issues and resolutions

## References

- Requirements: 8.3, 4.3
- Design Document: `design.md`
- API Documentation: `api/sync-monitor.php`
- Dashboard: `html/sync_monitor_dashboard.php`
