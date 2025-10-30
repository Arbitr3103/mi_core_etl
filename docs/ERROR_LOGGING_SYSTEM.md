# Comprehensive Error Logging System

Complete documentation for the warehouse dashboard error logging, monitoring, and alerting system.

## Overview

The comprehensive error logging system provides centralized, structured logging with automatic rotation, archiving, and intelligent alerting for all components of the warehouse dashboard.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Error Sources                             │
├──────────────┬──────────────┬──────────────┬────────────────┤
│   React      │   PHP API    │  Python ETL  │   Monitoring   │
│  Frontend    │   Backend    │  Importers   │    Scripts     │
└──────┬───────┴──────┬───────┴──────┬───────┴────────┬───────┘
       │              │              │                │
       └──────────────┴──────────────┴────────────────┘
                      │
       ┌──────────────▼──────────────┐
       │  Comprehensive Error Logger  │
       │  - Structured JSON logging   │
       │  - Component categorization  │
       │  - Trace ID tracking         │
       └──────────────┬──────────────┘
                      │
       ┌──────────────▼──────────────┐
       │      Log Management          │
       │  - Automatic rotation        │
       │  - Compression & archiving   │
       │  - Cleanup old logs          │
       └──────────────┬──────────────┘
                      │
       ┌──────────────▼──────────────┐
       │    Alert Management          │
       │  - Rule-based alerting       │
       │  - Multiple channels         │
       │  - Throttling & dedup        │
       └──────────────┬──────────────┘
                      │
       ┌──────────────▼──────────────┐
       │   Notification Channels      │
       ├──────────┬──────────┬────────┤
       │  Email   │  Slack   │Telegram│
       └──────────┴──────────┴────────┘
```

## Components

### 1. ErrorLogger Class (PHP)

**Location:** `api/classes/ErrorLogger.php`

Centralized error logging with structured JSON format, automatic rotation, and multi-channel alerting.

**Features:**

-   Structured JSON logging
-   Component-based log organization
-   Automatic log rotation (configurable size threshold)
-   Compression and archiving
-   Multi-level logging (debug, info, warning, error, critical, alert, emergency)
-   Trace ID for request tracking
-   Performance monitoring
-   Alert management

**Usage:**

```php
require_once 'api/classes/ErrorLogger.php';

$logger = new ErrorLogger([
    'log_path' => '/path/to/logs',
    'max_log_size' => '50MB',
    'max_log_files' => 30,
    'log_level' => 'info',
    'alerts' => [
        'email' => 'admin@example.com',
        'slack_webhook' => 'https://hooks.slack.com/...',
        'telegram' => [
            'bot_token' => 'YOUR_BOT_TOKEN',
            'chat_id' => 'YOUR_CHAT_ID'
        ]
    ]
]);

// Log messages
$logger->info('User logged in', ['user_id' => 123], 'auth');
$logger->error('Database connection failed', ['error' => $e->getMessage()], 'database');
$logger->critical('System out of memory', [], 'system');

// Log API calls
$logger->logApiCall('/api/inventory', 'GET', ['limit' => 50], $response, 0.234);

// Log slow queries
$logger->logSlowQuery($sql, 2.5, ['param1' => 'value1']);
```

### 2. ComprehensiveErrorLogger (Python)

**Location:** `importers/error_logger.py`

Python error logger for ETL components with structured logging and integration with PHP logging endpoint.

**Features:**

-   Structured JSON logging
-   Rotating file handlers
-   Console and file output
-   Exception tracking with stack traces
-   Integration with PHP logging endpoint
-   ETL-specific logging methods

**Usage:**

```python
from error_logger import get_logger

# Get logger instance
logger = get_logger('ozon_importer', {
    'log_path': '../logs',
    'max_log_size': '50MB',
    'alerts': {
        'php_endpoint': 'https://market-mi.ru/api/comprehensive-error-logging.php',
        'slack_webhook': 'https://hooks.slack.com/...'
    }
})

