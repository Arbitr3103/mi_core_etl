#!/bin/bash

# Ozon Analytics Deployment Verification Script
# 
# This script verifies that the Ozon Analytics integration has been
# deployed correctly and is ready for production use.
#
# Usage: ./run_deployment_verification.sh
#
# @version 1.0
# @author Manhattan System

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/tmp/ozon_deployment_verification_$(date +%Y%m%d_%H%M%S).log"
ERRORS=0
WARNINGS=0

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$LOG_FILE"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
    ((WARNINGS++))
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
    ((ERRORS++))
}

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check file exists and is readable
check_file() {
    local file="$1"
    local description="$2"
    
    if [[ -f "$file" && -r "$file" ]]; then
        log_success "$description: $file"
        return 0
    else
        log_error "$description not found or not readable: $file"
        return 1
    fi
}

# Check directory exists and is readable
check_directory() {
    local dir="$1"
    local description="$2"
    
    if [[ -d "$dir" && -r "$dir" ]]; then
        log_success "$description: $dir"
        return 0
    else
        log_error "$description not found or not readable: $dir"
        return 1
    fi
}

# Check PHP extension
check_php_extension() {
    local extension="$1"
    
    if php -m | grep -q "^$extension$"; then
        log_success "PHP extension $extension is installed"
        return 0
    else
        log_error "PHP extension $extension is not installed"
        return 1
    fi
}

# Check database table
check_database_table() {
    local table="$1"
    local db_config="$2"
    
    if mysql $db_config -e "DESCRIBE $table;" >/dev/null 2>&1; then
        log_success "Database table $table exists"
        return 0
    else
        log_error "Database table $table does not exist"
        return 1
    fi
}

