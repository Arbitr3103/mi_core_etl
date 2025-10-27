#!/bin/bash

# Load Production Data Script
# This script imports real production data from the remote server into the local database

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}=========================================="
echo "Loading Production Data"
echo -e "==========================================${NC}"
echo ""

# Check if we can connect to the remote server
echo -e "${BLUE}[1/6]${NC} Checking remote server connection..."
if ! ssh root@market-mi.ru "echo 'Connected'" >/dev/null 2>&1; then
    echo -e "${RED}[✗]${NC} Cannot connect to remote server"
    echo "    Please check your SSH configuration"
    exit 1
fi
echo -e "${GREEN}[✓]${NC} Connected to remote server"
echo ""

# Check local database connection
echo -e "${BLUE}[2/6]${NC} Checking local database connection..."
if ! psql -d mi_core_db -c "SELECT 1" >/dev/null 2>&1; then
    echo -e "${RED}[✗]${NC} Cannot connect to local database"
    echo "    Please ensure PostgreSQL is running and mi_core_db exists"
    exit 1
fi
echo -e "${GREEN}[✓]${NC} Connected to local database"
echo ""

# Ensure local database schema matches production
echo -e "${BLUE}[3/6]${NC} Updating local database schema..."

# Add missing columns to inventory table
psql -d mi_core_db -q << 'EOF'
ALTER TABLE inventory 
ADD COLUMN IF NOT EXISTS cluster VARCHAR(255),
ADD COLUMN IF NOT EXISTS sku VARCHAR(255),
ADD COLUMN IF NOT EXISTS name VARCHAR(500),
ADD COLUMN IF NOT EXISTS available INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS reserved INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS preparing_for_sale INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS in_requests INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS in_transit INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS in_inspection INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS returns INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS expiring_soon INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS defective INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS in_supply_requests INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS returning_from_customers INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS excess_from_supply INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS awaiting_upd INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS preparing_for_removal INTEGER DEFAULT 0;
EOF

# Add missing columns to warehouse_sales_metrics table
psql -d mi_core_db -q << 'EOF'
ALTER TABLE warehouse_sales_metrics 
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;
EOF

echo -e "${GREEN}[✓]${NC} Schema updated"
echo ""

# Dump and import data
echo -e "${BLUE}[4/6]${NC} Importing production data..."
echo "    This may take a few minutes..."
echo ""

# Create temporary directory for dumps
TEMP_DIR=$(mktemp -d)
trap "rm -rf $TEMP_DIR" EXIT

# Import dim_products first (required for foreign keys)
echo "    Importing dim_products..."
ssh root@market-mi.ru "sudo -u postgres pg_dump -d mi_core_db --table=dim_products --data-only --inserts --no-owner --no-privileges 2>/dev/null" > "$TEMP_DIR/dim_products.sql"
psql -d mi_core_db < "$TEMP_DIR/dim_products.sql" >/dev/null 2>&1

# Import inventory
echo "    Importing inventory..."
ssh root@market-mi.ru "sudo -u postgres pg_dump -d mi_core_db --table=inventory --data-only --column-inserts --no-owner --no-privileges 2>/dev/null" > "$TEMP_DIR/inventory.sql"
psql -d mi_core_db < "$TEMP_DIR/inventory.sql" >/dev/null 2>&1

# Import warehouse_sales_metrics
echo "    Importing warehouse_sales_metrics..."
ssh root@market-mi.ru "sudo -u postgres pg_dump -d mi_core_db --table=warehouse_sales_metrics --data-only --column-inserts --no-owner --no-privileges 2>/dev/null" > "$TEMP_DIR/warehouse_sales_metrics.sql"
psql -d mi_core_db < "$TEMP_DIR/warehouse_sales_metrics.sql" >/dev/null 2>&1

# Import master_products (if exists)
echo "    Importing master_products..."
ssh root@market-mi.ru "sudo -u postgres pg_dump -d mi_core_db --table=master_products --data-only --column-inserts --no-owner --no-privileges 2>/dev/null" > "$TEMP_DIR/master_products.sql"
psql -d mi_core_db < "$TEMP_DIR/master_products.sql" >/dev/null 2>&1 || true

echo -e "${GREEN}[✓]${NC} Data imported successfully"
echo ""

# Verify data
echo -e "${BLUE}[5/6]${NC} Verifying imported data..."
DIM_PRODUCTS_COUNT=$(psql -d mi_core_db -t -c "SELECT COUNT(*) FROM dim_products;" | tr -d ' ')
INVENTORY_COUNT=$(psql -d mi_core_db -t -c "SELECT COUNT(*) FROM inventory;" | tr -d ' ')
METRICS_COUNT=$(psql -d mi_core_db -t -c "SELECT COUNT(*) FROM warehouse_sales_metrics;" | tr -d ' ')

echo "    Product records (dim_products): $DIM_PRODUCTS_COUNT"
echo "    Inventory records: $INVENTORY_COUNT"
echo "    Metrics records: $METRICS_COUNT"
echo ""

if [ "$INVENTORY_COUNT" -gt 0 ]; then
    echo -e "${GREEN}[✓]${NC} Data verification successful"
else
    echo -e "${YELLOW}[!]${NC} Warning: No inventory records found"
fi
echo ""

# Test API endpoint
echo -e "${BLUE}[6/6]${NC} Testing API endpoint..."
if curl -s "http://localhost:8080/api/inventory/detailed-stock?limit=1" >/dev/null 2>&1; then
    echo -e "${GREEN}[✓]${NC} API is responding"
else
    echo -e "${YELLOW}[!]${NC} API is not running (start with ./local-backend.sh)"
fi
echo ""

echo -e "${GREEN}=========================================="
echo "Production Data Loaded Successfully!"
echo -e "==========================================${NC}"
echo ""
echo "You can now:"
echo "  1. Start the backend: ./local-backend.sh"
echo "  2. Start the frontend: ./local-frontend.sh"
echo "  3. Open http://localhost:5173 in your browser"
echo ""
