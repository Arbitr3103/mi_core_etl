#!/bin/bash
#
# Full ETL Cycle Script for Warehouse Dashboard
# Loads real data from Ozon and Wildberries APIs
#
# Requirements: 4.1, 4.3, 4.4, 4.5
# Task: 11. Загрузить реальные данные и проверить dashboard

set -e  # Exit on error

echo "=========================================="
echo "FULL ETL CYCLE - WAREHOUSE DASHBOARD"
echo "=========================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Log file
LOG_FILE="logs/etl_full_cycle_$(date +%Y%m%d_%H%M%S).log"
mkdir -p logs

# Function to log messages
log() {
    echo -e "${1}" | tee -a "$LOG_FILE"
}

log "${GREEN}Starting Full ETL Cycle at $(date)${NC}"
log ""

# Check if .env file exists
if [ ! -f ".env" ]; then
    log "${RED}ERROR: .env file not found!${NC}"
    log "Please create .env file with API credentials"
    exit 1
fi

# Check if Python is available
if ! command -v python3 &> /dev/null; then
    log "${RED}ERROR: python3 not found!${NC}"
    exit 1
fi

# Check database connection
log "${YELLOW}Step 1: Checking database connection...${NC}"
python3 -c "from db_connector import connect_to_db; conn = connect_to_db(); print('✅ Database connection OK'); conn.close()" 2>&1 | tee -a "$LOG_FILE"
if [ $? -ne 0 ]; then
    log "${RED}ERROR: Database connection failed!${NC}"
    exit 1
fi
log ""

# Calculate date range (last 30 days)
END_DATE=$(date +%Y-%m-%d)
START_DATE=$(date -v-30d +%Y-%m-%d 2>/dev/null || date -d '30 days ago' +%Y-%m-%d)

log "${YELLOW}Date range: ${START_DATE} to ${END_DATE}${NC}"
log ""

# Step 2: Import Ozon Products
log "${YELLOW}Step 2: Importing Ozon products...${NC}"
cd importers
python3 run_ozon_import.py products 2>&1 | tee -a "../$LOG_FILE"
if [ $? -eq 0 ]; then
    log "${GREEN}✅ Ozon products imported successfully${NC}"
else
    log "${RED}⚠️  Ozon products import had issues (continuing...)${NC}"
fi
cd ..
log ""

# Step 3: Import Ozon Orders
log "${YELLOW}Step 3: Importing Ozon orders (${START_DATE} to ${END_DATE})...${NC}"
cd importers
python3 run_ozon_import.py orders "$START_DATE" "$END_DATE" 2>&1 | tee -a "../$LOG_FILE"
if [ $? -eq 0 ]; then
    log "${GREEN}✅ Ozon orders imported successfully${NC}"
else
    log "${RED}⚠️  Ozon orders import had issues (continuing...)${NC}"
fi
cd ..
log ""

# Step 4: Import Ozon Transactions
log "${YELLOW}Step 4: Importing Ozon transactions (${START_DATE} to ${END_DATE})...${NC}"
cd importers
python3 run_ozon_import.py transactions "$START_DATE" "$END_DATE" 2>&1 | tee -a "../$LOG_FILE"
if [ $? -eq 0 ]; then
    log "${GREEN}✅ Ozon transactions imported successfully${NC}"
else
    log "${RED}⚠️  Ozon transactions import had issues (continuing...)${NC}"
fi
cd ..
log ""

# Step 5: Import Stock Data
log "${YELLOW}Step 5: Importing stock/inventory data...${NC}"
cd importers
python3 stock_importer.py 2>&1 | tee -a "../$LOG_FILE"
if [ $? -eq 0 ]; then
    log "${GREEN}✅ Stock data imported successfully${NC}"
else
    log "${RED}⚠️  Stock import had issues (continuing...)${NC}"
fi
cd ..
log ""

# Step 6: Import Wildberries Products (if WB_API_KEY is set)
if grep -q "WB_API_KEY=" .env && [ -n "$(grep WB_API_KEY= .env | cut -d'=' -f2)" ]; then
    log "${YELLOW}Step 6: Importing Wildberries products...${NC}"
    cd importers
    python3 wb_importer.py products 2>&1 | tee -a "../$LOG_FILE"
    if [ $? -eq 0 ]; then
        log "${GREEN}✅ WB products imported successfully${NC}"
    else
        log "${RED}⚠️  WB products import had issues (continuing...)${NC}"
    fi
    cd ..
    log ""
    
    # Step 7: Import Wildberries Sales
    log "${YELLOW}Step 7: Importing Wildberries sales (${START_DATE} to ${END_DATE})...${NC}"
    cd importers
    python3 wb_importer.py sales "$START_DATE" "$END_DATE" 2>&1 | tee -a "../$LOG_FILE"
    if [ $? -eq 0 ]; then
        log "${GREEN}✅ WB sales imported successfully${NC}"
    else
        log "${RED}⚠️  WB sales import had issues (continuing...)${NC}"
    fi
    cd ..
    log ""
else
    log "${YELLOW}Step 6-7: Skipping Wildberries import (WB_API_KEY not configured)${NC}"
    log ""
fi

# Step 8: Calculate warehouse metrics
log "${YELLOW}Step 8: Calculating warehouse sales metrics...${NC}"
python3 << 'EOF' 2>&1 | tee -a "$LOG_FILE"
from db_connector import connect_to_db
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

