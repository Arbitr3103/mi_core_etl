#!/bin/bash

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

PRODUCTION_SERVER="178.72.129.61"
PRODUCTION_USER="vladimir"

echo "=========================================="
echo "Production Log Monitor"
echo "=========================================="
echo ""
echo "Monitoring production logs for errors..."
echo "Press Ctrl+C to stop."
echo ""

check_server_logs() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} Checking server logs..."
    
    echo -e "${YELLOW}Nginx Error Log (last 10 lines):${NC}"
    ssh ${PRODUCTION_USER}@${PRODUCTION_SERVER} "sudo tail -10 /var/log/nginx/error.log" 2>/dev/null || echo "Could not access nginx logs"
    
    echo -e "${YELLOW}PHP Error Log (last 10 lines):${NC}"
    ssh ${PRODUCTION_USER}@${PRODUCTION_SERVER} "sudo tail -10 /var/log/php8.1-fpm.log" 2>/dev/null || echo "Could not access PHP logs"
    
    echo -e "${YELLOW}Recent 404 errors:${NC}"
    ssh ${PRODUCTION_USER}@${PRODUCTION_SERVER} "sudo grep '404' /var/log/nginx/access.log | tail -5" 2>/dev/null || echo "No 404 errors found"
    
    echo ""
}

check_app_health() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} Checking application health..."
    
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://market-mi.ru/warehouse-dashboard/")
    if [ "$HTTP_CODE" = "200" ]; then
        echo -e "${GREEN}✓${NC} Dashboard: OK (HTTP $HTTP_CODE)"
    else
        echo -e "${RED}✗${NC} Dashboard: FAIL (HTTP $HTTP_CODE)"
    fi
    
    API_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://market-mi.ru/api/detailed-stock.php?limit=1")
    if [ "$API_CODE" = "200" ]; then
        echo -e "${GREEN}✓${NC} API: OK (HTTP $API_CODE)"
    else
        echo -e "${RED}✗${NC} API: FAIL (HTTP $API_CODE)"
    fi
    
    echo ""
}

echo "Starting monitoring (checking every 30 seconds)..."
echo ""

while true; do
    check_app_health
    check_server_logs
    echo "----------------------------------------"
    sleep 30
done
