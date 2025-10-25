# Server Deployment Guide - Analytics ETL System

## 🚀 Quick Start

### For Full Deployment (first time or major updates):

```bash
sudo ./deploy_server_update.sh
```

### For Quick Updates (code changes only):

```bash
sudo ./quick_update.sh
```

## 📋 Prerequisites

1. **Server Setup:**

    - Web server (Nginx/Apache) running
    - PHP 8.0+ with required extensions
    - PostgreSQL database
    - Git repository access

2. **Users Configuration:**

    - `vladimir` - maintenance user with git access
    - `www-data` (or nginx/apache) - web server user

3. **Directory Structure:**
    ```
    /var/www/html/mi_core_etl/  # Main project directory
    ├── logs/analytics_etl/     # ETL logs (vladimir:www-data)
    ├── cache/analytics_api/    # API cache (vladimir:www-data)
    └── storage/temp/           # Temporary files (vladimir:www-data)
    ```

## 🔧 Configuration Before First Run

### 1. Edit Deployment Scripts

**Edit `deploy_server_update.sh`:**

```bash
# Update these variables for your server:
PROJECT_DIR="/var/www/html/mi_core_etl"  # Your project path
VLADIMIR_USER="vladimir"                  # Your maintenance user
APP_USER="www-data"                      # Your web server user
APP_GROUP="www-data"                     # Your web server group
```

**Edit `quick_update.sh`:**

```bash
# Update the same variables as above
PROJECT_DIR="/var/www/html/mi_core_etl"
VLADIMIR_USER="vladimir"
APP_USER="www-data"
APP_GROUP="www-data"
```

### 2. Set Up SSH Keys (if using private repository)

```bash
# As vladimir user:
sudo -u vladimir ssh-keygen -t rsa -b 4096 -C "vladimir@yourserver.com"
sudo -u vladimir cat /home/vladimir/.ssh/id_rsa.pub
# Add this key to your GitHub repository
```

### 3. Initial Repository Clone

```bash
# As vladimir user:
cd /var/www/html/
sudo -u vladimir git clone https://github.com/Arbitr3103/mi_core_etl.git
sudo chown -R www-data:www-data mi_core_etl/
```

## 📖 Deployment Process Details

### Full Deployment (`deploy_server_update.sh`) includes:

1. **Ownership Management** - Changes to vladimir for git operations
2. **Git Pull** - Updates code from repository
3. **Database Migrations** - Runs new database changes
4. **Composer Update** - Updates PHP dependencies
5. **Frontend Build** - Builds React/TypeScript frontend
6. **Permissions Setup** - Sets correct file permissions
7. **Ownership Restore** - Returns ownership to web server
8. **Service Restart** - Reloads web server and PHP-FPM
9. **Smoke Tests** - Verifies deployment success
10. **Cron Jobs Check** - Reviews scheduled tasks

### Quick Update (`quick_update.sh`) includes:

1. **Ownership Change** - vladimir for git
2. **Git Pull** - Latest code changes
3. **Ownership Restore** - Back to web server
4. **Maintenance Access** - Keeps vladimir access to logs/cache

## 🔐 Security & Permissions

### File Ownership Strategy:

-   **Web files**: `www-data:www-data` (web server access)
-   **Logs/Cache**: `vladimir:www-data` (maintenance + web server)
-   **Git repository**: Temporarily `vladimir` during updates

### Directory Permissions:

-   **Application files**: `755` (read/execute for all, write for owner)
-   **Logs/Cache/Storage**: `775` (group write access for maintenance)
-   **Executable scripts**: `755` (executable permissions)

## 🔄 Typical Deployment Workflow

### 1. Development Changes

```bash
# On development machine:
git add .
git commit -m "feat: new feature"
git push origin main
```

### 2. Server Update

```bash
# On server:
sudo ./quick_update.sh
```

### 3. Major Updates (with migrations/dependencies)

```bash
# On server:
sudo ./deploy_server_update.sh
```

## 🚨 Troubleshooting

### Common Issues:

**1. Permission Denied on Git Pull:**

```bash
# Fix: Ensure vladimir has git access
sudo -u vladimir git status
sudo -u vladimir git pull origin main
```

**2. Web Server Can't Access Files:**

```bash
# Fix: Restore proper ownership
sudo chown -R www-data:www-data /var/www/html/mi_core_etl/
```

**3. Logs Not Writable:**

```bash
# Fix: Set proper permissions
sudo chown -R vladimir:www-data logs/
sudo chmod -R g+w logs/
```

**4. Database Connection Issues:**

```bash
# Check database configuration
php -r "include 'config/database.php'; echo 'DB connection OK';"
```

### Emergency Rollback:

```bash
# If deployment fails, rollback to previous commit:
sudo chown -R vladimir:vladimir /var/www/html/mi_core_etl/
cd /var/www/html/mi_core_etl/
sudo -u vladimir git log --oneline -5  # Find previous commit
sudo -u vladimir git reset --hard COMMIT_HASH
sudo chown -R www-data:www-data /var/www/html/mi_core_etl/
```

## 📊 Post-Deployment Verification

### 1. Check Application Status:

```bash
curl -I http://your-domain.com
curl http://your-domain.com/api/health
```

### 2. Verify ETL System:

```bash
php monitor_analytics_etl.php
php analytics_etl_smoke_tests.php
```

### 3. Check Logs:

```bash
tail -f logs/analytics_etl/etl_$(date +%Y%m%d).log
tail -f /var/log/nginx/error.log  # or apache error log
```

### 4. Test Database Connection:

```bash
php -r "
require_once 'config/database.php';
try {
    \$pdo = new PDO(\$dsn, \$username, \$password);
    echo 'Database connection: OK\n';
} catch (Exception \$e) {
    echo 'Database error: ' . \$e->getMessage() . '\n';
}
"
```

## 🔄 Automated Deployment (Optional)

### Set up webhook for automatic deployment:

```bash
# Create webhook script
sudo nano /var/www/webhook_deploy.php

# Add to web server configuration
# When GitHub webhook triggers, run quick_update.sh
```

## 📅 Maintenance Schedule

### Daily:

-   Monitor ETL logs
-   Check system resources

### Weekly:

-   Review error logs
-   Update dependencies if needed

### Monthly:

-   Full system backup
-   Security updates
-   Performance review

---

## 🆘 Support

If you encounter issues:

1. Check the troubleshooting section above
2. Review logs in `logs/analytics_etl/`
3. Verify file permissions and ownership
4. Test database connectivity
5. Check web server error logs

**Remember**: Always test deployment scripts on staging environment first!
