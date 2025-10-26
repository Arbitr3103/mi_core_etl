#!/bin/bash

# Ozon ETL System Deployment Script
# This script automates the deployment process for the Ozon ETL System

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="/opt/ozon-etl"
APP_USER="www-data"
BACKUP_DIR="/opt/ozon-etl/backups"
LOG_FILE="/tmp/ozon-etl-deploy.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
    exit 1
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

# Check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        error "This script must be run as root (use sudo)"
    fi
}

# Display usage information
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --environment ENV    Deployment environment (production|development|staging)"
    echo "  --app-dir DIR        Application directory (default: /opt/ozon-etl)"
    echo "  --app-user USER      Application user (default: www-data)"
    echo "  --skip-deps          Skip dependency installation"
    echo "  --skip-db            Skip database setup"
    echo "  --skip-cron          Skip cron job installation"
    echo "  --backup             Create backup before deployment"
    echo "  --help               Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 --environment production"
    echo "  $0 --environment development --app-dir /home/user/ozon-etl"
    echo "  $0 --environment production --backup --skip-deps"
}

# Parse command line arguments
parse_args() {
    ENVIRONMENT=""
    SKIP_DEPS=false
    SKIP_DB=false
    SKIP_CRON=false
    CREATE_BACKUP=false
    
    while [[ $# -gt 0 ]]; do
        case $1 in
            --environment)
                ENVIRONMENT="$2"
                shift 2
                ;;
            --app-dir)
                APP_DIR="$2"
                shift 2
                ;;
            --app-user)
                APP_USER="$2"
                shift 2
                ;;
            --skip-deps)
                SKIP_DEPS=true
                shift
                ;;
            --skip-db)
                SKIP_DB=true
                shift
                ;;
            --skip-cron)
                SKIP_CRON=true
                shift
                ;;
            --backup)
                CREATE_BACKUP=true
                shift
                ;;
            --help)
                usage
                exit 0
                ;;
            *)
                error "Unknown option: $1"
                ;;
        esac
    done
    
    if [[ -z "$ENVIRONMENT" ]]; then
        error "Environment must be specified (--environment production|development|staging)"
    fi
    
    if [[ ! "$ENVIRONMENT" =~ ^(production|development|staging)$ ]]; then
        error "Invalid environment: $ENVIRONMENT. Must be production, development, or staging"
    fi
}

# Check system requirements
check_requirements() {
    log "Checking system requirements..."
    
    # Check OS
    if [[ ! -f /etc/os-release ]]; then
        error "Cannot determine operating system"
    fi
    
    # Check PHP
    if ! command -v php &> /dev/null; then
        error "PHP is not installed"
    fi
    
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    log "Found PHP version: $PHP_VERSION"
    
    # Check required PHP extensions
    REQUIRED_EXTENSIONS=("pdo" "pdo_pgsql" "curl" "json" "mbstring")
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            error "Required PHP extension not found: $ext"
        fi
    done
    
    # Check PostgreSQL client
    if ! command -v psql &> /dev/null; then
        warning "PostgreSQL client (psql) not found. Database operations may fail."
    fi
    
    success "System requirements check passed"
}

# Install system dependencies
install_dependencies() {
    if [[ "$SKIP_DEPS" == true ]]; then
        log "Skipping dependency installation"
        return
    fi
    
    log "Installing system dependencies..."
    
    # Detect package manager
    if command -v apt-get &> /dev/null; then
        # Ubuntu/Debian
        apt-get update
        apt-get install -y php php-cli php-pdo php-pgsql php-curl php-json php-mbstring php-xml
        apt-get install -y postgresql-client cron
    elif command -v yum &> /dev/null; then
        # CentOS/RHEL
        yum update -y
        yum install -y php php-cli php-pdo php-pgsql php-curl php-json php-mbstring php-xml
        yum install -y postgresql cronie
        systemctl enable crond
        systemctl start crond
    else
        warning "Unknown package manager. Please install dependencies manually."
    fi
    
    success "Dependencies installed successfully"
}

# Create application user if it doesn't exist
create_app_user() {
    if ! id "$APP_USER" &>/dev/null; then
        log "Creating application user: $APP_USER"
        useradd -r -s /bin/false "$APP_USER"
    else
        log "Application user already exists: $APP_USER"
    fi
}

# Create backup of existing installation
create_backup() {
    if [[ "$CREATE_BACKUP" != true ]]; then
        return
    fi
    
    if [[ -d "$APP_DIR" ]]; then
        log "Creating backup of existing installation..."
        BACKUP_NAME="ozon-etl-backup-$(date +%Y%m%d-%H%M%S)"
        mkdir -p "$BACKUP_DIR"
        tar -czf "$BACKUP_DIR/$BACKUP_NAME.tar.gz" -C "$(dirname "$APP_DIR")" "$(basename "$APP_DIR")"
        success "Backup created: $BACKUP_DIR/$BACKUP_NAME.tar.gz"
    fi
}

