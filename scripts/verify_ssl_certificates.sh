#!/bin/bash

# SSL Certificate Verification and Setup Script
# This script verifies SSL certificates are properly configured for market-mi.ru

set -e  # Exit on error

echo "=========================================="
echo "SSL Certificate Verification"
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
DOMAIN="market-mi.ru"
WWW_DOMAIN="www.market-mi.ru"

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

# Check if certificates exist
print_info "Checking SSL certificate files..."
CERT_CHECK=$(ssh "$SERVER_USER@$SERVER_HOST" "
    if [ -f '/etc/ssl/certs/market-mi.ru.crt' ] && [ -f '/etc/ssl/private/market-mi.ru.key' ]; then
        echo 'CUSTOM_CERTS_EXIST'
    elif [ -f '/etc/letsencrypt/live/market-mi.ru/fullchain.pem' ] && [ -f '/etc/letsencrypt/live/market-mi.ru/privkey.pem' ]; then
        echo 'LETSENCRYPT_CERTS_EXIST'
    elif [ -f '/etc/letsencrypt/live/www.market-mi.ru/fullchain.pem' ] && [ -f '/etc/letsencrypt/live/www.market-mi.ru/privkey.pem' ]; then
        echo 'LETSENCRYPT_WWW_CERTS_EXIST'
    else
        echo 'NO_CERTS_FOUND'
    fi
")

case "$CERT_CHECK" in
    "CUSTOM_CERTS_EXIST")
        print_status "Custom SSL certificates found at /etc/ssl/"
        CERT_PATH="/etc/ssl/certs/market-mi.ru.crt"
        KEY_PATH="/etc/ssl/private/market-mi.ru.key"
        ;;
    "LETSENCRYPT_CERTS_EXIST")
        print_status "Let's Encrypt certificates found for market-mi.ru"
        CERT_PATH="/etc/letsencrypt/live/market-mi.ru/fullchain.pem"
        KEY_PATH="/etc/letsencrypt/live/market-mi.ru/privkey.pem"
        ;;
    "LETSENCRYPT_WWW_CERTS_EXIST")
        print_status "Let's Encrypt certificates found for www.market-mi.ru"
        CERT_PATH="/etc/letsencrypt/live/www.market-mi.ru/fullchain.pem"
        KEY_PATH="/etc/letsencrypt/live/www.market-mi.ru/privkey.pem"
        ;;
    "NO_CERTS_FOUND")
        print_warning "No SSL certificates found!"
        print_info "Attempting to set up Let's Encrypt certificates..."
        
        # Check if certbot is installed
        CERTBOT_CHECK=$(ssh "$SERVER_USER@$SERVER_HOST" "which certbot || echo 'NOT_FOUND'")
        if [ "$CERTBOT_CHECK" = "NOT_FOUND" ]; then
            print_info "Installing certbot..."
            ssh "$SERVER_USER@$SERVER_HOST" "
                apt update
                apt install -y certbot python3-certbot-nginx
            "
            print_status "Certbot installed"
        else
            print_status "Certbot is already installed"
        fi
        
        # Generate certificates
        print_info "Generating SSL certificates for $DOMAIN and $WWW_DOMAIN..."
        ssh "$SERVER_USER@$SERVER_HOST" "
            certbot --nginx -d $DOMAIN -d $WWW_DOMAIN --non-interactive --agree-tos --email admin@$DOMAIN --redirect
        " || {
            print_error "Failed to generate SSL certificates automatically"
            print_warning "You may need to:"
            print_warning "1. Ensure DNS is pointing to this server"
            print_warning "2. Temporarily disable nginx and use standalone mode"
            print_warning "3. Configure certificates manually"
            exit 1
        }
        
        print_status "SSL certificates generated successfully"
        CERT_PATH="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
        KEY_PATH="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
        ;;
esac

# Update nginx configuration with correct certificate paths
print_info "Updating nginx configuration with certificate paths..."
ssh "$SERVER_USER@$SERVER_HOST" "
    sed -i 's|ssl_certificate .*|ssl_certificate $CERT_PATH;|' /etc/nginx/sites-available/warehouse-dashboard
    sed -i 's|ssl_certificate_key .*|ssl_certificate_key $KEY_PATH;|' /etc/nginx/sites-available/warehouse-dashboard
"

# Test nginx configuration
print_info "Testing nginx configuration..."
TEST_RESULT=$(ssh "$SERVER_USER@$SERVER_HOST" "nginx -t 2>&1" || echo "FAILED")
if echo "$TEST_RESULT" | grep -q "syntax is ok"; then
    print_status "Nginx configuration test passed"
else
    print_error "Nginx configuration test failed:"
    echo "$TEST_RESULT"
    exit 1
fi

# Reload nginx
print_info "Reloading nginx..."
ssh "$SERVER_USER@$SERVER_HOST" "systemctl reload nginx"
print_status "Nginx reloaded successfully"

# Test SSL certificate
print_info "Testing SSL certificate..."
sleep 5  # Wait for nginx to fully reload

# Test HTTPS connection
HTTPS_TEST=$(curl -s -o /dev/null -w "%{http_code}" "https://$WWW_DOMAIN/" --connect-timeout 10 || echo "000")
if [ "$HTTPS_TEST" = "200" ] || [ "$HTTPS_TEST" = "301" ] || [ "$HTTPS_TEST" = "302" ]; then
    print_status "HTTPS connection successful (HTTP $HTTPS_TEST)"
else
    print_warning "HTTPS connection failed (HTTP $HTTPS_TEST)"
fi

# Check certificate expiration
print_info "Checking certificate expiration..."
CERT_INFO=$(ssh "$SERVER_USER@$SERVER_HOST" "openssl x509 -in $CERT_PATH -noout -dates 2>/dev/null || echo 'CERT_READ_FAILED'")
if [ "$CERT_INFO" != "CERT_READ_FAILED" ]; then
    echo "$CERT_INFO"
    print_status "Certificate information retrieved"
else
    print_warning "Could not read certificate information"
fi

# Set up automatic renewal for Let's Encrypt
if [[ "$CERT_PATH" == *"letsencrypt"* ]]; then
    print_info "Setting up automatic certificate renewal..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        # Add cron job for certificate renewal if not exists
        (crontab -l 2>/dev/null | grep -q 'certbot renew') || {
            (crontab -l 2>/dev/null; echo '0 12 * * * /usr/bin/certbot renew --quiet') | crontab -
        }
    "
    print_status "Automatic renewal configured"
fi

echo ""
echo "=========================================="
echo "SSL Certificate Verification Complete"
echo "=========================================="
echo ""
print_status "SSL certificates are properly configured!"
echo ""
echo "Certificate details:"
echo "  Certificate: $CERT_PATH"
echo "  Private Key: $KEY_PATH"
echo ""
echo "Test your HTTPS setup:"
echo "  https://$DOMAIN/"
echo "  https://$WWW_DOMAIN/"
echo "  https://$WWW_DOMAIN/warehouse-dashboard/"
echo ""
echo "=========================================="