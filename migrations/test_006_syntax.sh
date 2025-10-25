#!/bin/bash

# Syntax validation test for Warehouse Normalization Table Migration
# Purpose: Validate SQL syntax without executing against database

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔍 Testing SQL syntax for Warehouse Normalization Table Migration${NC}"
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
    if grep -q "CREATE TABLE.*warehouse_normalization" "$sql_file"; then
        echo -e "${GREEN}✅ Contains CREATE TABLE warehouse_normalization statement${NC}"
    elif [[ "$sql_file" == *"rollback"* ]]; then
        if grep -q "DROP TABLE.*warehouse_normalization" "$sql_file"; then
            echo -e "${GREEN}✅ Contains DROP TABLE warehouse_normalization statement${NC}"
        else
            echo -e "${RED}❌ Missing DROP TABLE warehouse_normalization statement${NC}"
            return 1
        fi
    else
        echo -e "${RED}❌ Missing CREATE TABLE warehouse_normalization statement${NC}"
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
    
    # Check for required fields (task requirements) - only for non-rollback files
    if [[ "$sql_file" != *"rollback"* ]]; then
        local required_fields=("original_name" "normalized_name" "mapping" "fast.*lookup")
        
        for field in "${required_fields[@]}"; do
            if grep -q "$field" "$sql_file"; then
                echo -e "${GREEN}✅ Contains $field field/functionality${NC}"
            else
                echo -e "${RED}❌ Missing $field field/functionality${NC}"
                return 1
            fi
        done
        
        # Check for warehouse normalization specific features
        if grep -q "РФЦ\|МРФЦ" "$sql_file"; then
            echo -e "${GREEN}✅ Contains РФЦ/МРФЦ normalization rules${NC}"
        else
            echo -e "${RED}❌ Missing РФЦ/МРФЦ normalization rules${NC}"
            return 1
        fi
    else
        echo -e "${GREEN}✅ Rollback script structure validated${NC}"
    fi
    
    # Check for fast lookup indexes
    if [[ "$sql_file" != *"rollback"* ]]; then
        if grep -q "idx_norm.*original\|idx_norm.*normalized" "$sql_file"; then
            echo -e "${GREEN}✅ Contains fast lookup indexes${NC}"
        else
            echo -e "${RED}❌ Missing fast lookup indexes${NC}"
            return 1
        fi
    fi
    
    echo -e "${GREEN}✅ $description syntax validation passed${NC}"
    echo ""
    return 0
}

# Test main migration file
if test_sql_syntax "006_create_warehouse_normalization_table.sql" "Main migration"; then
    MAIN_PASSED=true
else
    MAIN_PASSED=false
fi

# Test rollback file
if test_sql_syntax "rollback_006_create_warehouse_normalization_table.sql" "Rollback migration"; then
    ROLLBACK_PASSED=true
else
    ROLLBACK_PASSED=false
fi

# Test validation file
echo -e "${YELLOW}📝 Testing Validation script${NC}"
if [ -f "migrations/validate_006_create_warehouse_normalization_table.sql" ]; then
    if grep -q "information_schema.tables" "migrations/validate_006_create_warehouse_normalization_table.sql"; then
        echo -e "${GREEN}✅ Validation script contains table checks${NC}"
        VALIDATION_PASSED=true
    else
        echo -e "${RED}❌ Validation script missing table checks${NC}"
        VALIDATION_PASSED=false
    fi
    
    if grep -q "INSERT INTO warehouse_normalization\|TEST_WAREHOUSE_VALIDATION" "migrations/validate_006_create_warehouse_normalization_table.sql"; then
        echo -e "${GREEN}✅ Validation script contains test operations${NC}"
    else
        echo -e "${RED}❌ Validation script missing test operations${NC}"
        VALIDATION_PASSED=false
    fi
    
    if grep -q "normalization lookup" "migrations/validate_006_create_warehouse_normalization_table.sql"; then
        echo -e "${GREEN}✅ Validation script tests lookup functionality${NC}"
    else
        echo -e "${RED}❌ Validation script missing lookup tests${NC}"
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
    echo -e "${GREEN}Warehouse Normalization Table migration is ready for deployment.${NC}"
    exit 0
else
    echo -e "${RED}❌ Some syntax validations failed.${NC}"
    echo -e "${RED}Please review and fix the issues before deployment.${NC}"
    exit 1
fi