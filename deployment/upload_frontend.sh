#!/bin/bash

# Quick script to upload built frontend to server

set -e

echo "=========================================="
echo "Upload Frontend to Production Server"
echo "=========================================="
echo ""

# Configuration
SERVER="vladimir@178.72.129.61"
REMOTE_DIR="/var/www/mi_core_etl_new/public/build"
LOCAL_BUILD="frontend/dist"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Check if build exists
if [ ! -d "$LOCAL_BUILD" ]; then
    print_error "Build directory not found: $LOCAL_BUILD"
    echo "Please run 'npm run build' in the frontend directory first"
    exit 1
fi

print_success "Found build directory"

# Create tarball
echo "Creating tarball..."
cd frontend
tar -czf ../frontend-build.tar.gz dist/
cd ..
print_success "Tarball created: frontend-build.tar.gz"

# Upload to server
echo "Uploading to server..."
scp frontend-build.tar.gz "$SERVER:/tmp/"
print_success "Uploaded to server"

# Extract on server
echo "Extracting on server..."
ssh "$SERVER" << 'ENDSSH'
cd /var/www/mi_core_etl_new
echo "qwert1234" | sudo -S mkdir -p public/build
cd public/build
echo "qwert1234" | sudo -S tar -xzf /tmp/frontend-build.tar.gz --strip-components=1
echo "qwert1234" | sudo -S chown -R www-data:www-data .
echo "qwert1234" | sudo -S chmod -R 755 .
rm /tmp/frontend-build.tar.gz
ENDSSH

print_success "Frontend deployed to server"

# Cleanup
rm frontend-build.tar.gz
print_success "Local tarball cleaned up"

echo ""
echo "=========================================="
echo "Frontend Upload Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "  1. Configure Nginx (see FINAL_DEPLOYMENT_GUIDE.md)"
echo "  2. Test: http://178.72.129.61:8080"
echo ""
