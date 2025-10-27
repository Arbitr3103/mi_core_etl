# Warehouse Dashboard Deployment Scripts

This directory contains deployment scripts for the warehouse dashboard redesign project.

## Scripts Overview

### 1. Backend Deployment

**Script:** `deploy_warehouse_dashboard_backend.sh`

Deploys backend API changes including:

-   Database view (`v_detailed_inventory`)
-   Performance indexes
-   API classes and endpoints
-   Cache configuration
-   Monitoring setup

**Usage:**

```bash
./deploy_warehouse_dashboard_backend.sh
```

**Environment Variables:**

-   `DEPLOY_USER`: SSH user (default: root)
-   `DEPLOY_HOST`: Server hostname (default: market-mi.ru)
-   `DEPLOY_PATH`: Server path (default: /var/www/market-mi.ru)
-   `BACKUP_DIR`: Backup directory (default: /var/www/market-mi.ru/backups)

### 2. Frontend Deployment

**Script:** `deploy_warehouse_dashboard_frontend.sh`

Deploys frontend React application including:

-   Build and test frontend
-   Upload build files
-   Feature flag configuration
-   Dashboard switcher
-   Error and performance monitoring

**Usage:**

```bash
./deploy_warehouse_dashboard_frontend.sh
```

**Environment Variables:** Same as backend deployment

### 3. Gradual Rollout Management

**Script:** `deploy_warehouse_dashboard_rollout.sh`

Interactive script for managing gradual rollout:

-   Enable for test users only
-   Gradual percentage increases (5%, 10%, 25%, 50%, 75%, 100%)
-   Health checks before each phase
-   Monitoring guidance

**Usage:**

```bash
./deploy_warehouse_dashboard_rollout.sh
```

**Menu Options:**

1. Show current status
2. Enable for test users only (0% rollout)
3. Start gradual rollout (5%)
4. Increase rollout to 10%
5. Increase rollout to 25%
6. Increase rollout to 50%
7. Increase rollout to 75%
8. Full rollout (100%)
9. Disable new dashboard
10. Exit

### 4. Rollback

**Script:** `rollback_warehouse_dashboard.sh`

Emergency rollback procedure:

-   Disable feature flag
-   Restore backend files (optional)
-   Rollback database changes (optional)
-   Clear cache
-   Restart services

**Usage:**

```bash
./rollback_warehouse_dashboard.sh
```

## Deployment Workflow

### Standard Deployment

1. **Pre-deployment checks**

    ```bash
    # Verify tests pass
    cd frontend && npm run test

    # Verify type checking
    npm run type-check

    # Verify linting
    npm run lint
    ```

2. **Deploy backend**

    ```bash
    cd deployment/scripts
    ./deploy_warehouse_dashboard_backend.sh
    ```

3. **Deploy frontend**

    ```bash
    ./deploy_warehouse_dashboard_frontend.sh
    ```

4. **Start gradual rollout**

    ```bash
    ./deploy_warehouse_dashboard_rollout.sh
    # Select option 2 (test users only)
    ```

5. **Monitor and increase rollout**
    ```bash
    # After 24-48 hours of monitoring
    ./deploy_warehouse_dashboard_rollout.sh
    # Select next percentage option
    ```

### Emergency Rollback

```bash
cd deployment/scripts
./rollback_warehouse_dashboard.sh
```

## Configuration

### Server Configuration

Set environment variables before running scripts:

```bash
export DEPLOY_USER="your_user"
export DEPLOY_HOST="your_server.com"
export DEPLOY_PATH="/var/www/your_path"
export BACKUP_DIR="/var/www/backups"
```

Or create a `.env` file:

```bash
# deployment/scripts/.env
DEPLOY_USER=your_user
DEPLOY_HOST=your_server.com
DEPLOY_PATH=/var/www/your_path
BACKUP_DIR=/var/www/backups
```

Then source it:

```bash
source .env
./deploy_warehouse_dashboard_backend.sh
```

### SSH Configuration

Ensure SSH key-based authentication is set up:

```bash
# Generate SSH key if needed
ssh-keygen -t rsa -b 4096

# Copy to server
ssh-copy-id user@server.com

# Test connection
ssh user@server.com "echo 'Connection successful'"
```

## Monitoring

### View Logs

```bash
# Frontend error logs
ssh user@server 'tail -f /var/www/market-mi.ru/logs/frontend/errors_*.log'

# Frontend performance logs
ssh user@server 'tail -f /var/www/market-mi.ru/logs/frontend/performance_*.log'

# API logs
ssh user@server 'tail -f /var/www/market-mi.ru/logs/api/*.log'
```

### Check API Health

```bash
# Summary endpoint
curl https://market-mi.ru/api/inventory/detailed-stock?action=summary

# Cache statistics
curl https://market-mi.ru/api/inventory/detailed-stock?action=cache-stats

# Warehouses list
curl https://market-mi.ru/api/inventory/detailed-stock?action=warehouses
```

### Monitor System Resources

```bash
# CPU and memory
ssh user@server 'htop'

# Disk usage
ssh user@server 'df -h'

# Database connections
ssh user@server 'psql -c "SELECT count(*) FROM pg_stat_activity;"'
```

## Troubleshooting

### Script Fails with Permission Error

```bash
# Make scripts executable
chmod +x *.sh

# Or run with bash
bash deploy_warehouse_dashboard_backend.sh
```

### SSH Connection Fails

```bash
# Test SSH connection
ssh -v user@server.com

# Check SSH config
cat ~/.ssh/config

# Verify SSH key
ssh-add -l
```

### Deployment Fails Midway

```bash
# Check what was deployed
ssh user@server 'ls -la /var/www/market-mi.ru/api/inventory/'

# Check logs
ssh user@server 'tail -100 /var/log/nginx/error.log'

# Rollback if needed
./rollback_warehouse_dashboard.sh
```

### Feature Flag Not Working

```bash
# Check config file
ssh user@server 'cat /var/www/market-mi.ru/warehouse-dashboard/config.js'

# Verify file permissions
ssh user@server 'ls -la /var/www/market-mi.ru/warehouse-dashboard/config.js'

# Clear browser cache
# Hard refresh: Ctrl+Shift+R (Cmd+Shift+R on Mac)
```

## Best Practices

1. **Always backup before deployment**

    - Scripts create automatic backups
    - Verify backups are created successfully
    - Test backup restoration periodically

2. **Deploy during low-traffic periods**

    - Schedule deployments for off-peak hours
    - Notify users of potential downtime
    - Have rollback plan ready

3. **Monitor closely during rollout**

    - Check error logs every 2 hours initially
    - Monitor performance metrics continuously
    - Respond quickly to issues

4. **Gradual rollout is key**

    - Don't rush through phases
    - Wait 24-48 hours between increases
    - Be prepared to pause or rollback

5. **Document everything**
    - Keep deployment logs
    - Document any issues encountered
    - Update runbooks with learnings

## Support

For issues or questions:

-   **Technical Support:** [email]
-   **DevOps Team:** [email]
-   **On-Call:** [phone]

## Related Documentation

-   [Deployment Guide](../../.kiro/specs/warehouse-dashboard-redesign/DEPLOYMENT_GUIDE.md)
-   [User Guide](../../.kiro/specs/warehouse-dashboard-redesign/USER_GUIDE.md)
-   [API Migration Notes](../../.kiro/specs/warehouse-dashboard-redesign/API_MIGRATION_NOTES.md)
-   [Deployment Checklist](../../.kiro/specs/warehouse-dashboard-redesign/DEPLOYMENT_CHECKLIST.md)

---

**Last Updated:** October 27, 2025  
**Version:** 1.0.0
