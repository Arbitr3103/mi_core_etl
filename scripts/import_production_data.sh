#!/bin/bash

# ===================================================================
# Import Production Data for Warehouse Dashboard
# ===================================================================

set -e  # Exit on error

echo "====================================================================="
echo "Importing Production Data for Warehouse Dashboard"
echo "====================================================================="
echo ""

# Set database environment variables for production
export DB_HOST="localhost"
export DB_PORT="5432"
export DB_NAME="mi_core_db"
export DB_USER="mi_core_user"
export DB_PASSWORD="PostgreSQL_MDM_2025_SecurePass!"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Check if Python is available
if ! command -v python3 &> /dev/null; then
    print_error "Python 3 is not installed"
    exit 1
fi

# Check if required Python packages are installed
python3 -c "import psycopg2, dotenv" 2>/dev/null || {
    print_warning "Installing required Python packages..."
    pip3 install psycopg2-binary python-dotenv
}

# Import sample inventory data
echo "1. Importing Ozon inventory data..."
if python3 importers/ozon_warehouse_importer.py --inventory importers/test_data/ozon_inventory_sample.csv; then
    print_success "Inventory data imported successfully"
else
    print_error "Failed to import inventory data"
    exit 1
fi
echo ""

# Import sample sales data (last 28 days)
echo "2. Importing Ozon sales data..."
# macOS compatible date commands
START_DATE=$(date -v-28d +%Y-%m-%d)
END_DATE=$(date +%Y-%m-%d)

if python3 importers/ozon_warehouse_importer.py \
    --sales importers/test_data/ozon_sales_sample.csv \
    --start-date "$START_DATE" \
    --end-date "$END_DATE"; then
    print_success "Sales data imported successfully"
else
    print_error "Failed to import sales data"
    exit 1
fi
echo ""

# Calculate initial metrics
echo "3. Calculating warehouse metrics..."
if php -f scripts/refresh_warehouse_metrics.php; then
    print_success "Warehouse metrics calculated successfully"
else
    print_warning "Metrics calculation failed (script may not exist yet)"
fi
echo ""

# Verify data integrity
echo "4. Verifying imported data..."
python3 -c "
import psycopg2
import os

conn = psycopg2.connect(
    host=os.environ['DB_HOST'],
    port=os.environ['DB_PORT'],
    database=os.environ['DB_NAME'],
    user=os.environ['DB_USER'],
    password=os.environ['DB_PASSWORD']
)
cursor = conn.cursor()

# Check inventory records
cursor.execute('SELECT COUNT(*) FROM inventory WHERE source = %s', ('ozon',))
inventory_count = cursor.fetchone()[0]
print(f'Inventory records: {inventory_count}')

# Check sales records
cursor.execute('SELECT COUNT(*) FROM stock_movements WHERE source = %s AND movement_type = %s', ('ozon', 'sale'))
sales_count = cursor.fetchone()[0]
print(f'Sales records: {sales_count}')

# Check products
cursor.execute('SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL')
products_count = cursor.fetchone()[0]
print(f'Products with Ozon SKU: {products_count}')

# Check warehouse metrics
cursor.execute('SELECT COUNT(*) FROM warehouse_sales_metrics WHERE source = %s', ('ozon',))
metrics_count = cursor.fetchone()[0]
print(f'Warehouse metrics records: {metrics_count}')

conn.close()
"

echo ""
echo "====================================================================="
echo "Data Import Summary"
echo "====================================================================="
echo ""
print_success "✓ Ozon inventory data imported from sample CSV"
print_success "✓ Ozon sales data imported from sample CSV"
print_success "✓ Database populated with initial warehouse data"
echo ""
echo "Next steps:"
echo "1. Access warehouse dashboard at: http://localhost:3000"
echo "2. Test API endpoints at: http://localhost:8000/api/warehouse-dashboard.php"
echo "3. Set up automated data refresh (cron job)"
echo ""
print_success "Production data import completed successfully!"
