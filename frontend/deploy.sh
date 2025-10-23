#!/bin/bash

# React Frontend Deployment Script
# This script builds and deploys the React application to the server

set -e  # Exit on error

echo "=========================================="
echo "React Frontend Deployment Script"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SERVER_USER="${DEPLOY_USER:-root}"
SERVER_HOST="${DEPLOY_HOST:-178.72.129.61}"
SERVER_PATH="${DEPLOY_PATH:-/var/www/mi_core_etl/public/dashboard}"
BACKUP_DIR="${BACKUP_DIR:-/var/www/mi_core_etl/backups/frontend}"

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Function to print colored output
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

# Check if SSH connection is available
print_info "Checking SSH connection to $SERVER_USER@$SERVER_HOST..."
if ! ssh -o ConnectTimeout=5 "$SERVER_USER@$SERVER_HOST" "echo 'Connection successful'" > /dev/null 2>&1; then
    print_error "Cannot connect to server. Please check your SSH configuration."
    exit 1
fi
print_status "SSH connection successful"

# Build the application
print_info "Building React application..."
./build.sh || {
    print_error "Build failed!"
    exit 1
}

# Create backup on server
print_info "Creating backup on server..."
ssh "$SERVER_USER@$SERVER_HOST" "
    if [ -d '$SERVER_PATH' ]; then
        mkdir -p '$BACKUP_DIR'
        BACKUP_NAME='frontend_backup_\$(date +%Y%m%d_%H%M%S)'
        cp -r '$SERVER_PATH' '$BACKUP_DIR/\$BACKUP_NAME'
        echo 'Backup created: \$BACKUP_NAME'
    else
        echo 'No existing deployment found, skipping backup'
    fi
"
print_status "Backup completed"

# Create deployment directory on server
print_info "Preparing deployment directory..."
ssh "$SERVER_USER@$SERVER_HOST" "mkdir -p '$SERVER_PATH'"

# Upload build files
print_info "Uploading build files to server..."
rsync -avz --delete \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='.env*' \
    dist/ "$SERVER_USER@$SERVER_HOST:$SERVER_PATH/"

print_status "Files uploaded successfully"

# Set proper permissions
print_info "Setting file permissions..."
ssh "$SERVER_USER@$SERVER_HOST" "
    chown -R www-data:www-data '$SERVER_PATH'
    find '$SERVER_PATH' -type d -exec chmod 755 {} \;
    find '$SERVER_PATH' -type f -exec chmod 644 {} \;
"
print_status "Permissions set"

# Verify deployment
print_info "Verifying deployment..."
ssh "$SERVER_USER@$SERVER_HOST" "
    if [ -f '$SERVER_PATH/index.html' ]; then
        echo 'Deployment verified: index.html found'
    else
        echo 'ERROR: index.html not found!'
        exit 1
    fi
"
print_status "Deployment verified"

# Display deployment info
echo ""
echo "=========================================="
echo "Deployment Summary"
echo "=========================================="
echo "Server: $SERVER_USER@$SERVER_HOST"
echo "Path: $SERVER_PATH"
echo "Build Date: $(date)"
echo ""
print_status "Deployment completed successfully!"
echo ""
echo "Access your application at:"
echo "  http://$SERVER_HOST/dashboard/"
echo ""
echo "=========================================="
