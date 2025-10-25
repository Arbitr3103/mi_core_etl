# AlertManager Documentation

## Overview

The AlertManager is a comprehensive alerting system for Analytics ETL processes that provides critical error alerting, daily summary reports, and multi-channel notification support.

**Task:** 7.2 Создать AlertManager для Analytics ETL  
**Requirements:** 7.1, 7.2, 7.3, 7.4, 7.5

## Features

### 🚨 Alert Capabilities

1. **Critical Error Alerting**

    - ETL process failures
    - API failure rate monitoring (>20% threshold)
    - Data quality issues
    - System health problems
    - SLA breaches

2. **Daily Summary Reports**

    - ETL performance statistics
    - Data quality metrics
    - Alert summaries
    - Overall health scores

3. **Multi-Channel Integration**

    - Email notifications
    - Slack integration
    - Telegram bot support
    - Configurable channel routing by severity

4. **Smart Alert Management**
    - Alert throttling and deduplication
    - Severity-based channel selection
    - Historical alert tracking
    - Automatic cleanup of old alerts

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    AlertManager                             │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │  Alert Types    │  │   Severities    │  │   Channels   │ │
│  │                 │  │                 │  │              │ │
│  │ • ETL Failure   │  │ • CRITICAL      │  │ • Email      │ │
│  │ • API Failure   │  │ • ERROR         │  │ • Slack      │ │
│  │ • Data Quality  │  │ • WARNING       │  │ • Telegram   │ │
│  │ • System Health │  │ • INFO          │  │              │ │
│  │ • SLA Breach    │  │                 │  │              │ │
│  │ • Daily Summary │  │                 │  │              │ │
│  └─────────────────┘  └─────────────────┘  └──────────────┘ │
├─────────────────────────────────────────────────────────────┤
│                    Database Layer                           │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │ alert_history   │  │ analytics_etl_  │  │   inventory  │ │
│  │                 │  │      log        │  │              │ │
│  └─────────────────┘  └─────────────────┘  └──────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

## Alert Types and Thresholds

### Alert Types

| Type            | Description            | Trigger Conditions        |
| --------------- | ---------------------- | ------------------------- |
| `ETL_FAILURE`   | ETL process failures   | Any ETL process exception |
| `API_FAILURE`   | High API failure rate  | Failure rate > 20%        |
| `DATA_QUALITY`  | Data quality issues    | Quality score < 90%       |
| `SYSTEM_HEALTH` | System resource issues | Disk usage > 90%, etc.    |
| `SLA_BREACH`    | SLA violations         | Uptime < 99.5%, etc.      |
| `DAILY_SUMMARY` | Daily reports          | Scheduled daily           |

### Alert Thresholds (from Requirement 7)

| Metric               | Threshold  | Alert Level |
| -------------------- | ---------- | ----------- |
| API Failure Rate     | > 20%      | ERROR       |
| Data Age             | > 6 hours  | WARNING     |
| Data Unavailable     | > 12 hours | CRITICAL    |
| Source Discrepancy   | > 15%      | WARNING     |
| Consecutive Failures | ≥ 3        | CRITICAL    |
| Throttle Period      | 60 minutes | -           |

### Severity Levels

| Severity   | Description                                       | Channel Routing |
| ---------- | ------------------------------------------------- | --------------- |
| `CRITICAL` | System failure, immediate action required         | All channels    |
| `ERROR`    | Significant issues affecting functionality        | Email + Slack   |
| `WARNING`  | Performance degradation or approaching thresholds | Slack only      |
| `INFO`     | Informational messages and summaries              | Email only      |

## Usage

### 1. Basic Alert Sending

```php
<?php
require_once 'src/Services/AlertManager.php';

// Initialize AlertManager
$alertManager = new AlertManager([
    'enable_email' => true,
    'enable_slack' => true,
    'enable_telegram' => false
]);

// Send basic alert
$success = $alertManager->sendAlert(
    AlertManager::TYPE_SYSTEM_HEALTH,
    AlertManager::SEVERITY_WARNING,
    'High Memory Usage',
    'System memory usage is at 85%',
    ['memory_usage' => 85, 'threshold' => 80]
);
```

### 2. Specialized Alert Methods

```php
// ETL failure alert
$alertManager->sendETLFailureAlert(
    'batch_20241025_001',
    'Database connection timeout',
    ['execution_time' => 1800, 'records_processed' => 0]
);

// API failure alert
$alertManager->sendAPIFailureAlert(25.5, 100, 25);

// Data quality alert
$alertManager->sendDataQualityAlert(75.0, 25, 100);

// Data staleness alert
$alertManager->sendDataStalenessAlert(8.5, '2024-01-15 10:00:00');

// SLA breach alert
$alertManager->sendSLABreachAlert('uptime', 98.2, 99.5);
```

### 3. Daily Summary Report

```php
// Generate and send daily summary
$success = $alertManager->sendDailySummaryReport();
```

### 4. Alert Statistics

