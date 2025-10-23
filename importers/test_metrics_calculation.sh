#!/bin/bash
# Test script for warehouse metrics calculation
# This script runs the metrics refresh and validates the results

set -e  # Exit on error

echo "=========================================="
echo "Warehouse Metrics Calculation Test"
echo "=========================================="
echo ""

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "❌ Error: PHP is not installed"
    exit 1
fi

echo "✅ PHP found: $(php -v | head -n 1)"
echo ""

# Check if the refresh script exists
if [ ! -f scripts/refresh_warehouse_metrics.php ]; then
    echo "❌ Error: refresh_warehouse_metrics.php not found"
    exit 1
fi

echo "✅ Metrics refresh script found"
echo ""

# Make sure the script is executable
chmod +x scripts/refresh_warehouse_metrics.php

# Run the metrics refresh script
echo "=========================================="
echo "Running metrics calculation..."
echo "=========================================="
echo ""

php scripts/refresh_warehouse_metrics.php --verbose

echo ""
echo "=========================================="
echo "Validating calculated metrics..."
echo "=========================================="
echo ""

# Run validation script
if [ -f importers/validate_metrics.py ]; then
    python3 importers/validate_metrics.py
else
    echo "⚠️  Metrics validation script not found, skipping validation"
fi

echo ""
echo "=========================================="
echo "✅ Metrics calculation test completed!"
echo "=========================================="
echo ""
echo "Check the log file for details:"
echo "  logs/warehouse_metrics_refresh.log"
echo ""
echo "Next steps:"
echo "1. Open Warehouse Dashboard in browser"
echo "2. Verify metrics are displayed correctly"
echo "3. Check replenishment recommendations"
echo ""
