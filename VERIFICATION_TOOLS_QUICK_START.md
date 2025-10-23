# Verification Tools - Quick Start Guide

## Overview

This guide provides quick instructions for using the production verification tools created for the Warehouse Dashboard.

---

## Available Tools

### 1. Production Verification Script

**Purpose:** Automated server-side verification  
**File:** `scripts/production_verification.sh`  
**Type:** Bash script

### 2. Browser Verification Tool

**Purpose:** Interactive browser-based testing  
**File:** `scripts/browser_verification.html`  
**Type:** HTML/JavaScript

### 3. Performance Verification Script

**Purpose:** Detailed performance analysis  
**File:** `scripts/performance_verification.sh`  
**Type:** Bash script

---

## Quick Start

### Option 1: Run All Verifications (Recommended)

```bash
# Make scripts executable
chmod +x scripts/production_verification.sh
chmod +x scripts/performance_verification.sh

# Run production verification
bash scripts/production_verification.sh

# Run performance verification
bash scripts/performance_verification.sh

# Open browser verification tool
open scripts/browser_verification.html
```

### Option 2: Individual Tool Usage

#### Production Verification

```bash
bash scripts/production_verification.sh
```

**What it checks:**

-   Page loading (HTTP status)
-   HTML structure
-   CSS and JS file loading
-   API endpoint functionality
-   Resource availability

**Output:**

-   Console output with ✓/✗ indicators
-   JSON report: `production_verification_report_*.json`

#### Performance Verification

```bash
bash scripts/performance_verification.sh
```

**What it measures:**

-   Page load time (5 attempts, averaged)
-   API response time (5 attempts, averaged)
-   Resource sizes (CSS, JS, HTML)
-   Compression and caching
-   Network efficiency

**Output:**

-   Console output with performance metrics
-   JSON report: `performance_verification_report_*.json`

#### Browser Verification

```bash
# Open in your default browser
open scripts/browser_verification.html

# Or specify a browser
open -a "Google Chrome" scripts/browser_verification.html
open -a "Firefox" scripts/browser_verification.html
open -a "Safari" scripts/browser_verification.html
```

**What it tests:**

-   Visual verification (6 checks)
-   Functional testing (5 checks)
-   Performance verification (4 checks)

**Features:**

-   Interactive UI
-   Real-time test execution
-   Export results to JSON
-   Preview iframe

---

## Using the Browser Verification Tool

### Step 1: Open the Tool

Open `scripts/browser_verification.html` in any browser

### Step 2: Run Tests

**Run All Tests:**
Click "Run All Tests" button

**Run Specific Tests:**

-   "Visual Tests Only" - Check layout and elements
-   "Functional Tests Only" - Test API and interactions
-   "Performance Tests Only" - Measure load times

### Step 3: Review Results

The tool displays:

-   ✓ Green checkmarks for passed tests
-   ✗ Red X marks for failed tests
-   Test summary with pass rate

### Step 4: Export Report

Click "Export Report" to download JSON results

### Step 5: Preview Dashboard

Click "Toggle Preview" to see the live dashboard in an iframe

---

## Understanding the Reports

### Production Verification Report

```json
{
  "timestamp": "2025-10-23_20-09-55",
  "url": "https://www.market-mi.ru/warehouse-dashboard/",
  "visual_verification": {
    "page_loads": true,
    "header_present": true,
    "navigation_present": true,
    ...
  },
  "resources": {
    "css_files_loaded": true,
    "js_files_loaded": true,
    ...
  },
  "overall_status": "success"
}
```

**Status Values:**

-   `success` - All checks passed
-   `warning` - Most checks passed, some issues
-   `failure` - Critical issues detected

### Performance Verification Report

```json
{
  "timestamp": "2025-10-23_20-15-25",
  "load_time": {
    "average_seconds": 0.251,
    "threshold_seconds": 3.0,
    "status": "pass",
    "measurements": [0.276, 0.237, ...]
  },
  "api_response": {
    "average_seconds": 0.534,
    ...
  },
  "summary": {
    "pass_rate": "88.88%",
    "overall_status": "good"
  }
}
```

**Overall Status Values:**

-   `excellent` - All tests passed
-   `good` - 80%+ tests passed
-   `acceptable` - 60-79% tests passed
-   `poor` - < 60% tests passed

---

## Interpreting Results

### ✅ All Green (100% Pass Rate)

**Status:** Production ready  
**Action:** None required

### ⚠️ Mostly Green (80-99% Pass Rate)

**Status:** Good, minor issues  
**Action:** Review failed tests, fix if critical

### ⚠️ Mixed Results (60-79% Pass Rate)

**Status:** Acceptable, needs attention  
**Action:** Investigate and fix failed tests

### ❌ Mostly Red (< 60% Pass Rate)

**Status:** Critical issues  
**Action:** Do not deploy, fix issues immediately

---

## Common Issues and Solutions

### Issue: "Page failed to load (HTTP 301)"

**Cause:** Missing trailing slash in URL  
**Solution:** Use `https://www.market-mi.ru/warehouse-dashboard/` (with slash)

### Issue: "CSS files not found"

