# Responsive Design Testing - Quick Start Guide

## 🚀 Quick Test Commands

### Run Automated Tests

```bash
cd frontend
npm test -- responsive-design.test.tsx
```

**Expected Result:** All 15 tests should pass ✅

---

## 🎨 Visual Testing Tool

### Open the Interactive Testing Interface

```bash
# Option 1: Direct file open
open scripts/test_responsive_design.html

# Option 2: Serve locally
python3 -m http.server 8000
# Then open: http://localhost:8000/scripts/test_responsive_design.html
```

### How to Use:

1. Click preset viewport buttons (Mobile, Tablet, Desktop)
2. Or enter custom width and click "Apply"
3. Check off items in the verification checklist
4. Your progress is automatically saved

---

## 📱 Browser DevTools Testing

### Chrome/Edge:

1. Open https://www.market-mi.ru/warehouse-dashboard
2. Press `F12` (or `Cmd+Option+I` on Mac)
3. Click device toolbar icon (or `Cmd+Shift+M` / `Ctrl+Shift+M`)
4. Select device or enter custom dimensions

### Test These Viewports:

-   **Mobile:** 375px, 414px
-   **Tablet:** 768px, 1024px
-   **Desktop:** 1280px, 1920px

---

## ✅ What to Verify

### Mobile (< 768px)

-   ✅ Metrics: 1 column (stacked)
-   ✅ Filters: 1 column (stacked)
-   ✅ Table: Scrolls horizontally

### Tablet (768px - 1023px)

-   ✅ Metrics: 2 columns
-   ✅ Filters: 2 columns
-   ✅ Proper spacing

### Desktop (1024px+)

-   ✅ Metrics: 4 columns
-   ✅ Filters: 4 columns
-   ✅ Table: All columns visible

---

## 🐛 Troubleshooting

### Tests Fail?

```bash
# Check if dependencies are installed
cd frontend
npm install

# Run tests with verbose output
npm test -- responsive-design.test.tsx --reporter=verbose
```

### Visual Tool Not Loading?

-   Ensure you're serving from the project root
-   Check browser console for errors
-   Try a different browser

### Layout Looks Wrong?

1. Hard refresh: `Cmd+Shift+R` (Mac) or `Ctrl+Shift+R` (Windows)
2. Clear browser cache
3. Check if CSS file loaded (Network tab in DevTools)
4. Verify Tailwind classes in Elements inspector

---

## 📊 Test Results

All tests passing? Great! ✅

See full report: `RESPONSIVE_DESIGN_TESTING_REPORT.md`

---

## 🔗 Related Files

-   **Test File:** `frontend/src/tests/responsive-design.test.tsx`
-   **Visual Tool:** `scripts/test_responsive_design.html`
-   **Full Report:** `RESPONSIVE_DESIGN_TESTING_REPORT.md`
-   **Task Spec:** `.kiro/specs/warehouse-dashboard-fix/tasks.md`

---

## 💡 Pro Tips

1. **Use the visual tool** for quick manual verification
2. **Run automated tests** before committing changes
3. **Test on real devices** when possible
4. **Check all breakpoints** when making CSS changes
5. **Save your checklist progress** - it persists in localStorage

---

**Status:** ✅ All responsive design tests completed and passing!