# Set trace ID for request tracking
logger.set_trace_id('trace_12345')

# Log messages
logger.info('Starting ETL process', {'source': 'ozon_api'}, 'ozon_importer')
logger.error('Failed to fetch data', {'error': str(e)}, 'ozon_importer')

# ETL-specific logging
logger.log_etl_start('ozon_importer', {'batch_size': 100})
logger.log_etl_end('ozon_importer', True, {'records_processed': 500})

# Log API calls
logger.log_api_call('https://api.ozon.ru/v1/products', 'GET', 1.234, 200)

# Log database queries
logger.log_database_query(sql, 0.456, {'param': 'value'})
```

### 3. Comprehensive Error Logging API

**Location:** `api/comprehensive-error-logging.php`

REST API endpoint for receiving and processing error logs from all components.

**Endpoints:**

#### POST /api/comprehensive-error-logging.php

Log an error from any component.

**Request:**

```json
{
    "level": "error",
    "message": "Failed to load inventory data",
    "component": "frontend",
    "context": {
        "error_details": "Network timeout",
        "user_id": 123
    },
    "source": "react",
    "stack": "Error: Network timeout\n  at fetch...",
    "traceId": "trace_12345"
}
```

**Response:**

```json
{
    "success": true,
    "message": "Error logged successfully",
    "trace_id": "trace_12345"
}
```

#### GET /api/comprehensive-error-logging.php?component=frontend&days=7

Get log statistics.

**Response:**

```json
{
  "success": true,
  "stats": {
    "total_logs": 1234,
    "by_level": {
      "ERROR": 45,
      "WARNING": 123,
      "INFO": 1066
    },
    "by_component": {
      "frontend": 456,
      "api": 678,
      "etl": 100
    },
    "recent_errors": [...]
  }
}
```

### 4. Alert Manager

**Location:** `api/alert-manager.php`

Manages alert rules, notification channels, and alert history.

**Endpoints:**

#### GET /api/alert-manager.php?action=rules

Get alert rules configuration.

#### POST /api/alert-manager.php

Update alert rules.

#### GET /api/alert-manager.php?action=recent&limit=50

Get recent alerts.

#### GET /api/alert-manager.php?action=stats&days=7

Get alert statistics.

#### GET /api/alert-manager.php?action=test

Test all configured alert channels.

**Alert Rules Configuration:**

```json
{
    "rules": [
        {
            "id": "critical_errors",
            "name": "Critical Errors",
            "enabled": true,
            "conditions": {
                "level": ["critical", "emergency", "alert"]
            },
            "actions": {
                "email": true,
                "slack": true,
                "telegram": false
            },
            "throttle": 300
        }
    ],
    "channels": {
        "email": {
            "enabled": true,
            "recipients": ["admin@example.com"]
        },
        "slack": {
            "enabled": true,
            "webhook_url": "https://hooks.slack.com/..."
        },
        "telegram": {
            "enabled": false,
            "bot_token": "",
            "chat_id": ""
        }
    }
}
```

### 5. Log Viewer API

**Location:** `api/log-viewer.php`

Provides endpoints for viewing, searching, and analyzing logs.

**Endpoints:**

#### GET /api/log-viewer.php?action=files&component=frontend

List available log files.

#### GET /api/log-viewer.php?action=read&file=frontend/react-2024-10-30.log&limit=100&offset=0

Read log file with pagination.

#### GET /api/log-viewer.php?action=search&query=error&component=api&days=7

Search logs.

#### GET /api/log-viewer.php?action=stats&days=7

Get log statistics.

#### GET /api/log-viewer.php?action=trends&days=7

Get error trends over time.

### 6. Log Rotation Script

**Location:** `scripts/rotate_and_archive_logs.sh`

Automated script for rotating, compressing, and archiving log files.

**Features:**

-   Rotates logs exceeding size threshold
-   Compresses rotated logs with gzip
-   Archives old logs
-   Cleans up very old archives
-   Generates rotation reports

**Configuration:**

```bash
export LOG_PATH="/var/www/market-mi.ru/logs"
export MAX_LOG_AGE_DAYS=30
export MAX_ARCHIVE_AGE_DAYS=90
export MAX_LOG_SIZE_MB=50
```

**Manual Execution:**

```bash
./scripts/rotate_and_archive_logs.sh
```

**Automated Setup:**

```bash
./scripts/setup_log_rotation_cron.sh
```

This sets up a daily cron job at 2:00 AM.

## Log Structure

### Structured JSON Format

All logs are stored in structured JSON format for easy parsing and analysis:

```json
{
    "timestamp": "2024-10-30T15:30:45.123Z",
    "level": "ERROR",
    "component": "frontend",
    "message": "Failed to load inventory data",
    "context": {
        "error_details": "Network timeout",
        "user_id": 123,
        "endpoint": "/api/inventory"
    },
    "server": {
        "request_uri": "/warehouse-dashboard",
        "request_method": "GET",
        "user_agent": "Mozilla/5.0...",
        "ip_address": "192.168.1.100",
        "server_name": "market-mi.ru"
    },
    "runtime": {
        "memory_usage": 12582912,
        "peak_memory": 15728640,
        "execution_time": 0.234
    },
    "trace_id": "trace_1698675045_abc123"
}
```

### Log Levels

1. **DEBUG** - Detailed debugging information
2. **INFO** - Informational messages
3. **NOTICE** - Normal but significant events
4. **WARNING** - Warning messages
5. **ERROR** - Error conditions
6. **CRITICAL** - Critical conditions
7. **ALERT** - Action must be taken immediately
8. **EMERGENCY** - System is unusable

### Log Organization

```
logs/
├── frontend/           # React frontend logs
│   ├── react-2024-10-30.log
│   └── react-errors-2024-10-30.log
├── api/               # PHP API logs
│   ├── api-2024-10-30.log
│   └── api-errors-2024-10-30.log
├── etl/               # Python ETL logs
│   ├── ozon_importer-2024-10-30.log
│   └── wb_importer-2024-10-30.log
├── monitoring/        # Monitoring logs
│   └── monitoring-2024-10-30.log
├── errors-2024-10-30.log    # All errors
├── alerts-2024-10-30.log    # Alert history
└── archive/           # Compressed old logs
    ├── react-2024-10-29.log.1698675045.gz
    └── api-2024-10-28.log.1698588645.gz
