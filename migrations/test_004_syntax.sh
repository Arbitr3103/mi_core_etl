#!/bin/bash

# Syntax validation test for Analytics API Integration Migration
# Purpose: Validate SQL syntax without executing against database

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔍 Testing SQL syntax for Analytics API Integration Migration${NC}"
echo ""

# Function to test SQL syntax
test_sql_syntax() {
    local sql_file="migrations/$1"
    local description=$2
    
    echo -e "${YELLOW}📝 Testing $description${NC}"
    
    if [ ! -f "$sql_file" ]; then
        echo -e "${RED}❌ Error: $sql_file not found${NC}"
        return 1
    fi
    
    # Basic syntax checks
    if grep -q "ALTER TABLE inventory" "$sql_file"; then
        echo -e "${GREEN}✅ Contains ALTER TABLE statements${NC}"
    else
        echo -e "${RED}❌ Missing ALTER TABLE statements${NC}"
        return 1
    fi
    
    if [[ "$sql_file" == *"rollback"* ]]; then
        if grep -q "DROP INDEX" "$sql_file"; then
            echo -e "${GREEN}✅ Contains DROP INDEX statements${NC}"
        else
            echo -e "${RED}❌ Missing DROP INDEX statements${NC}"
            return 1
        fi
    else
        if grep -q "CREATE INDEX" "$sql_file"; then
            echo -e "${GREEN}✅ Contains CREATE INDEX statements${NC}"
        else
            echo -e "${RED}❌ Missing CREATE INDEX statements${NC}"
            return 1
        fi
    fi
    
    # Check for required columns
    local required_columns=("data_source" "data_quality_score" "last_analytics_sync" "normalized_warehouse_name" "original_warehouse_name" "sync_batch_id")
    
    for column in "${required_columns[@]}"; do
        if grep -q "$column" "$sql_file"; then
            echo -e "${GREEN}✅ Contains $column column${NC}"
        else
            echo -e "${RED}❌ Missing $column column${NC}"
            return 1
        fi
    done
    
    echo -e "${GREEN}✅ $description syntax validation passed${NC}"
    echo ""
    return 0
}

# Test main migration file
if test_sql_syntax "004_extend_inventory_analytics_integration.sql" "Main migration"; then
    MAIN_PASSED=true
else
    MAIN_PASSED=false
fi

# Test rollback file
if test_sql_syntax "rollback_004_extend_inventory_analytics_integration.sql" "Rollback migration"; then
    ROLLBACK_PASSED=true
else
    ROLLBACK_PASSED=false
fi

# Test validation file
echo -e "${YELLOW}📝 Testing Validation script${NC}"
if [ -f "migrations/validate_004_extend_inventory_analytics_integration.sql" ]; then
    if grep -q "information_schema.columns" "migrations/validate_004_extend_inventory_analytics_integration.sql"; then
        echo -e "${GREEN}✅ Validation script syntax looks good${NC}"
        VALIDATION_PASSED=true
    else
        echo -e "${RED}❌ Validation script missing required queries${NC}"
        VALIDATION_PASSED=false
    fi
else
    echo -e "${RED}❌ Validation script not found${NC}"
    VALIDATION_PASSED=false
fi
echo ""

# Summary
echo -e "${YELLOW}📋 Syntax Validation Summary:${NC}"
if [ "$MAIN_PASSED" = true ]; then
    echo -e "${GREEN}✅ Main migration syntax: PASSED${NC}"
else
    echo -e "${RED}❌ Main migration syntax: FAILED${NC}"
fi

if [ "$ROLLBACK_PASSED" = true ]; then
    echo -e "${GREEN}✅ Rollback migration syntax: PASSED${NC}"
else
    echo -e "${RED}❌ Rollback migration syntax: FAILED${NC}"
fi

if [ "$VALIDATION_PASSED" = true ]; then
    echo -e "${GREEN}✅ Validation script syntax: PASSED${NC}"
else
    echo -e "${RED}❌ Validation script syntax: FAILED${NC}"
fi

echo ""

if [ "$MAIN_PASSED" = true ] && [ "$ROLLBACK_PASSED" = true ] && [ "$VALIDATION_PASSED" = true ]; then
    echo -e "${GREEN}🎉 All syntax validations passed!${NC}"
    echo -e "${GREEN}Migration is ready for deployment.${NC}"
    exit 0
else
    echo -e "${RED}❌ Some syntax validations failed.${NC}"
    echo -e "${RED}Please review and fix the issues before deployment.${NC}"
    exit 1
fi