```php
// Get alert statistics for last 7 days
$stats = $alertManager->getAlertStatistics(7);

// Clean up old alerts (older than 90 days)
$deletedCount = $alertManager->cleanupOldAlerts(90);
```

## Command Line Interface

### Basic Commands

```bash
# Send test alert
php run_alert_manager.php test-alert --severity=WARNING

# Generate daily summary
php run_alert_manager.php daily-summary

# Show alert statistics
php run_alert_manager.php stats --days=7

# Test all channels
php run_alert_manager.php test-channels

# Clean up old alerts
php run_alert_manager.php cleanup --days=30
```

### Advanced Options

```bash
# Send specific alert type
php run_alert_manager.php test-alert \
  --severity=CRITICAL \
  --type=etl_failure \
  --channels=email,slack

# Verbose statistics
php run_alert_manager.php stats --days=30 --verbose

# Custom configuration
php run_alert_manager.php daily-summary --config=custom_config.php
```

## Configuration

### Environment Variables

```bash
# Email Configuration
ALERT_EMAIL_ENABLED=true
ALERT_EMAIL_RECIPIENTS=admin@company.com,ops@company.com
ALERT_FROM_EMAIL=alerts@warehouse-system.com
ALERT_FROM_NAME=Warehouse ETL Alerts

# Slack Configuration
ALERT_SLACK_ENABLED=true
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
SLACK_ALERT_CHANNEL=#alerts
SLACK_BOT_USERNAME=ETL Alert Bot

# Telegram Configuration
ALERT_TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHAT_ID=your_chat_id_here
```

### PHP Configuration

```php
$config = [
    'log_file' => '/path/to/alerts.log',
    'enable_email' => true,
    'enable_slack' => false,
    'enable_telegram' => false,
    'email_recipients' => ['admin@company.com'],
    'slack_webhook_url' => 'https://hooks.slack.com/...',
    'telegram_bot_token' => 'bot_token',
    'telegram_chat_id' => 'chat_id',
    'throttle_enabled' => true,
    'daily_summary_time' => '09:00',
    'timezone' => 'Europe/Moscow'
];

$alertManager = new AlertManager($config);
```

## Channel Setup

### Email Setup

Email alerts use PHP's built-in `mail()` function:

1. Ensure your server has a working mail configuration
2. Set the `ALERT_EMAIL_RECIPIENTS` environment variable
3. Configure `ALERT_FROM_EMAIL` and `ALERT_FROM_NAME`

### Slack Setup

1. Create a Slack app in your workspace
2. Add an Incoming Webhook
3. Set the `SLACK_WEBHOOK_URL` environment variable
4. Configure channel and bot username

### Telegram Setup

1. Create a bot using @BotFather
2. Get your bot token
3. Find your chat ID using @userinfobot
4. Set `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID`

## Automated Scheduling

### Install Cron Jobs

```bash
# Run the setup script
./setup_alert_manager_cron.sh
```

This creates the following schedule:

-   **Daily Summary**: 9:00 AM every day
-   **Weekly Statistics**: 10:00 AM every Monday
-   **Monthly Cleanup**: 2:00 AM on the 1st of each month
-   **Channel Test**: 11:00 PM every Sunday

### Manual Cron Configuration

```bash
# Daily summary report at 9:00 AM
0 9 * * * cd /path/to/project && php run_alert_manager.php daily-summary

# Weekly statistics on Mondays at 10:00 AM
0 10 * * 1 cd /path/to/project && php run_alert_manager.php stats --days=7

# Monthly cleanup on the 1st at 2:00 AM
0 2 1 * * cd /path/to/project && php run_alert_manager.php cleanup --days=90
```

## Database Schema

### alert_history Table

```sql
CREATE TABLE alert_history (
    id SERIAL PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    context JSONB,
    channels_sent TEXT[],
    throttle_key VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW(),
    sent_at TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending'
);

-- Indexes
CREATE INDEX idx_alert_history_type ON alert_history(alert_type);
CREATE INDEX idx_alert_history_severity ON alert_history(severity);
CREATE INDEX idx_alert_history_throttle ON alert_history(throttle_key);
CREATE INDEX idx_alert_history_created ON alert_history(created_at);
```

## Integration with ETL System

### AnalyticsETL Integration

The AlertManager is automatically integrated with the AnalyticsETL system:

```php
// In AnalyticsETL constructor
$this->alertManager = new AlertManager($alertConfig);

// Automatic alerts on ETL failures
private function handleETLError(Exception $e, int $executionTime): ETLResult {
    // ... error handling ...

    // Send alert
    $this->sendETLFailureAlert($e->getMessage(), [
        'execution_time_ms' => $executionTime,
        'metrics' => $this->metrics
    ]);

    return $result;
}
```

### AnalyticsETLMonitor Integration

The AlertManager is integrated with the monitoring system:

```php
// In AnalyticsETLMonitor
private function addAlert(string $level, string $message, array $context = []): void {
    // ... local alert handling ...

    // Send via AlertManager for critical/error alerts
    if ($this->alertManager && in_array($level, ['CRITICAL', 'ERROR'])) {
        $this->alertManager->sendAlert($alertType, $level, $message, $formattedMessage, $context);
    }
}
```

