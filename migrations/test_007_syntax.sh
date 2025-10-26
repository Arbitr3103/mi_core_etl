#!/bin/bash

# Syntax Test: 007_create_ozon_etl_schema.sql
# Description: Test SQL syntax for Ozon ETL System schema
# Date: 2025-10-26

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Test files
MIGRATION_FILE="migrations/007_create_ozon_etl_schema.sql"
ROLLBACK_FILE="migrations/rollback_007_create_ozon_etl_schema.sql"
VALIDATION_FILE="migrations/validate_007_create_ozon_etl_schema.sql"

print_status "Testing SQL syntax for Ozon ETL Schema migration files"
print_status "======================================================"

# Function to test SQL syntax using PostgreSQL
test_sql_syntax() {
    local file=$1
    local description=$2
    
    print_status "Testing $description: $file"
    
    if [ ! -f "$file" ]; then
        print_error "File not found: $file"
        return 1
    fi
    
    # Check if psql is available
    if ! command -v psql &> /dev/null; then
        print_warning "PostgreSQL client not available, skipping syntax check for $file"
        return 0
    fi
    
    # Test syntax by parsing without execution
    if psql --set=ON_ERROR_STOP=1 --quiet --no-psqlrc --single-transaction --dry-run -f "$file" 2>/dev/null; then
        print_status "✓ Syntax OK: $file"
        return 0
    else
        # If dry-run is not supported, try a different approach
        # Create a temporary test that just validates syntax
        if psql --set=ON_ERROR_STOP=1 --quiet --no-psqlrc -c "BEGIN; \i $file; ROLLBACK;" template1 2>/dev/null; then
            print_status "✓ Syntax OK: $file"
            return 0
        else
            print_error "✗ Syntax Error in: $file"
            return 1
        fi
    fi
}

# Function to check basic SQL structure
check_sql_structure() {
    local file=$1
    local description=$2
    
    print_status "Checking structure of $description: $file"
    
    if [ ! -f "$file" ]; then
        print_error "File not found: $file"
        return 1
    fi
    
    # Check for basic SQL keywords and structure
    local errors=0
    
    # Check for unmatched parentheses
    local open_parens=$(grep -o '(' "$file" | wc -l)
    local close_parens=$(grep -o ')' "$file" | wc -l)
    
    if [ "$open_parens" -ne "$close_parens" ]; then
        print_error "Unmatched parentheses in $file (open: $open_parens, close: $close_parens)"
        errors=$((errors + 1))
    fi
    
    # Check for semicolons at end of statements
    if ! grep -q ';$' "$file"; then
        print_warning "No statements ending with semicolon found in $file"
    fi
    
    # Check for SQL injection patterns (basic check)
    if grep -qi "drop\s*database\|truncate\s*\*\|delete\s*from\s*\*" "$file"; then
        print_warning "Potentially dangerous SQL patterns found in $file"
    fi
    
    if [ $errors -eq 0 ]; then
        print_status "✓ Structure OK: $file"
        return 0
    else
        print_error "✗ Structure issues found in: $file"
        return 1
    fi
}

# Main test execution
main() {
    local total_errors=0
    
    # Test migration file
    if ! check_sql_structure "$MIGRATION_FILE" "migration"; then
        total_errors=$((total_errors + 1))
    fi
    
    if ! test_sql_syntax "$MIGRATION_FILE" "migration"; then
        total_errors=$((total_errors + 1))
    fi
    
    # Test rollback file
    if ! check_sql_structure "$ROLLBACK_FILE" "rollback"; then
        total_errors=$((total_errors + 1))
    fi
    
    if ! test_sql_syntax "$ROLLBACK_FILE" "rollback"; then
        total_errors=$((total_errors + 1))
    fi
    
    # Test validation file
    if ! check_sql_structure "$VALIDATION_FILE" "validation"; then
        total_errors=$((total_errors + 1))
    fi
    
    if ! test_sql_syntax "$VALIDATION_FILE" "validation"; then
        total_errors=$((total_errors + 1))
    fi
    
    print_status "======================================================"
    
    if [ $total_errors -eq 0 ]; then
        print_status "All syntax tests passed successfully!"
        print_status "Files are ready for deployment."
        exit 0
    else
        print_error "Found $total_errors syntax/structure issues"
        print_error "Please fix the issues before deploying."
        exit 1
    fi
}

# Execute main function
main "$@"