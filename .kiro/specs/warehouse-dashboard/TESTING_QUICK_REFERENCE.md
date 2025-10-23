# Warehouse Dashboard - Testing Quick Reference

Quick guide for running all tests and verifications.

---

## 🚀 Quick Start

### 1. Verify Implementation (No Server Required)

```bash
bash .kiro/specs/warehouse-dashboard/verify_implementation.sh
```

**Expected:** All 51 checks pass ✅

### 2. Run Full Test Suite (Requires Server)

```bash
bash .kiro/specs/warehouse-dashboard/run_final_tests.sh
```

**Expected:** All automated tests pass ✅

---

## 📋 Test Categories

### Backend Tests

#### Unit Tests

```bash
# All backend unit tests
vendor/bin/phpunit tests/Unit/ --testdox

# Specific test files
vendor/bin/phpunit tests/Unit/WarehouseServiceTest.php
vendor/bin/phpunit tests/Unit/WarehouseSalesAnalyticsServiceTest.php
vendor/bin/phpunit tests/Unit/ReplenishmentCalculatorTest.php
```

#### Integration Tests

```bash
vendor/bin/phpunit tests/Integration/WarehouseDashboardAPIIntegrationTest.php
```

### Frontend Tests

#### Unit Tests

```bash
cd frontend

# All tests
npm test -- --run

# Specific test files
npm test -- src/components/warehouse/__tests__/ --run
npm test -- src/hooks/__tests__/useWarehouse.test.ts --run
npm test -- src/utils/__tests__/ --run
```

#### Integration Tests

```bash
cd frontend
npm test -- src/__tests__/integration/ --run
```

#### Watch Mode (Development)

```bash
cd frontend
npm test
```

---

## 🔍 Manual Testing

### 1. Open Testing Checklist

```bash
open .kiro/specs/warehouse-dashboard/final_testing_checklist.md
```

Follow the 132 test cases documented.

### 2. Mobile Responsiveness Testing

```bash
# Open in browser
open .kiro/specs/warehouse-dashboard/test_mobile_responsiveness.html
```

Or navigate to: `file:///.../test_mobile_responsiveness.html`

### 3. Browser Testing

Test in:

-   Chrome (latest)
-   Firefox (latest)
-   Safari (latest)
-   Edge (latest)
-   Mobile browsers (Safari iOS, Chrome Android)

---

## 📊 Generate Test Report

```bash
bash .kiro/specs/warehouse-dashboard/generate_test_report.sh
```

Creates: `FINAL_TEST_REPORT_YYYYMMDD_HHMMSS.md`

---

## 🎯 Specific Test Scenarios

### Test Filters

1. Navigate to `/warehouse-dashboard`
2. Test each filter:
    - Warehouse dropdown
    - Cluster dropdown
    - Liquidity status
    - Active only checkbox
    - Replenishment need checkbox
3. Test combined filters
4. Verify data updates correctly

### Test Sorting

1. Click each column header
2. Verify sort direction indicator
3. Test ascending/descending
4. Verify data order changes

### Test Export

1. Apply filters
2. Click "Export CSV"
3. Verify file downloads
4. Open CSV and verify data matches display

### Test Performance

1. Load page with large dataset (>100 items)
2. Verify pagination appears
3. Test filter response time (<500ms)
4. Check for smooth scrolling

### Test Mobile

1. Open mobile testing tool
2. Select different viewports
3. Verify layout adapts
4. Test touch interactions

---

## 🐛 Debugging Tests

### Backend Test Failures

```bash
# Run with verbose output
vendor/bin/phpunit tests/Unit/WarehouseServiceTest.php --verbose

# Run specific test method
vendor/bin/phpunit tests/Unit/WarehouseServiceTest.php --filter testGetDashboardData
```

### Frontend Test Failures

```bash
cd frontend

# Run with verbose output
npm test -- --run --reporter=verbose

# Run specific test file
npm test -- src/components/warehouse/__tests__/WarehouseTable.test.tsx --run

# Run in UI mode (interactive)
npm test -- --ui
```

### API Testing

```bash
# Test API endpoint directly
curl http://localhost:8000/api/warehouse-dashboard.php

# Test with filters
curl "http://localhost:8000/api/warehouse-dashboard.php?warehouse=test&active_only=true"

# Test export
curl "http://localhost:8000/api/warehouse-dashboard.php?export=csv" -o test.csv
```

---

## ✅ Pre-Deployment Checklist

Run these before deploying:

```bash
# 1. Verify implementation
bash .kiro/specs/warehouse-dashboard/verify_implementation.sh

# 2. Run backend tests
vendor/bin/phpunit tests/Unit/ --testdox
vendor/bin/phpunit tests/Integration/WarehouseDashboardAPIIntegrationTest.php

# 3. Run frontend tests
cd frontend && npm test -- --run && cd ..

# 4. Build frontend
cd frontend && npm run build && cd ..

# 5. Check for TypeScript errors
cd frontend && npm run type-check && cd ..

# 6. Check for linting errors
cd frontend && npm run lint && cd ..
```