```

## Alert Channels

### Email Alerts

Configure email alerts in environment variables:

```bash
export ALERT_EMAIL="admin@example.com,dev@example.com"
```

### Slack Alerts

1. Create a Slack webhook URL
2. Configure in environment:

```bash
export SLACK_WEBHOOK_URL="https://hooks.slack.com/services/YOUR/WEBHOOK/URL"
```

### Telegram Alerts

1. Create a Telegram bot with @BotFather
2. Get your chat ID
3. Configure in environment:

```bash
export TELEGRAM_BOT_TOKEN="YOUR_BOT_TOKEN"
export TELEGRAM_CHAT_ID="YOUR_CHAT_ID"
```

## Integration Examples

### React Frontend Integration

```typescript
import { useEffect } from 'react';

// Send error to logging endpoint
const logError = async (error: Error, context: any) => {
  try {
    await fetch('/api/comprehensive-error-logging.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Trace-ID': getTraceId()
      },
      body: JSON.stringify({
        level: 'error',
        message: error.message,
        component: 'frontend',
        context: context,
        source: 'react',
        stack: error.stack,
        traceId: getTraceId()
      })
    });
  } catch (e) {
    console.error('Failed to log error:', e);
  }
};

// Use in ErrorBoundary
componentDidCatch(error: Error, errorInfo: ErrorInfo) {
  logError(error, {
    componentStack: errorInfo.componentStack,
    url: window.location.href
  });
}
```

### PHP API Integration

```php
require_once 'api/classes/ErrorLogger.php';