# Deploy application files
deploy_application() {
    log "Deploying application files..."
    
    # Create application directory
    mkdir -p "$APP_DIR"
    
    # Copy application files
    SOURCE_DIR="$(dirname "$SCRIPT_DIR")"
    cp -r "$SOURCE_DIR"/* "$APP_DIR/"
    
    # Create necessary directories
    mkdir -p "$APP_DIR/Logs"
    mkdir -p "$APP_DIR/backups"
    mkdir -p "$APP_DIR/tmp"
    
    # Set proper ownership and permissions
    chown -R "$APP_USER:$APP_USER" "$APP_DIR"
    chmod -R 755 "$APP_DIR"
    chmod -R 777 "$APP_DIR/Logs"
    chmod -R 777 "$APP_DIR/backups"
    chmod -R 777 "$APP_DIR/tmp"
    
    # Make scripts executable
    chmod +x "$APP_DIR/Scripts"/*.php
    chmod +x "$APP_DIR/Scripts"/*.sh
    
    success "Application files deployed successfully"
}

# Setup configuration
setup_configuration() {
    log "Setting up configuration..."
    
    # Copy environment configuration template
    ENV_FILE="$APP_DIR/.env"
    TEMPLATE_FILE="$APP_DIR/Config/.env.$ENVIRONMENT.example"
    
    if [[ -f "$TEMPLATE_FILE" ]]; then
        cp "$TEMPLATE_FILE" "$ENV_FILE"
        chown "$APP_USER:$APP_USER" "$ENV_FILE"
        chmod 600 "$ENV_FILE"
        
        warning "Configuration template copied to $ENV_FILE"
        warning "Please edit $ENV_FILE with your actual configuration values"
    else
        error "Configuration template not found: $TEMPLATE_FILE"
    fi
    
    success "Configuration setup completed"
}

# Setup database
setup_database() {
    if [[ "$SKIP_DB" == true ]]; then
        log "Skipping database setup"
        return
    fi
    
    log "Setting up database..."
    
    # Check if .env file exists and has database configuration
    ENV_FILE="$APP_DIR/.env"
    if [[ ! -f "$ENV_FILE" ]]; then
        warning "Environment file not found. Skipping database setup."
        warning "Please configure database manually after editing $ENV_FILE"
        return
    fi
    
    # Source environment variables
    set -a
    source "$ENV_FILE"
    set +a
    
    # Check if database configuration is present
    if [[ -z "$PG_HOST" || -z "$PG_NAME" || -z "$PG_USER" || -z "$PG_PASSWORD" ]]; then
        warning "Database configuration incomplete. Skipping database setup."
        warning "Please configure database manually after editing $ENV_FILE"
        return
    fi
    
    # Test database connectivity
    if command -v psql &> /dev/null; then
        log "Testing database connectivity..."
        if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -U "$PG_USER" -d "$PG_NAME" -c "SELECT 1;" &> /dev/null; then
            log "Database connection successful"
            
            # Run migrations
            log "Running database migrations..."
            PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -U "$PG_USER" -d "$PG_NAME" -f "$APP_DIR/migrations/007_create_ozon_etl_schema.sql"
            success "Database migrations completed"
        else
            warning "Cannot connect to database. Please check configuration and run migrations manually."
        fi
    else
        warning "PostgreSQL client not available. Please run migrations manually."
    fi
}

# Install cron jobs
install_cron_jobs() {
    if [[ "$SKIP_CRON" == true ]]; then
        log "Skipping cron job installation"
        return
    fi
    
    log "Installing cron jobs..."
    
    # Use the scheduler installation script
    if [[ -f "$APP_DIR/Scripts/install_scheduler.php" ]]; then
        sudo -u "$APP_USER" php "$APP_DIR/Scripts/install_scheduler.php" --environment="$ENVIRONMENT"
        success "Cron jobs installed successfully"
    else
        warning "Scheduler installation script not found. Please install cron jobs manually."
    fi
}

# Run post-deployment tests
run_tests() {
    log "Running post-deployment tests..."
    
    # Run simplified E2E test
    if [[ -f "$APP_DIR/Tests/SimplifiedE2ETest.php" ]]; then
        if sudo -u "$APP_USER" php "$APP_DIR/Tests/SimplifiedE2ETest.php" &> /dev/null; then
            success "Post-deployment tests passed"
        else
            warning "Post-deployment tests failed. Please check configuration."
        fi
    else
        warning "Test file not found. Skipping tests."
    fi
}

# Display deployment summary
display_summary() {
    echo ""
    echo "=========================================="
    echo "  Ozon ETL System Deployment Summary"
    echo "=========================================="
    echo "Environment: $ENVIRONMENT"
    echo "Application Directory: $APP_DIR"
    echo "Application User: $APP_USER"
    echo "Log File: $LOG_FILE"
    echo ""
    echo "Next Steps:"
    echo "1. Edit configuration file: $APP_DIR/.env"
    echo "2. Verify database connection and run migrations if needed"
    echo "3. Test the system: sudo -u $APP_USER php $APP_DIR/Tests/SimplifiedE2ETest.php"
    echo "4. Monitor logs: tail -f $APP_DIR/Logs/ozon-etl.log"
    echo ""
    echo "For more information, see: $APP_DIR/DEPLOYMENT_GUIDE.md"
    echo "=========================================="
}

# Main deployment function
main() {
    log "Starting Ozon ETL System deployment..."
    
    check_root
    parse_args "$@"
    check_requirements
    install_dependencies
    create_app_user
    create_backup
    deploy_application
    setup_configuration
    setup_database
    install_cron_jobs
    run_tests
    
    success "Deployment completed successfully!"
    display_summary
}

# Run main function with all arguments
main "$@"