# Main verification function
main() {
    log_info "🚀 Starting Ozon Analytics Deployment Verification"
    log_info "Timestamp: $(date)"
    log_info "Script: $0"
    log_info "Log file: $LOG_FILE"
    echo ""

    # 1. System Requirements Check
    log_info "📋 Step 1: Checking System Requirements"
    echo "----------------------------------------"
    
    # Check PHP version
    if command_exists php; then
        PHP_VERSION=$(php -r "echo PHP_VERSION;")
        if php -r "exit(version_compare(PHP_VERSION, '7.4.0', '>=') ? 0 : 1);"; then
            log_success "PHP version: $PHP_VERSION (>= 7.4.0)"
        else
            log_error "PHP version $PHP_VERSION is too old (requires >= 7.4.0)"
        fi
    else
        log_error "PHP is not installed or not in PATH"
    fi
    
    # Check MySQL
    if command_exists mysql; then
        MYSQL_VERSION=$(mysql --version | awk '{print $5}' | sed 's/,//')
        log_success "MySQL client version: $MYSQL_VERSION"
    else
        log_error "MySQL client is not installed or not in PATH"
    fi
    
    # Check required PHP extensions
    log_info "Checking PHP extensions..."
    check_php_extension "curl"
    check_php_extension "json"
    check_php_extension "pdo"
    check_php_extension "pdo_mysql"
    check_php_extension "mbstring"
    check_php_extension "openssl"
    
    echo ""

    # 2. File Structure Check
    log_info "📁 Step 2: Checking File Structure"
    echo "-----------------------------------"
    
    # Core PHP classes
    check_file "src/classes/OzonAnalyticsAPI.php" "OzonAnalyticsAPI class"
    check_file "src/classes/OzonDataCache.php" "OzonDataCache class"
    check_file "src/classes/OzonSecurityManager.php" "OzonSecurityManager class"
    check_file "src/classes/OzonSecurityMiddleware.php" "OzonSecurityMiddleware class"
    
    # API endpoints
    check_file "src/api/ozon-analytics.php" "Ozon Analytics API endpoint"
    
    # JavaScript components
    check_file "src/js/OzonFunnelChart.js" "OzonFunnelChart JavaScript"
    check_file "src/js/OzonAnalyticsIntegration.js" "OzonAnalyticsIntegration JavaScript"
    check_file "src/js/OzonDemographics.js" "OzonDemographics JavaScript"
    check_file "src/js/OzonExportManager.js" "OzonExportManager JavaScript"
    check_file "src/js/OzonSettingsManager.js" "OzonSettingsManager JavaScript"
    
    # CSS files
    check_file "src/css/ozon-performance.css" "Ozon Analytics CSS"
    
    # Migration files
    check_directory "migrations" "Migrations directory"
    check_file "migrations/add_ozon_analytics_tables.sql" "Main migration file"
    
    # Documentation
    check_directory "docs" "Documentation directory"
    check_file "docs/OZON_ANALYTICS_USER_GUIDE.md" "User guide"
    check_file "docs/OZON_ANALYTICS_TECHNICAL_GUIDE.md" "Technical guide"
    check_file "docs/OZON_ANALYTICS_DEPLOYMENT_GUIDE.md" "Deployment guide"
    
    # Test files
    check_directory "tests" "Tests directory"
    check_file "tests/OzonAnalyticsIntegrationTest.php" "Integration tests"
    check_file "tests/OzonDataValidationTest.php" "Data validation tests"
    
    echo ""

    # 3. Database Check
    log_info "🗄️  Step 3: Checking Database"
    echo "-----------------------------"
    
    # Try to read database configuration
    if [[ -f ".env" ]]; then
        log_success "Environment file found: .env"
        
        # Extract database configuration
        DB_HOST=$(grep "^DB_HOST=" .env | cut -d'=' -f2 | tr -d '"')
        DB_NAME=$(grep "^DB_NAME=" .env | cut -d'=' -f2 | tr -d '"')
        DB_USER=$(grep "^DB_USER=" .env | cut -d'=' -f2 | tr -d '"')
        DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d'=' -f2 | tr -d '"')
        
        if [[ -n "$DB_HOST" && -n "$DB_NAME" && -n "$DB_USER" ]]; then
            log_success "Database configuration found in .env"
            
            # Test database connection
            DB_CONFIG="-h$DB_HOST -u$DB_USER"
            if [[ -n "$DB_PASSWORD" ]]; then
                DB_CONFIG="$DB_CONFIG -p$DB_PASSWORD"
            fi
            DB_CONFIG="$DB_CONFIG $DB_NAME"
            
            if mysql $DB_CONFIG -e "SELECT 1;" >/dev/null 2>&1; then
                log_success "Database connection successful"
                
                # Check required tables
                check_database_table "ozon_api_settings" "$DB_CONFIG"
                check_database_table "ozon_funnel_data" "$DB_CONFIG"
                check_database_table "ozon_demographics" "$DB_CONFIG"
                check_database_table "ozon_campaigns" "$DB_CONFIG"
                
            else
                log_error "Cannot connect to database"
            fi
        else
            log_error "Incomplete database configuration in .env"
        fi
    else
        log_warning "Environment file .env not found"
    fi
    
    echo ""

    # 4. Web Server Check
    log_info "🌐 Step 4: Checking Web Server Configuration"
    echo "---------------------------------------------"
    
    # Check if Apache or Nginx is running
    if pgrep apache2 >/dev/null || pgrep httpd >/dev/null; then
        log_success "Apache web server is running"
        
        # Check Apache configuration
        if [[ -f "/etc/apache2/sites-available/manhattan.conf" ]]; then
            log_success "Apache configuration file found"
        else
            log_warning "Apache configuration file not found at expected location"
        fi
        
    elif pgrep nginx >/dev/null; then
        log_success "Nginx web server is running"
        
        # Check Nginx configuration
        if [[ -f "/etc/nginx/sites-available/manhattan" ]]; then
            log_success "Nginx configuration file found"
        else
            log_warning "Nginx configuration file not found at expected location"
        fi
        
    else
        log_warning "No web server (Apache/Nginx) appears to be running"
    fi
    
    # Check if the main dashboard is accessible
    if command_exists curl; then
        DASHBOARD_URL="http://localhost/dashboard_marketplace_enhanced.php"
        if curl -s -f "$DASHBOARD_URL" >/dev/null; then
            log_success "Main dashboard is accessible"
        else
            log_warning "Main dashboard is not accessible at $DASHBOARD_URL"
        fi
        
        # Check API endpoint
        API_URL="http://localhost/src/api/ozon-analytics.php?action=health"
        if curl -s -f "$API_URL" >/dev/null; then
            log_success "Ozon Analytics API endpoint is accessible"
        else
            log_warning "Ozon Analytics API endpoint is not accessible at $API_URL"
        fi
    else
        log_warning "curl not available, cannot test web accessibility"
    fi
    
    echo ""

    # 5. Permissions Check
    log_info "🔐 Step 5: Checking File Permissions"
    echo "------------------------------------"
    
    # Check web server user (common names)
    WEB_USER=""
    for user in www-data apache nginx; do
        if id "$user" >/dev/null 2>&1; then
            WEB_USER="$user"
            break
        fi
    done
    
    if [[ -n "$WEB_USER" ]]; then
        log_success "Web server user found: $WEB_USER"
        
        # Check if web server can read key files
        if [[ -r "src/classes/OzonAnalyticsAPI.php" ]]; then
            log_success "OzonAnalyticsAPI.php is readable"
        else
            log_error "OzonAnalyticsAPI.php is not readable"
        fi
        
        if [[ -r "src/api/ozon-analytics.php" ]]; then
            log_success "ozon-analytics.php is readable"
        else
            log_error "ozon-analytics.php is not readable"
        fi
        
    else
        log_warning "Could not determine web server user"
    fi
    
    # Check .env file permissions
    if [[ -f ".env" ]]; then
        ENV_PERMS=$(stat -c "%a" .env 2>/dev/null || stat -f "%A" .env 2>/dev/null)
        if [[ "$ENV_PERMS" == "600" ]]; then
            log_success ".env file has secure permissions (600)"
        else
            log_warning ".env file permissions are $ENV_PERMS (should be 600)"
        fi
    fi
    
    echo ""

    # 6. Cron Jobs Check
    log_info "⏰ Step 6: Checking Scheduled Tasks"
    echo "-----------------------------------"
    
    # Check if cron is running
    if pgrep cron >/dev/null || pgrep crond >/dev/null; then
        log_success "Cron daemon is running"
        
        # Check for Ozon-related cron jobs
        if crontab -l 2>/dev/null | grep -q "ozon"; then
            log_success "Ozon-related cron jobs found"
            crontab -l 2>/dev/null | grep "ozon" | while read -r line; do
                log_info "  Cron job: $line"
            done
        else
            log_warning "No Ozon-related cron jobs found"
        fi
    else
        log_warning "Cron daemon is not running"
    fi
    
    echo ""

    # 7. Run Tests
    log_info "🧪 Step 7: Running Automated Tests"
    echo "----------------------------------"
    
    if [[ -f "run_ozon_analytics_tests.php" ]]; then
        log_info "Running automated test suite..."
        
        if php run_ozon_analytics_tests.php --test-type=integration >/dev/null 2>&1; then
            log_success "Integration tests passed"
        else
            log_error "Integration tests failed"
        fi
        
        if php run_ozon_analytics_tests.php --test-type=validation >/dev/null 2>&1; then
            log_success "Data validation tests passed"
        else
            log_error "Data validation tests failed"
        fi
        
    else
        log_warning "Test runner not found, skipping automated tests"
    fi
    
    echo ""

    # 8. Security Check
    log_info "🔒 Step 8: Security Verification"
    echo "--------------------------------"
    
    # Check for sensitive files exposure
    SENSITIVE_FILES=(".env" "config.php" "migrations/" "tests/")
    
    for file in "${SENSITIVE_FILES[@]}"; do
        if command_exists curl; then
            if curl -s -f "http://localhost/$file" >/dev/null 2>&1; then
                log_error "Sensitive file/directory is publicly accessible: $file"
            else
                log_success "Sensitive file/directory is protected: $file"
            fi
        fi
    done
    
    # Check for default credentials
    if [[ -f ".env" ]]; then
        if grep -q "test_client_id\|test_api_key\|default_password" .env; then
            log_error "Default/test credentials found in .env file"
        else
            log_success "No default credentials found in .env file"
        fi
    fi
    
    echo ""

    # Final Summary
    log_info "📊 Deployment Verification Summary"
    echo "=================================="
    
    if [[ $ERRORS -eq 0 && $WARNINGS -eq 0 ]]; then
        log_success "🎉 DEPLOYMENT VERIFICATION PASSED!"
        log_success "All checks completed successfully. The system is ready for production."
    elif [[ $ERRORS -eq 0 ]]; then
        log_warning "⚠️  DEPLOYMENT VERIFICATION COMPLETED WITH WARNINGS"
        log_warning "Found $WARNINGS warning(s). Please review and address if necessary."
        log_warning "The system should work but may have minor issues."
    else
        log_error "❌ DEPLOYMENT VERIFICATION FAILED"
        log_error "Found $ERRORS error(s) and $WARNINGS warning(s)."
        log_error "Please fix the errors before using the system in production."
    fi
    
    echo ""
    log_info "Detailed log saved to: $LOG_FILE"
    
    # Exit with appropriate code
    if [[ $ERRORS -eq 0 ]]; then
        exit 0
    else
        exit 1
    fi
}

# Run main function
main "$@"