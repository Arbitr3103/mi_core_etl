# Error Logging System - Quick Start Guide

Get started with the comprehensive error logging system in 5 minutes.

## Quick Setup

### 1. Configure Environment Variables

Create or update your `.env` file:

```bash
# Log Configuration
LOG_PATH=/var/www/market-mi.ru/logs
LOG_LEVEL=info
LOG_MAX_SIZE=50MB
LOG_MAX_FILES=30

# Alert Configuration
ALERT_EMAIL=admin@example.com
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_CHAT_ID=your_chat_id
```

### 2. Set Up Log Directories

```bash
# Create log directories
mkdir -p logs/{frontend,api,etl,monitoring,archive}

# Set permissions
chmod 755 logs
chmod 755 logs/*
```

### 3. Set Up Automated Log Rotation

```bash
# Make scripts executable
chmod +x scripts/rotate_and_archive_logs.sh
chmod +x scripts/setup_log_rotation_cron.sh

# Set up cron job (runs daily at 2 AM)
./scripts/setup_log_rotation_cron.sh
```

### 4. Test the System

```bash
# Test PHP logging
curl -X POST https://market-mi.ru/api/comprehensive-error-logging.php \
  -H "Content-Type: application/json" \
  -d '{
    "level": "info",
    "message": "Test log message",
    "component": "test",
    "context": {"test": true}
  }'

# Test alert channels
curl https://market-mi.ru/api/alert-manager.php?action=test

# View log statistics
curl https://market-mi.ru/api/log-viewer.php?action=stats&days=7
```

## Usage Examples

### PHP API Logging

```php
require_once 'api/classes/ErrorLogger.php';

$logger = new ErrorLogger();

// Log different levels
$logger->info('User action', ['user_id' => 123], 'api');
$logger->warning('Slow query detected', ['duration' => 2.5], 'database');
$logger->error('API call failed', ['endpoint' => '/inventory'], 'api');
$logger->critical('Database connection lost', [], 'database');

// Log API calls
$start = microtime(true);
$response = callExternalAPI();
$duration = microtime(true) - $start;
$logger->logApiCall('/api/inventory', 'GET', ['limit' => 50], $response, $duration);
```

### Python ETL Logging

```python
from importers.error_logger import get_logger

# Initialize logger
logger = get_logger('my_importer', {
    'log_path': '../logs',
    'alerts': {
        'php_endpoint': 'https://market-mi.ru/api/comprehensive-error-logging.php'
    }
})

# Log ETL process
logger.log_etl_start('my_importer', {'source': 'api'})

try:
    # Your ETL logic
    records = process_data()
    logger.info(f'Processed {len(records)} records')
    logger.log_etl_end('my_importer', True, {'records': len(records)})
except Exception as e:
    logger.error(f'ETL failed: {str(e)}', exc_info=True)
    logger.log_etl_end('my_importer', False)
```

### React Frontend Logging

```typescript
// Send error to logging endpoint
const logError = async (level: string, message: string, context: any) => {
    await fetch("/api/comprehensive-error-logging.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            level,
            message,
            component: "frontend",
            context,
            source: "react",
        }),
    });
};

// Use in your components
try {
    await fetchData();
} catch (error) {
    logError("error", "Failed to fetch data", {
        error: error.message,
        url: window.location.href,
    });
}
```

## Viewing Logs

### Via API

```bash
# List log files
curl "https://market-mi.ru/api/log-viewer.php?action=files"

# Read specific log file
curl "https://market-mi.ru/api/log-viewer.php?action=read&file=api/api-2024-10-30.log&limit=100"

# Search logs
curl "https://market-mi.ru/api/log-viewer.php?action=search&query=error&days=7"

# Get statistics
curl "https://market-mi.ru/api/log-viewer.php?action=stats&days=7"

# Get error trends
curl "https://market-mi.ru/api/log-viewer.php?action=trends&days=7"
```

### Via Command Line

```bash
# View latest logs
tail -f logs/api/api-$(date +%Y-%m-%d).log

# View errors only
tail -f logs/errors-$(date +%Y-%m-%d).log

# Search for specific error
grep -r "error_message" logs/

# View compressed archive
zcat logs/archive/api-2024-10-29.log.gz | less
```

## Managing Alerts

### View Alert Rules

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=rules"
```

### Update Alert Rules

```bash
curl -X POST https://market-mi.ru/api/alert-manager.php \
  -H "Content-Type: application/json" \
  -d @config/alert_rules.json
```

### View Recent Alerts

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=recent&limit=50"
```

### Get Alert Statistics

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=stats&days=7"
```

### Test Alert Channels

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=test"
```

## Common Tasks

### Manual Log Rotation

```bash
./scripts/rotate_and_archive_logs.sh
```

### Archive Old Logs

```python
from importers.error_logger import get_logger

logger = get_logger()
logger.archive_old_logs(days=30)
```

### Clean Up Old Archives

```bash
# Remove archives older than 90 days
find logs/archive -name "*.gz" -mtime +90 -delete
```

### Monitor Disk Usage

```bash
# Check log directory size
du -sh logs/

# Check individual component sizes
du -sh logs/*/

# Check archive size
du -sh logs/archive/
```

## Troubleshooting

### Logs Not Appearing

1. Check permissions:

```bash
ls -la logs/
```

2. Check PHP error log:

```bash
tail -f /var/log/php-fpm/error.log
```

3. Test logging endpoint:

```bash
curl -X POST https://market-mi.ru/api/comprehensive-error-logging.php \
  -H "Content-Type: application/json" \
  -d '{"level":"info","message":"test","component":"test"}'
```

### Alerts Not Sending

1. Test alert channels:

```bash
curl "https://market-mi.ru/api/alert-manager.php?action=test"
```

2. Check webhook URLs in `.env`

3. Verify network connectivity

### Cron Job Not Running

1. Check cron job exists:

```bash
crontab -l
```

2. Check cron logs:

```bash
tail -f logs/log_rotation.log
```

3. Run manually to test:

```bash
./scripts/rotate_and_archive_logs.sh
```

## Next Steps

1. **Customize Alert Rules**: Edit `config/alert_rules.json` to match your needs
2. **Set Up Monitoring Dashboard**: Create a dashboard to visualize log data
3. **Integrate with Existing Tools**: Connect to your monitoring stack
4. **Review Logs Regularly**: Set up weekly log review process
5. **Optimize Retention**: Adjust log retention based on your needs

## Support

For detailed documentation, see:

-   [Complete Error Logging System Documentation](ERROR_LOGGING_SYSTEM.md)
-   [API Documentation](../api/README.md)
-   [ETL Documentation](../importers/README.md)

## Summary

You now have:

-   ✅ Centralized error logging
-   ✅ Automatic log rotation
-   ✅ Multi-channel alerting
-   ✅ Log viewing and search
-   ✅ Performance monitoring

The system is ready to capture and manage all errors across your warehouse dashboard!
