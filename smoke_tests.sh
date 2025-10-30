#!/bin/bash

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

PRODUCTION_URL="https://market-mi.ru/warehouse-dashboard/"
API_URL="https://market-mi.ru/api"

echo "=========================================="
echo "Production Smoke Tests"
echo "=========================================="

# Test 1: Dashboard loads
echo -n "Test 1: Dashboard loads... "
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$PRODUCTION_URL")
if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}PASS${NC} (HTTP $HTTP_CODE)"
else
    echo -e "${RED}FAIL${NC} (HTTP $HTTP_CODE)"
    exit 1
fi

# Test 2: React app present
echo -n "Test 2: React app present... "
CONTENT=$(curl -s "$PRODUCTION_URL")
if echo "$CONTENT" | grep -q "root"; then
    echo -e "${GREEN}PASS${NC}"
else
    echo -e "${RED}FAIL${NC}"
    exit 1
fi

# Test 3: API responds
echo -n "Test 3: API responds... "
API_RESPONSE=$(curl -s "$API_URL/detailed-stock.php?limit=1")
if echo "$API_RESPONSE" | grep -q "success"; then
    echo -e "${GREEN}PASS${NC}"
else
    echo -e "${RED}FAIL${NC}"
    exit 1
fi

# Test 4: API returns data
echo -n "Test 4: API returns data... "
if echo "$API_RESPONSE" | grep -q "data"; then
    echo -e "${GREEN}PASS${NC}"
else
    echo -e "${RED}FAIL${NC}"
    exit 1
fi

# Test 5: Check for ErrorBoundary
echo -n "Test 5: ErrorBoundary present... "
if echo "$CONTENT" | grep -q "ErrorBoundary\|error-boundary"; then
    echo -e "${GREEN}PASS${NC}"
else
    echo -e "${YELLOW}WARNING${NC} (ErrorBoundary not found in HTML)"
fi

# Test 6: Assets accessible
echo -n "Test 6: Assets accessible... "
ASSET_URL=$(echo "$CONTENT" | grep -o 'src="/warehouse-dashboard/assets/[^"]*\.js"' | head -1 | sed 's/src="//;s/"//')
if [ -n "$ASSET_URL" ]; then
    FULL_ASSET_URL="https://market-mi.ru$ASSET_URL"
    ASSET_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$FULL_ASSET_URL")
    if [ "$ASSET_CODE" = "200" ]; then
        echo -e "${GREEN}PASS${NC}"
    else
        echo -e "${RED}FAIL${NC} (HTTP $ASSET_CODE)"
        exit 1
    fi
else
    echo -e "${YELLOW}SKIP${NC} (no assets found)"
fi

echo "=========================================="
echo -e "${GREEN}All smoke tests passed!${NC}"
echo "=========================================="
