#!/bin/bash

# HTTPS Security Configuration Script
# This script configures advanced HTTPS security settings

set -e  # Exit on error

echo "=========================================="
echo "HTTPS Security Configuration"
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
SERVER_HOST="${DEPLOY_HOST:-market-mi.ru}"

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

# Check SSH connection
print_info "Checking SSH connection to $SERVER_USER@$SERVER_HOST..."
if ! ssh -o ConnectTimeout=10 "$SERVER_USER@$SERVER_HOST" "echo 'Connection successful'" > /dev/null 2>&1; then
    print_error "Cannot connect to server. Please check your SSH configuration."
    exit 1
fi
print_status "SSH connection successful"

# Create enhanced nginx configuration with security headers
print_info "Updating nginx configuration with enhanced security..."
ssh "$SERVER_USER@$SERVER_HOST" "
    # Backup current configuration
    cp /etc/nginx/sites-available/warehouse-dashboard /etc/nginx/sites-available/warehouse-dashboard.backup.\$(date +%Y%m%d_%H%M%S)
    
    # Check if security headers are already configured
    if ! grep -q 'X-Frame-Options' /etc/nginx/sites-available/warehouse-dashboard; then
        echo 'Adding security headers to nginx configuration...'
        
        # Add security headers after SSL configuration
        sed -i '/ssl_dhparam/a\\
\\
    # Enhanced Security Headers\\
    add_header Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\" always;\\
    add_header X-Frame-Options \"SAMEORIGIN\" always;\\
    add_header X-Content-Type-Options \"nosniff\" always;\\
    add_header X-XSS-Protection \"1; mode=block\" always;\\
    add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\\
    add_header Content-Security-Policy \"default-src '\''self'\''; script-src '\''self'\'' '\''unsafe-inline'\'' '\''unsafe-eval'\''; style-src '\''self'\'' '\''unsafe-inline'\''; img-src '\''self'\'' data: https:; font-src '\''self'\'' data:; connect-src '\''self'\'' https:; frame-ancestors '\''self'\'';\" always;\\
    add_header Permissions-Policy \"geolocation=(), microphone=(), camera=()\" always;' /etc/nginx/sites-available/warehouse-dashboard
        
        echo 'Security headers added'
    else
        echo 'Security headers already configured'
    fi
"

# Configure SSL session settings
print_info "Optimizing SSL session settings..."
ssh "$SERVER_USER@$SERVER_HOST" "
    # Check if SSL session settings are optimized
    if ! grep -q 'ssl_session_cache shared:SSL:50m' /etc/nginx/sites-available/warehouse-dashboard; then
        echo 'Updating SSL session settings...'
        
        # Update SSL session cache and timeout
        sed -i 's/ssl_session_cache shared:SSL:10m/ssl_session_cache shared:SSL:50m/' /etc/nginx/sites-available/warehouse-dashboard
        sed -i 's/ssl_session_timeout 10m/ssl_session_timeout 1d/' /etc/nginx/sites-available/warehouse-dashboard
        
        echo 'SSL session settings updated'
    else
        echo 'SSL session settings already optimized'
    fi
"

# Configure OCSP stapling
print_info "Configuring OCSP stapling..."
ssh "$SERVER_USER@$SERVER_HOST" "
    # Add OCSP stapling if not present
    if ! grep -q 'ssl_stapling' /etc/nginx/sites-available/warehouse-dashboard; then
        echo 'Adding OCSP stapling configuration...'
        
        sed -i '/ssl_session_timeout/a\\
    ssl_stapling on;\\
    ssl_stapling_verify on;\\
    ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;\\
    resolver 8.8.8.8 8.8.4.4 valid=300s;\\
    resolver_timeout 5s;' /etc/nginx/sites-available/warehouse-dashboard
        
        echo 'OCSP stapling configured'
    else
        echo 'OCSP stapling already configured'
    fi
"

# Test nginx configuration
print_info "Testing nginx configuration..."
TEST_RESULT=$(ssh "$SERVER_USER@$SERVER_HOST" "nginx -t 2>&1" || echo "FAILED")
if echo "$TEST_RESULT" | grep -q "syntax is ok"; then
    print_status "Nginx configuration test passed"
else
    print_error "Nginx configuration test failed:"
    echo "$TEST_RESULT"
    print_warning "Restoring backup configuration..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        cp /etc/nginx/sites-available/warehouse-dashboard.backup.* /etc/nginx/sites-available/warehouse-dashboard
        nginx -t
    "
    exit 1
fi

# Reload nginx
print_info "Reloading nginx with new configuration..."
ssh "$SERVER_USER@$SERVER_HOST" "systemctl reload nginx"
print_status "Nginx reloaded successfully"

# Test security headers
print_info "Testing security headers..."
sleep 5  # Wait for nginx to fully reload

SECURITY_TEST=$(curl -s -I "https://www.$SERVER_HOST/" | grep -E "(Strict-Transport-Security|X-Frame-Options|X-Content-Type-Options)" | wc -l)
if [ "$SECURITY_TEST" -ge 3 ]; then
    print_status "Security headers are properly configured"
else
    print_warning "Some security headers may not be working correctly"
fi

# Test SSL rating (basic check)
print_info "Performing basic SSL configuration check..."
SSL_PROTOCOLS=$(ssh "$SERVER_USER@$SERVER_HOST" "openssl s_client -connect localhost:443 -servername www.$SERVER_HOST < /dev/null 2>/dev/null | grep 'Protocol' || echo 'SSL_CHECK_FAILED'")
if [[ "$SSL_PROTOCOLS" == *"TLSv1.3"* ]] || [[ "$SSL_PROTOCOLS" == *"TLSv1.2"* ]]; then
    print_status "Modern TLS protocols are enabled"
else
    print_warning "Could not verify TLS protocol version"
fi

echo ""
echo "=========================================="
echo "HTTPS Security Configuration Complete"
echo "=========================================="
echo ""
print_status "Enhanced HTTPS security configured!"
echo ""
echo "Security features enabled:"
echo "  ✓ HTTP Strict Transport Security (HSTS)"
echo "  ✓ X-Frame-Options protection"
echo "  ✓ X-Content-Type-Options protection"
echo "  ✓ X-XSS-Protection"
echo "  ✓ Content Security Policy"
echo "  ✓ Referrer Policy"
echo "  ✓ Permissions Policy"
echo "  ✓ OCSP Stapling"
echo "  ✓ Optimized SSL session cache"
echo ""
echo "Test your security configuration:"
echo "  https://www.ssllabs.com/ssltest/analyze.html?d=$SERVER_HOST"
echo "  https://securityheaders.com/?q=https://www.$SERVER_HOST"
echo ""
echo "=========================================="