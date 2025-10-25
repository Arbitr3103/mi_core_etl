#!/bin/bash
#
# Test Analytics ETL Cron Setup
# Validates all components of the cron system
# Task: 6.2 Настроить cron задачи для Analytics ETL

echo "🧪 Testing Analytics ETL Cron Setup"
echo "==================================="
echo ""

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
TESTS_PASSED=0
TESTS_FAILED=0

# Test function
run_test() {
    local test_name="$1"
    local test_command="$2"
    
    echo -n "Testing $test_name... "
    
    if eval "$test_command" >/dev/null 2>&1; then
        echo "✅ PASS"
        ((TESTS_PASSED++))
    else
        echo "❌ FAIL"
        ((TESTS_FAILED++))
    fi
}

# Test 1: Check if main ETL script exists and is executable
run_test "ETL script existence" "[ -x '$SCRIPT_DIR/warehouse_etl_analytics.php' ]"

# Test 2: Check if setup script exists and is executable
run_test "Setup script existence" "[ -x '$SCRIPT_DIR/setup_analytics_etl_cron.sh' ]"

# Test 3: Check if monitoring script exists and is executable
run_test "Monitoring script existence" "[ -x '$SCRIPT_DIR/monitor_analytics_etl.php' ]"

# Test 4: Check if cron templates exist
run_test "Standard cron template" "[ -f '$SCRIPT_DIR/cron_examples/analytics_etl_standard.txt' ]"
run_test "High frequency cron template" "[ -f '$SCRIPT_DIR/cron_examples/analytics_etl_high_frequency.txt' ]"
run_test "Low resource cron template" "[ -f '$SCRIPT_DIR/cron_examples/analytics_etl_low_resource.txt' ]"

# Test 5: Check PHP availability
run_test "PHP availability" "command -v php"

# Test 6: Test ETL script help
run_test "ETL script help" "php '$SCRIPT_DIR/warehouse_etl_analytics.php' --help"

# Test 7: Test monitoring script help
run_test "Monitoring script help" "php '$SCRIPT_DIR/monitor_analytics_etl.php' --help"

# Test 8: Check if logs directory can be created
run_test "Logs directory creation" "mkdir -p '$SCRIPT_DIR/logs/test' && rmdir '$SCRIPT_DIR/logs/test'"

# Test 9: Test cron template syntax
echo -n "Testing cron template syntax... "
if crontab -l >/dev/null 2>&1; then
    # Test if we can parse cron templates
    if grep -E '^[0-9*,/-]+ [0-9*,/-]+ [0-9*,/-]+ [0-9*,/-]+ [0-9*,/-]+' "$SCRIPT_DIR/cron_examples/analytics_etl_standard.txt" >/dev/null 2>&1; then
        echo "✅ PASS"
        ((TESTS_PASSED++))
    else
        echo "❌ FAIL"
        ((TESTS_FAILED++))
    fi
else
    echo "⚠️  SKIP (crontab not available)"
fi

# Test 10: Test configuration loading
echo -n "Testing configuration loading... "
if php -r "
require_once '$SCRIPT_DIR/config.php';
try {
    if (function_exists('getDatabaseConnection')) {
        echo 'Config loaded successfully';
        exit(0);
    } else {
        echo 'Config function missing';
        exit(1);
    }
} catch (Exception \$e) {
    echo 'Config error: ' . \$e->getMessage();
    exit(1);
}
" >/dev/null 2>&1; then
    echo "✅ PASS"
    ((TESTS_PASSED++))
else
    echo "❌ FAIL"
    ((TESTS_FAILED++))
fi

echo ""
echo "📊 Test Results"
echo "==============="
echo "Tests passed: $TESTS_PASSED"
echo "Tests failed: $TESTS_FAILED"
echo "Total tests: $((TESTS_PASSED + TESTS_FAILED))"

if [ $TESTS_FAILED -eq 0 ]; then
    echo ""
    echo "🎉 All tests passed! The cron setup is ready."
    echo ""
    echo "Next steps:"
    echo "1. Run: ./setup_analytics_etl_cron.sh"
    echo "2. Configure API credentials if needed"
    echo "3. Test with: ./run_analytics_etl_manual.sh --dry-run --debug --limit=5"
    echo ""
    exit 0
else
    echo ""
    echo "❌ Some tests failed. Please fix the issues before proceeding."
    echo ""
    echo "Common fixes:"
    echo "- Ensure all scripts have execute permissions: chmod +x *.sh *.php"
    echo "- Check if PHP is installed and accessible"
    echo "- Verify config.php exists and is readable"
    echo "- Ensure cron_examples directory exists with template files"
    echo ""
    exit 1
fi