**Cause:** Build not deployed or incorrect paths  
**Solution:**

```bash
cd frontend
npm run build
# Deploy dist/ contents to server
```

### Issue: "API endpoint failed"

**Cause:** API not responding or incorrect URL  
**Solution:** Check API endpoint is accessible:

```bash
curl https://www.market-mi.ru/api/warehouse-dashboard.php
```

### Issue: "Performance tests fail"

**Cause:** Slow network or server issues  
**Solution:**

-   Check server load
-   Test from different network
-   Review server logs

---

## Automated Testing Schedule

### After Each Deployment

```bash
bash scripts/production_verification.sh
bash scripts/performance_verification.sh
```

### Weekly Spot Check

```bash
# Quick verification
bash scripts/production_verification.sh
```

### Monthly Full Test

```bash
# Run all scripts
bash scripts/production_verification.sh
bash scripts/performance_verification.sh

# Open browser tool for manual verification
open scripts/browser_verification.html
```

---

## Integration with CI/CD

### GitHub Actions Example

```yaml
name: Production Verification

on:
    deployment_status:

jobs:
    verify:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v2

            - name: Run Production Verification
              run: bash scripts/production_verification.sh

            - name: Run Performance Verification
              run: bash scripts/performance_verification.sh

            - name: Upload Reports
              uses: actions/upload-artifact@v2
              with:
                  name: verification-reports
                  path: "*_verification_report_*.json"
```

---

## Manual Browser Testing Checklist

Use this checklist when manually testing:

### Visual Verification

-   [ ] Open https://www.market-mi.ru/warehouse-dashboard/
-   [ ] Verify header at top
-   [ ] Verify navigation below header
-   [ ] Verify metrics in grid (4 columns on desktop)
-   [ ] Verify filters in grid layout
-   [ ] Verify table displays correctly
-   [ ] Verify pagination at bottom
-   [ ] Check for overlapping elements

### Functional Testing

-   [ ] Select warehouse from dropdown
-   [ ] Apply filters
-   [ ] Click table column to sort
-   [ ] Navigate pages with pagination
-   [ ] Click "Export CSV"
-   [ ] Click "Refresh"

### Responsive Testing

-   [ ] Resize browser to mobile width (375px)
-   [ ] Verify single column layout
-   [ ] Test horizontal scroll on table
-   [ ] Resize to tablet width (768px)
-   [ ] Verify 2-column layout
-   [ ] Resize to desktop width (1024px+)
-   [ ] Verify 4-column layout

### Performance Testing

-   [ ] Open DevTools (F12)
-   [ ] Go to Network tab
-   [ ] Hard refresh (Cmd+Shift+R)
-   [ ] Verify all resources load (200 status)
-   [ ] Check total load time
-   [ ] Go to Console tab
-   [ ] Verify no errors

---

## Tips and Best Practices

### 1. Always Test After Deployment

Run verification scripts immediately after deploying to catch issues early.

### 2. Test in Multiple Browsers

Use the browser verification tool in Chrome, Firefox, and Safari.

### 3. Test on Real Devices

Test on actual mobile devices, not just browser DevTools.

### 4. Keep Reports

Save verification reports for historical comparison.

### 5. Monitor Trends

Track performance metrics over time to detect degradation.

### 6. Clear Cache

Always test with cleared cache to simulate first-time users.

### 7. Test Different Networks

Test on both fast and slow connections.

---

## Troubleshooting

### Scripts Won't Run

```bash
# Make executable
chmod +x scripts/*.sh

# Check bash is available
which bash

# Run with explicit bash
bash scripts/production_verification.sh
```

### Browser Tool Won't Load

```bash
# Check file exists
ls -la scripts/browser_verification.html

# Open with specific browser
open -a "Google Chrome" scripts/browser_verification.html
```

### Reports Not Generated

```bash
# Check write permissions
ls -la .

# Run with sudo if needed (not recommended)
sudo bash scripts/production_verification.sh
```

---

## Support and Documentation

### Full Documentation

-   `PRODUCTION_VERIFICATION_REPORT.md` - Complete verification results
-   `CROSS_BROWSER_TESTING_GUIDE.md` - Detailed browser testing guide
-   `TASK_10_PRODUCTION_VERIFICATION_COMPLETE.md` - Task completion report

### Script Documentation

Each script includes inline comments explaining functionality.

### Getting Help

If you encounter issues:

1. Check the troubleshooting section above
2. Review the full documentation
3. Check script output for error messages
4. Verify production URL is accessible

---

## Quick Reference Commands

```bash
# Production verification
bash scripts/production_verification.sh

# Performance verification
bash scripts/performance_verification.sh

# Open browser tool
open scripts/browser_verification.html

# View latest report
cat production_verification_report_*.json | jq .

# Check production URL
curl -I https://www.market-mi.ru/warehouse-dashboard/

# Check API endpoint
curl https://www.market-mi.ru/api/warehouse-dashboard.php

# Test page load time
curl -s -o /dev/null -w "%{time_total}\n" https://www.market-mi.ru/warehouse-dashboard/
```

---

**Last Updated:** October 23, 2025  
**Version:** 1.0  
**Status:** Production Ready ✅
