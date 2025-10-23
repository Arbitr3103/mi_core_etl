#!/bin/bash

# React Frontend Build Script
# This script builds the React application for production

set -e  # Exit on error

echo "=========================================="
echo "React Frontend Build Script"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Function to print colored output
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    print_warning "node_modules not found. Installing dependencies..."
    npm install
fi

# Clean previous build
if [ -d "dist" ]; then
    print_status "Cleaning previous build..."
    rm -rf dist
fi

# Type check
print_status "Running type check..."
npm run type-check

# Lint check
print_status "Running linter..."
npm run lint || print_warning "Linting warnings found (continuing...)"

# Run tests
print_status "Running tests..."
npm run test || {
    print_error "Tests failed!"
    exit 1
}

# Build for production
print_status "Building for production..."
NODE_ENV=production npm run build

# Check if build was successful
if [ ! -d "dist" ]; then
    print_error "Build failed! dist directory not created."
    exit 1
fi

# Display build statistics
print_status "Build completed successfully!"
echo ""
echo "Build Statistics:"
echo "----------------------------------------"
du -sh dist
echo ""
echo "Files:"
find dist -type f -name "*.js" -o -name "*.css" | while read file; do
    size=$(du -h "$file" | cut -f1)
    echo "  $size - $(basename $file)"
done
echo ""

# Create build info file
BUILD_DATE=$(date -u +"%Y-%m-%d %H:%M:%S UTC")
BUILD_HASH=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
cat > dist/build-info.json <<EOF
{
  "buildDate": "$BUILD_DATE",
  "gitHash": "$BUILD_HASH",
  "nodeVersion": "$(node --version)",
  "npmVersion": "$(npm --version)"
}
EOF

print_status "Build info saved to dist/build-info.json"
echo ""
echo "=========================================="
echo "Build completed successfully!"
echo "=========================================="