try:
    conn = connect_to_db()
    cursor = conn.cursor()
    
    # Calculate daily sales averages and update warehouse_sales_metrics
    logger.info("Calculating warehouse sales metrics...")
    
    query = """
    INSERT INTO warehouse_sales_metrics (
        product_id, 
        warehouse_name, 
        daily_sales_avg, 
        sales_last_28_days,
        days_without_sales,
        updated_at
    )
    SELECT 
        i.product_id,
        i.warehouse_name,
        COALESCE(AVG(ABS(sm.quantity)), 0) as daily_sales_avg,
        COALESCE(SUM(ABS(sm.quantity)), 0) as sales_last_28_days,
        CASE 
            WHEN MAX(sm.movement_date) IS NULL THEN 999
            ELSE EXTRACT(DAY FROM (CURRENT_DATE - MAX(sm.movement_date)))
        END as days_without_sales,
        NOW()
    FROM inventory i
    LEFT JOIN stock_movements sm ON 
        i.product_id = sm.product_id 
        AND i.warehouse_name = sm.warehouse_name
        AND sm.movement_type = 'sale'
        AND sm.movement_date >= CURRENT_DATE - INTERVAL '28 days'
    WHERE i.source = 'ozon'
    GROUP BY i.product_id, i.warehouse_name
    ON CONFLICT (product_id, warehouse_name)
    DO UPDATE SET
        daily_sales_avg = EXCLUDED.daily_sales_avg,
        sales_last_28_days = EXCLUDED.sales_last_28_days,
        days_without_sales = EXCLUDED.days_without_sales,
        updated_at = EXCLUDED.updated_at
    """
    
    cursor.execute(query)
    conn.commit()
    
    rows_affected = cursor.rowcount
    logger.info(f"✅ Updated {rows_affected} warehouse sales metrics")
    
    cursor.close()
    conn.close()
    
except Exception as e:
    logger.error(f"❌ Error calculating metrics: {e}")
    raise

EOF

if [ $? -eq 0 ]; then
    log "${GREEN}✅ Warehouse metrics calculated successfully${NC}"
else
    log "${RED}⚠️  Metrics calculation had issues${NC}"
fi
log ""

# Step 9: Generate ETL summary report
log "${YELLOW}Step 9: Generating ETL summary report...${NC}"
python3 << 'EOF' 2>&1 | tee -a "$LOG_FILE"
from db_connector import connect_to_db
from datetime import datetime

try:
    conn = connect_to_db()
    cursor = conn.cursor()
    
    print("\n" + "="*60)
    print("ETL SUMMARY REPORT")
    print("="*60)
    print(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("")
    
    # Products count
    cursor.execute("SELECT COUNT(*) FROM dim_products")
    products_count = cursor.fetchone()[0]
    print(f"📦 Total Products: {products_count}")
    
    # Inventory records
    cursor.execute("""
        SELECT 
            source,
            COUNT(*) as records,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_stock
        FROM inventory
        GROUP BY source
    """)
    print("\n📊 Inventory by Source:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]} records, {row[2]} products, {row[3]} units")
    
    # Orders count
    cursor.execute("""
        SELECT 
            COUNT(*) as total_orders,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(qty) as total_quantity,
            SUM(price * qty) as total_revenue
        FROM fact_orders
        WHERE order_date >= CURRENT_DATE - INTERVAL '30 days'
    """)
    row = cursor.fetchone()
    print(f"\n🛒 Orders (Last 30 days):")
    print(f"  Total orders: {row[0]}")
    print(f"  Unique products: {row[1]}")
    print(f"  Total quantity: {row[2]}")
    print(f"  Total revenue: {row[3]:.2f} RUB" if row[3] else "  Total revenue: 0.00 RUB")
    
    # Warehouse metrics
    cursor.execute("""
        SELECT 
            COUNT(*) as total_metrics,
            AVG(daily_sales_avg) as avg_daily_sales,
            SUM(sales_last_28_days) as total_sales_28d
        FROM warehouse_sales_metrics
    """)
    row = cursor.fetchone()
    print(f"\n📈 Warehouse Metrics:")
    print(f"  Total metrics: {row[0]}")
    print(f"  Avg daily sales: {row[1]:.2f}" if row[1] else "  Avg daily sales: 0.00")
    print(f"  Total sales (28d): {row[2]}" if row[2] else "  Total sales (28d): 0")
    
    # Critical stock items
    cursor.execute("""
        SELECT COUNT(*)
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON 
            i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE i.quantity_present > 0
        AND wsm.daily_sales_avg > 0
        AND (i.quantity_present / NULLIF(wsm.daily_sales_avg, 0)) < 7
    """)
    critical_count = cursor.fetchone()[0]
    print(f"\n⚠️  Critical Stock Items (< 7 days): {critical_count}")
    
    print("\n" + "="*60)
    
    cursor.close()
    conn.close()
    
except Exception as e:
    print(f"❌ Error generating report: {e}")
    raise

EOF

log ""
log "${GREEN}=========================================="
log "ETL CYCLE COMPLETED at $(date)"
log "==========================================${NC}"
log ""
log "Log file: $LOG_FILE"
log ""
log "${YELLOW}Next steps:${NC}"
log "1. Open dashboard: https://market-mi.ru/warehouse-dashboard/"
log "2. Run verification: ./verify_dashboard_data.sh"
log "3. Check replenishment recommendations"
log ""