---

## 📈 Performance Testing

### Measure API Response Time

```bash
# Simple timing
time curl -s http://localhost:8000/api/warehouse-dashboard.php > /dev/null

# Detailed timing
curl -w "@-" -o /dev/null -s http://localhost:8000/api/warehouse-dashboard.php <<'EOF'
    time_namelookup:  %{time_namelookup}\n
       time_connect:  %{time_connect}\n
    time_appconnect:  %{time_appconnect}\n
   time_pretransfer:  %{time_pretransfer}\n
      time_redirect:  %{time_redirect}\n
 time_starttransfer:  %{time_starttransfer}\n
                    ----------\n
         time_total:  %{time_total}\n
EOF
```

### Measure Frontend Load Time

1. Open DevTools (F12)
2. Go to Network tab
3. Reload page
4. Check "Load" time at bottom
5. Should be < 2 seconds

---

## 🔧 Test Data Management

### Validate Import Data

```bash
python3 importers/validate_import.py
```

### Validate Metrics Calculation

```bash
python3 importers/validate_metrics.py
```

### Refresh Metrics

```bash
php scripts/refresh_warehouse_metrics.php
```

### Check Database

```bash
# Check if tables exist
psql -U postgres -d basebuy -c "\dt warehouse_sales_metrics"

# Check data count
psql -U postgres -d basebuy -c "SELECT COUNT(*) FROM warehouse_sales_metrics"

# Check sample data
psql -U postgres -d basebuy -c "SELECT * FROM warehouse_sales_metrics LIMIT 5"
```

---

## 📱 Mobile Device Testing

### iOS (Safari)

1. Open Safari on iPhone/iPad
2. Navigate to dashboard URL
3. Test all interactions
4. Check layout at different orientations

### Android (Chrome)

1. Open Chrome on Android device
2. Navigate to dashboard URL
3. Test all interactions
4. Check layout at different orientations

### Browser DevTools Simulation

1. Open DevTools (F12)
2. Click device toolbar icon (Ctrl+Shift+M)
3. Select device from dropdown
4. Test responsive behavior

---

## 🎨 Visual Testing

### Check Color Contrast

1. Use browser extension (e.g., WAVE, axe DevTools)
2. Verify WCAG AA compliance
3. Check all status colors (red, yellow, green, blue)

### Check Typography

1. Verify font sizes (min 14px on mobile)
2. Check line heights
3. Verify readability

### Check Spacing

1. Verify consistent padding/margins
2. Check touch target sizes (min 44px)
3. Verify table cell spacing

---

## 🚨 Common Issues & Solutions

### Issue: Tests fail with "Connection refused"

**Solution:** Start the development server first

```bash
# Backend
php -S localhost:8000

# Frontend
cd frontend && npm run dev
```

### Issue: Frontend tests fail with module errors

**Solution:** Install dependencies

```bash
cd frontend && npm install
```

### Issue: Backend tests fail with database errors

**Solution:** Check database connection and run migrations

```bash
psql -U postgres -d basebuy -f migrations/warehouse_dashboard_schema.sql
```

### Issue: Import validation fails

**Solution:** Check if data files exist

```bash
ls -la importers/test_data/
```

---

## 📞 Support

### Documentation

-   User Guide: `docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md`
-   API Docs: `docs/WAREHOUSE_DASHBOARD_API.md`
-   Requirements: `.kiro/specs/warehouse-dashboard/requirements.md`
-   Design: `.kiro/specs/warehouse-dashboard/design.md`

### Test Documentation

-   Testing Checklist: `.kiro/specs/warehouse-dashboard/final_testing_checklist.md`
-   Task Summary: `.kiro/specs/warehouse-dashboard/TASK_11.3_COMPLETION_SUMMARY.md`
-   Project Summary: `.kiro/specs/warehouse-dashboard/PROJECT_COMPLETION_SUMMARY.md`

---

## ✨ Quick Commands Summary

```bash
# Verify everything is in place
bash .kiro/specs/warehouse-dashboard/verify_implementation.sh

# Run all automated tests
bash .kiro/specs/warehouse-dashboard/run_final_tests.sh

# Backend unit tests
vendor/bin/phpunit tests/Unit/ --testdox

# Frontend unit tests
cd frontend && npm test -- --run

# Generate test report
bash .kiro/specs/warehouse-dashboard/generate_test_report.sh

# Open mobile testing tool
open .kiro/specs/warehouse-dashboard/test_mobile_responsiveness.html
```

---

**Happy Testing! 🎉**
