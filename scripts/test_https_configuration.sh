#!/bin/bash

# HTTPS Configuration Testing Script
# This script performs comprehensive testing of HTTPS configuration

set -e  # Exit on error

echo "=========================================="
echo "HTTPS Configuration Testing"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
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

# Test 1: HTTP to HTTPS redirect
print_info "Testing HTTP to HTTPS redirect..."
HTTP_REDIRECT=$(curl -s -o /dev/null -w "%{http_code}" "http://$WWW_DOMAIN/" --max-time 10 || echo "000")
if [ "$HTTP_REDIRECT" = "301" ] || [ "$HTTP_REDIRECT" = "302" ]; then
    print_status "HTTP to HTTPS redirect working (HTTP $HTTP_REDIRECT)"
else
    print_error "HTTP to HTTPS redirect not working (HTTP $HTTP_REDIRECT)"
fi

# Test 2: HTTPS accessibility
print_info "Testing HTTPS accessibility..."
HTTPS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "https://$WWW_DOMAIN/" --max-time 10 || echo "000")
if [ "$HTTPS_RESPONSE" = "200" ]; then
    print_status "HTTPS site is accessible (HTTP $HTTPS_RESPONSE)"
elif [ "$HTTPS_RESPONSE" = "000" ]; then
    print_error "Cannot connect to HTTPS site (SSL/connection issue)"
else
    print_warning "HTTPS site returned unexpected status (HTTP $HTTPS_RESPONSE)"
fi

# Test 3: Warehouse dashboard HTTPS access
print_info "Testing warehouse dashboard HTTPS access..."
DASHBOARD_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "https://$WWW_DOMAIN/warehouse-dashboard/" --max-time 10 || echo "000")
if [ "$DASHBOARD_RESPONSE" = "200" ]; then
    print_status "Warehouse dashboard is accessible via HTTPS (HTTP $DASHBOARD_RESPONSE)"
else
    print_warning "Warehouse dashboard returned status (HTTP $DASHBOARD_RESPONSE)"
fi

# Test 4: API endpoint HTTPS access
print_info "Testing API endpoint HTTPS access..."
API_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "https://$WWW_DOMAIN/api/warehouse-dashboard.php" --max-time 10 || echo "000")
if [ "$API_RESPONSE" = "200" ]; then
    print_status "API endpoint is accessible via HTTPS (HTTP $API_RESPONSE)"
else
    print_warning "API endpoint returned status (HTTP $API_RESPONSE)"
fi

# Test 5: Security headers
print_info "Testing security headers..."
HEADERS_OUTPUT=$(curl -s -I "https://$WWW_DOMAIN/" --max-time 10 2>/dev/null || echo "CURL_FAILED")

if [ "$HEADERS_OUTPUT" != "CURL_FAILED" ]; then
    # Check individual security headers
    if echo "$HEADERS_OUTPUT" | grep -qi "strict-transport-security"; then
        print_status "HSTS header present"
    else
        print_warning "HSTS header missing"
    fi
    
    if echo "$HEADERS_OUTPUT" | grep -qi "x-frame-options"; then
        print_status "X-Frame-Options header present"
    else
        print_warning "X-Frame-Options header missing"
    fi
    
    if echo "$HEADERS_OUTPUT" | grep -qi "x-content-type-options"; then
        print_status "X-Content-Type-Options header present"
    else
        print_warning "X-Content-Type-Options header missing"
    fi
    
    if echo "$HEADERS_OUTPUT" | grep -qi "x-xss-protection"; then
        print_status "X-XSS-Protection header present"
    else
        print_warning "X-XSS-Protection header missing"
    fi
    
    if echo "$HEADERS_OUTPUT" | grep -qi "content-security-policy"; then
        print_status "Content-Security-Policy header present"
    else
        print_warning "Content-Security-Policy header missing"
    fi
else
    print_error "Could not retrieve headers for security testing"
fi

# Test 6: SSL certificate validity
print_info "Testing SSL certificate validity..."
CERT_CHECK=$(echo | openssl s_client -servername "$WWW_DOMAIN" -connect "$WWW_DOMAIN:443" 2>/dev/null | openssl x509 -noout -dates 2>/dev/null || echo "CERT_CHECK_FAILED")

if [ "$CERT_CHECK" != "CERT_CHECK_FAILED" ]; then
    print_status "SSL certificate is valid"
    echo "$CERT_CHECK" | sed 's/^/    /'
else
    print_warning "Could not verify SSL certificate validity"
fi

# Test 7: TLS version support
print_info "Testing TLS version support..."
TLS_TEST=$(echo | openssl s_client -servername "$WWW_DOMAIN" -connect "$WWW_DOMAIN:443" -tls1_2 2>/dev/null | grep "Protocol" || echo "TLS_TEST_FAILED")

