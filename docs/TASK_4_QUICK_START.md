# Task 4 Quick Start Guide

## Overview

This guide helps you get started with the new MDM-enhanced dashboards and APIs.

## Prerequisites

1. Database with `product_cross_reference` table (run Task 1 migration)
2. Web server (Apache/Nginx) configured
3. PHP 7.4+ with PDO MySQL extension

## Quick Start

### 1. Access the MDM Dashboard

```
http://your-server/html/dashboard_mdm_enhanced.php
```

**Features:**

- View all products with real names
- See data quality indicators (High/Medium/Low)
- Check sync status (Synced/Pending/Failed)
- Manually sync individual products
- Auto-refresh every 30 seconds

### 2. Access the Sync Monitor

```
http://your-server/html/sync_monitor_dashboard.php
```

**Features:**

- Real-time sync statistics
- Health status indicator
- Failed products alerts
- Manual sync controls
- Retry failed products

### 3. Use the Enhanced APIs

**Get Analytics Overview:**

```bash
curl http://your-server/api/analytics-enhanced.php?action=overview
```

**Get Sync Status:**

```bash
curl http://your-server/api/sync-monitor.php?action=status
```

**Trigger Manual Sync:**

```bash
curl -X POST http://your-server/api/sync-trigger.php \
  -H "Content-Type: application/json" \
  -d '{"action":"sync_all"}'
```

## Testing

Run the implementation test:

```bash
php tests/test_task4_implementation.php
```

## Troubleshooting

**Issue: Table doesn't exist**

- Run: `php create_product_cross_reference_table.sql`

**Issue: No data showing**

- Run sync script: `php sync-real-product-names-v2.php`

**Issue: API errors**

- Check config.php database credentials
- Verify web server PHP configuration

## Next Steps

- Populate cross-reference table (Task 1)
- Run synchronization (Task 3)
- Monitor data quality (Task 4)
