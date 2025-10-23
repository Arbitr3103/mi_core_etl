#!/bin/bash
# Test script for Ozon Warehouse Importer
# This script tests the import functionality with sample data

set -e  # Exit on error

echo "=========================================="
echo "Ozon Warehouse Importer - Test Script"
echo "=========================================="
echo ""

# Check if Python is available
if ! command -v python3 &> /dev/null; then
    echo "❌ Error: Python 3 is not installed"
    exit 1
fi

echo "✅ Python 3 found"

# Check if required Python packages are installed
echo "Checking Python dependencies..."
python3 -c "import psycopg2" 2>/dev/null || {
    echo "⚠️  psycopg2 not found. Installing..."
    pip3 install psycopg2-binary
}

python3 -c "import dotenv" 2>/dev/null || {
    echo "⚠️  python-dotenv not found. Installing..."
    pip3 install python-dotenv
}

echo "✅ All dependencies installed"
echo ""

# Check if .env file exists
if [ ! -f .env ]; then
    echo "❌ Error: .env file not found"
    echo "Please create a .env file with database credentials"
    exit 1
fi

echo "✅ .env file found"
echo ""

# Check if test data files exist
if [ ! -f importers/test_data/ozon_inventory_sample.csv ]; then
    echo "❌ Error: Test inventory file not found"
    exit 1
fi

if [ ! -f importers/test_data/ozon_sales_sample.csv ]; then
    echo "❌ Error: Test sales file not found"
    exit 1
fi

echo "✅ Test data files found"
echo ""

# Run the import
echo "=========================================="
echo "Starting import test..."
echo "=========================================="
echo ""

echo "Step 1: Importing inventory data..."
python3 importers/ozon_warehouse_importer.py \
    --inventory importers/test_data/ozon_inventory_sample.csv

echo ""
echo "Step 2: Importing sales data..."
python3 importers/ozon_warehouse_importer.py \
    --sales importers/test_data/ozon_sales_sample.csv \
    --start-date 2025-09-25 \
    --end-date 2025-10-20

echo ""
echo "=========================================="
echo "✅ Import test completed successfully!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Run: php scripts/refresh_warehouse_metrics.php"
echo "2. Open Warehouse Dashboard in browser"
echo "3. Verify data is displayed correctly"
echo ""
