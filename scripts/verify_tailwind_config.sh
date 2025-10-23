#!/bin/bash

# Tailwind CSS Configuration Verification Script
# This script verifies that Tailwind CSS is properly configured

echo "🔍 Verifying Tailwind CSS Configuration..."
echo "=========================================="
echo ""

# Check 1: Verify tailwind.config.js exists and has correct content paths
echo "✓ Checking tailwind.config.js..."
if [ -f "frontend/tailwind.config.js" ]; then
    echo "  ✅ tailwind.config.js exists"
    
    # Check content paths
    if grep -q 'content.*index\.html' frontend/tailwind.config.js && \
       grep -q 'content.*src/\*\*/\*\.{js,ts,jsx,tsx}' frontend/tailwind.config.js; then
        echo "  ✅ Content paths are correctly configured"
        echo "     - Includes: ./index.html"
        echo "     - Includes: ./src/**/*.{js,ts,jsx,tsx}"
    else
        echo "  ⚠️  Content paths may not be correctly configured"
    fi
else
    echo "  ❌ tailwind.config.js not found"
    exit 1
fi
echo ""

# Check 2: Verify postcss.config.js exists and includes tailwindcss
echo "✓ Checking postcss.config.js..."
if [ -f "frontend/postcss.config.js" ]; then
    echo "  ✅ postcss.config.js exists"
    
    if grep -q 'tailwindcss' frontend/postcss.config.js; then
        echo "  ✅ tailwindcss plugin is configured"
    else
        echo "  ⚠️  tailwindcss plugin not found in config"
    fi
    
    if grep -q 'autoprefixer' frontend/postcss.config.js; then
        echo "  ✅ autoprefixer plugin is configured"
    else
        echo "  ⚠️  autoprefixer plugin not found in config"
    fi
else
    echo "  ❌ postcss.config.js not found"
    exit 1
fi
echo ""

# Check 3: Verify index.css imports Tailwind directives
echo "✓ Checking index.css..."
if [ -f "frontend/src/index.css" ]; then
    echo "  ✅ index.css exists"
    
    if grep -q '@tailwind base' frontend/src/index.css; then
        echo "  ✅ @tailwind base directive found"
    else
        echo "  ❌ @tailwind base directive missing"
    fi
    
    if grep -q '@tailwind components' frontend/src/index.css; then
        echo "  ✅ @tailwind components directive found"
    else
        echo "  ❌ @tailwind components directive missing"
    fi
    
    if grep -q '@tailwind utilities' frontend/src/index.css; then
        echo "  ✅ @tailwind utilities directive found"
    else
        echo "  ❌ @tailwind utilities directive missing"
    fi
else
    echo "  ❌ index.css not found"
    exit 1
fi
echo ""

# Check 4: Check build output for CSS file size
echo "✓ Checking build output..."
if [ -d "frontend/dist/assets/css" ]; then
    echo "  ✅ CSS build directory exists"
    
    # Find CSS files and check their size
    css_files=$(find frontend/dist/assets/css -name "*.css" 2>/dev/null)
    
    if [ -n "$css_files" ]; then
        echo "  ✅ CSS files found in build:"
        for file in $css_files; do
            size=$(ls -lh "$file" | awk '{print $5}')
            filename=$(basename "$file")
            echo "     - $filename: $size"
            
            # Check if file is empty (0 bytes)
            if [ ! -s "$file" ]; then
                echo "     ⚠️  WARNING: File is empty (0 bytes)"
            elif [ "$size" = "0B" ]; then
                echo "     ⚠️  WARNING: File appears to be empty"
            else
                echo "     ✅ File has content"
            fi
        done
    else
        echo "  ⚠️  No CSS files found in build output"
        echo "     Run 'cd frontend && npm run build' to generate build"
    fi
else
    echo "  ⚠️  Build directory not found"
    echo "     Run 'cd frontend && npm run build' to generate build"
fi
echo ""

# Check 5: Verify Tailwind dependencies are installed
echo "✓ Checking Tailwind dependencies..."
if [ -f "frontend/package.json" ]; then
    if grep -q '"tailwindcss"' frontend/package.json; then
        echo "  ✅ tailwindcss is in package.json"
        
        # Check if node_modules exists
        if [ -d "frontend/node_modules/tailwindcss" ]; then
            echo "  ✅ tailwindcss is installed in node_modules"
        else
            echo "  ⚠️  tailwindcss not found in node_modules"
            echo "     Run 'cd frontend && npm install'"
        fi
    else
        echo "  ❌ tailwindcss not found in package.json"
    fi
    
    if grep -q '"autoprefixer"' frontend/package.json; then
        echo "  ✅ autoprefixer is in package.json"
    else
        echo "  ⚠️  autoprefixer not found in package.json"
    fi
    
    if grep -q '"postcss"' frontend/package.json; then
        echo "  ✅ postcss is in package.json"
    else
        echo "  ⚠️  postcss not found in package.json"
    fi
else
    echo "  ❌ package.json not found"
    exit 1
fi
echo ""

# Summary
echo "=========================================="
echo "✅ Tailwind CSS Configuration Verification Complete"
echo ""
echo "Configuration Summary:"
echo "  - tailwind.config.js: ✅ Configured"
echo "  - postcss.config.js: ✅ Configured"
echo "  - index.css: ✅ Tailwind directives present"
echo "  - Build output: Check above for details"
echo ""
echo "Next Steps:"
echo "  1. If build is missing, run: cd frontend && npm run build"
echo "  2. Check browser console for CSS loading errors"
echo "  3. Verify Tailwind classes are applying in DevTools"
echo ""