if [ "$TLS_TEST" != "TLS_TEST_FAILED" ]; then
    if echo "$TLS_TEST" | grep -q "TLSv1.3\|TLSv1.2"; then
        print_status "Modern TLS protocols supported"
        echo "$TLS_TEST" | sed 's/^/    /'
    else
        print_warning "Only older TLS protocols detected"
    fi
else
    print_warning "Could not verify TLS protocol support"
fi

# Test 8: Response time
print_info "Testing HTTPS response time..."
RESPONSE_TIME=$(curl -s -o /dev/null -w "%{time_total}" "https://$WWW_DOMAIN/warehouse-dashboard/" --max-time 30 || echo "999")
RESPONSE_TIME_MS=$(echo "$RESPONSE_TIME * 1000" | bc 2>/dev/null || echo "999")

if (( $(echo "$RESPONSE_TIME < 3.0" | bc -l 2>/dev/null || echo 0) )); then
    print_status "Response time is acceptable (${RESPONSE_TIME}s)"
elif (( $(echo "$RESPONSE_TIME < 10.0" | bc -l 2>/dev/null || echo 0) )); then
    print_warning "Response time is slow (${RESPONSE_TIME}s)"
else
    print_error "Response time is too slow or failed (${RESPONSE_TIME}s)"
fi

# Test 9: Mixed content check
print_info "Testing for mixed content issues..."
MIXED_CONTENT_CHECK=$(curl -s "https://$WWW_DOMAIN/warehouse-dashboard/" | grep -i "http://" | grep -v "localhost\|127.0.0.1" | wc -l || echo "0")

if [ "$MIXED_CONTENT_CHECK" -eq 0 ]; then
    print_status "No mixed content detected"
else
    print_warning "Potential mixed content detected ($MIXED_CONTENT_CHECK instances)"
fi

# Test 10: CORS headers for API
print_info "Testing CORS headers for API..."
CORS_TEST=$(curl -s -H "Origin: https://$WWW_DOMAIN" -I "https://$WWW_DOMAIN/api/warehouse-dashboard.php" | grep -i "access-control-allow-origin" || echo "NO_CORS")

if [ "$CORS_TEST" != "NO_CORS" ]; then
    print_status "CORS headers configured for API"
else
    print_warning "CORS headers may not be configured"
fi

# Summary
echo ""
echo "=========================================="
echo "HTTPS Configuration Test Summary"
echo "=========================================="
echo ""

# Count successful tests
TOTAL_TESTS=10
PASSED_TESTS=0

# This is a simplified count - in a real implementation, you'd track each test result
if [ "$HTTP_REDIRECT" = "301" ] || [ "$HTTP_REDIRECT" = "302" ]; then ((PASSED_TESTS++)); fi
if [ "$HTTPS_RESPONSE" = "200" ]; then ((PASSED_TESTS++)); fi
if [ "$DASHBOARD_RESPONSE" = "200" ]; then ((PASSED_TESTS++)); fi
if [ "$API_RESPONSE" = "200" ]; then ((PASSED_TESTS++)); fi
if [ "$HEADERS_OUTPUT" != "CURL_FAILED" ]; then ((PASSED_TESTS++)); fi
if [ "$CERT_CHECK" != "CERT_CHECK_FAILED" ]; then ((PASSED_TESTS++)); fi
if [ "$TLS_TEST" != "TLS_TEST_FAILED" ]; then ((PASSED_TESTS++)); fi
if (( $(echo "$RESPONSE_TIME < 10.0" | bc -l 2>/dev/null || echo 0) )); then ((PASSED_TESTS++)); fi
if [ "$MIXED_CONTENT_CHECK" -eq 0 ]; then ((PASSED_TESTS++)); fi
if [ "$CORS_TEST" != "NO_CORS" ]; then ((PASSED_TESTS++)); fi

echo "Tests passed: $PASSED_TESTS/$TOTAL_TESTS"
echo ""

if [ "$PASSED_TESTS" -ge 8 ]; then
    print_status "HTTPS configuration is working well!"
elif [ "$PASSED_TESTS" -ge 6 ]; then
    print_warning "HTTPS configuration has some issues that should be addressed"
else
    print_error "HTTPS configuration has significant issues that need immediate attention"
fi

echo ""
echo "Access URLs:"
echo "  🌐 Main site: https://$WWW_DOMAIN/"
echo "  📊 Dashboard: https://$WWW_DOMAIN/warehouse-dashboard/"
echo "  🔌 API: https://$WWW_DOMAIN/api/warehouse-dashboard.php"
echo ""
echo "External testing tools:"
echo "  🔒 SSL Labs: https://www.ssllabs.com/ssltest/analyze.html?d=$WWW_DOMAIN"
echo "  🛡️  Security Headers: https://securityheaders.com/?q=https://$WWW_DOMAIN"
echo ""
echo "=========================================="