$logger = new ErrorLogger();

try {
    // Your API logic
    $data = fetchInventoryData();
} catch (Exception $e) {
    $logger->error('Failed to fetch inventory', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'api');

    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
```

### Python ETL Integration

```python
from error_logger import get_logger

logger = get_logger('ozon_importer')

try:
    # ETL logic
    logger.log_etl_start('ozon_importer')
    process_data()
    logger.log_etl_end('ozon_importer', True, {'records': 100})
except Exception as e:
    logger.error(f'ETL failed: {str(e)}', exc_info=True)
    logger.log_etl_end('ozon_importer', False)
```

## Monitoring and Maintenance

### View Log Statistics

```bash
curl "https://market-mi.ru/api/log-viewer.php?action=stats&days=7"
```

### Search Logs

```bash
curl "https://market-mi.ru/api/log-viewer.php?action=search&query=error&days=7"
```

### View Recent Alerts

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=recent&limit=50"
```

### Test Alert Channels

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=test"
```

### Manual Log Rotation

```bash
./scripts/rotate_and_archive_logs.sh
```

### Archive Old Logs (Python)

```python
from error_logger import get_logger

logger = get_logger()
logger.archive_old_logs(days=30)
```

## Best Practices

1. **Use Appropriate Log Levels**

    - DEBUG: Development debugging only
    - INFO: Normal operations
    - WARNING: Potential issues
    - ERROR: Errors that need attention
    - CRITICAL: System-critical errors

2. **Include Context**

    - Always include relevant context in log messages
    - Use structured data in context field
    - Include trace IDs for request tracking

3. **Avoid Logging Sensitive Data**

    - Never log passwords, tokens, or API keys
    - Sanitize user data before logging
    - Use placeholders for sensitive information

4. **Monitor Log Size**

    - Configure appropriate rotation thresholds
    - Archive old logs regularly
    - Monitor disk space usage

5. **Set Up Alerts**

    - Configure alerts for critical errors
    - Use throttling to avoid alert fatigue
    - Test alert channels regularly

6. **Regular Maintenance**
    - Review logs weekly
    - Clean up old archives
    - Update alert rules as needed
    - Monitor alert effectiveness

## Troubleshooting

### Logs Not Being Created

1. Check directory permissions:

```bash
chmod 755 logs/
chmod 644 logs/*.log
```

2. Verify log path configuration
3. Check PHP/Python error logs

### Alerts Not Sending

1. Test alert channels:

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=test"
```

2. Verify webhook URLs and credentials
3. Check network connectivity
4. Review alert throttling settings

### Log Rotation Not Working

1. Verify cron job is configured:

```bash
crontab -l | grep rotate_and_archive_logs
```

2. Check script permissions:

```bash
chmod +x scripts/rotate_and_archive_logs.sh
```

3. Review rotation logs:

```bash
tail -f logs/log_rotation.log
```

### High Disk Usage

1. Check log sizes:

```bash
du -sh logs/*
```

2. Run manual rotation:

```bash
./scripts/rotate_and_archive_logs.sh
```

3. Adjust retention settings
4. Archive old logs to external storage

## Requirements Mapping

-   **Requirement 7.1**: React errors logged to server ✓
-   **Requirement 7.2**: ETL importers log progress and errors ✓
-   **Requirement 7.3**: API logs execution time and errors ✓
-   **Requirement 7.4**: Health check endpoints available ✓
-   **Requirement 7.5**: Performance analysis tools provided ✓

## Summary

The comprehensive error logging system provides:

✅ Centralized structured logging for all components
✅ Automatic log rotation and archiving
✅ Multi-channel alerting (Email, Slack, Telegram)
✅ Rule-based alert management
✅ Log viewing and search capabilities
✅ Performance monitoring
✅ Trace ID tracking for request correlation
✅ Automated maintenance with cron jobs

This system ensures that all errors are captured, logged, and appropriate teams are notified, enabling quick identification and resolution of issues.