## Alert Message Formats

### Email Format

```html
<h2 style="color: #dc3545">Critical ETL Failure</h2>
<p><strong>Severity:</strong> CRITICAL</p>
<p><strong>Type:</strong> etl_failure</p>
<p><strong>Time:</strong> 2024-01-15 14:30:00</p>
<hr />
<p>Analytics ETL process has failed with error: Database connection timeout</p>
<hr />
<h3>Additional Details:</h3>
<pre>
{
    "batch_id": "batch_20241025_001",
    "execution_time": 1800,
    "records_processed": 0
}
</pre>
```

### Slack Format

```json
{
    "channel": "#alerts",
    "username": "ETL Alert Bot",
    "icon_emoji": ":rotating_light:",
    "attachments": [
        {
            "color": "danger",
            "title": "Critical ETL Failure",
            "text": "Analytics ETL process has failed...",
            "fields": [
                { "title": "Severity", "value": "CRITICAL", "short": true },
                { "title": "Type", "value": "etl_failure", "short": true }
            ]
        }
    ]
}
```

### Telegram Format

````
🚨 *Critical ETL Failure*

📊 *Severity:* CRITICAL
🔧 *Type:* etl_failure
⏰ *Time:* 2024-01-15 14:30:00

📝 *Message:*
Analytics ETL process has failed with error: Database connection timeout

📋 *Details:*
```json
{
    "batch_id": "batch_20241025_001",
    "execution_time": 1800
}
````

```

## Daily Summary Report Format

```

📊 Daily ETL Summary Report for 2024-01-15

🔄 ETL Performance:
• Total runs: 24
• Successful: 22
• Failed: 2
• Success rate: 91.67%
• Avg execution time: 45.2s
• Total records processed: 125,430

📈 Data Quality:
• Average quality score: 94.2%
• Total records: 125,430
• Low quality records: 3,245
• Unique warehouses: 32

🚨 Alert Summary:
• Total alerts: 5
• Critical: 1
• Errors: 2
• Warnings: 2

🏥 Overall Health Score: 🟢 92.5%

````

## Troubleshooting

### Common Issues

1. **Emails not sending**
   ```bash
   # Check PHP mail configuration
   php -r "echo mail('test@example.com', 'Test', 'Test message') ? 'OK' : 'Failed';"

   # Check mail logs
   tail -f /var/log/mail.log
````

2. **Slack webhook not working**

    ```bash
    # Test webhook manually
    curl -X POST -H 'Content-type: application/json' \
      --data '{"text":"Test message"}' \
      YOUR_WEBHOOK_URL
    ```

3. **Telegram bot not responding**

    ```bash
    # Test bot API
    curl "https://api.telegram.org/botYOUR_BOT_TOKEN/getMe"

    # Test sending message
    curl "https://api.telegram.org/botYOUR_BOT_TOKEN/sendMessage?chat_id=YOUR_CHAT_ID&text=Test"
    ```

4. **Database connection errors**
    ```bash
    # Test database connection
    php -r "
    require_once 'config.php';
    try {
        \$pdo = getDatabaseConnection();
        echo 'Database connection: OK\n';
    } catch (Exception \$e) {
        echo 'Database error: ' . \$e->getMessage() . '\n';
    }
    "
    ```

### Debug Mode

Enable detailed logging:

```php
$alertManager = new AlertManager([
    'log_file' => '/path/to/debug.log',
    'detailed_logging' => true
]);
```

Check logs:

```bash
tail -f logs/analytics_etl/alerts.log
tail -f logs/cron/alert_manager_cron.log
```

## Performance Considerations

-   **Alert Throttling**: Prevents spam with 60-minute cooldown
-   **Database Optimization**: Proper indexes on alert_history table
-   **Memory Usage**: Efficient processing of large datasets
-   **Channel Timeouts**: 10-second timeout for external API calls
-   **Batch Processing**: Handles multiple alerts efficiently

## Security

-   **Data Sanitization**: All user input is properly escaped
-   **Webhook Security**: HTTPS-only webhook URLs
-   **Database Security**: Prepared statements prevent SQL injection
-   **Log Security**: No sensitive data in logs
-   **Channel Security**: Secure token/webhook management

## Maintenance

### Regular Tasks

1. **Weekly**: Review alert statistics and trends
2. **Monthly**: Clean up old alerts and optimize database
3. **Quarterly**: Review and adjust alert thresholds
4. **Annually**: Update channel configurations and test all integrations

### Monitoring the Monitor

-   Monitor AlertManager logs for errors
-   Check cron job execution
-   Verify channel delivery rates
-   Review alert response times

## Related Documentation

-   [AnalyticsETLMonitor](./AnalyticsETLMonitor.md)
-   [AnalyticsETL System](./AnalyticsETL.md)
-   [Database Schema](./database_schema.md)
-   [Cron Job Setup](../setup_alert_manager_cron.sh)
