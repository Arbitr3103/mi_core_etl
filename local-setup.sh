#!/bin/bash

# Local Development Setup Script for Warehouse Dashboard
# Runs on macOS (localhost)

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}=========================================="
echo "Warehouse Dashboard - Local Setup"
echo -e "==========================================${NC}"
echo ""

# Function to print colored output
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

# Step 1: Check prerequisites
print_info "Step 1: Checking prerequisites..."

if ! command -v node &> /dev/null; then
    print_error "Node.js not found. Please install Node.js 18+"
    exit 1
fi
print_status "Node.js $(node --version) found"

if ! command -v npm &> /dev/null; then
    print_error "npm not found. Please install npm"
    exit 1
fi
print_status "npm $(npm --version) found"

if ! command -v psql &> /dev/null; then
    print_error "PostgreSQL not found. Please install PostgreSQL"
    exit 1
fi
print_status "PostgreSQL $(psql --version | awk '{print $3}') found"

if ! command -v php &> /dev/null; then
    print_error "PHP not found. Please install PHP 8.1+"
    exit 1
fi
print_status "PHP $(php --version | head -1 | awk '{print $2}') found"

# Step 2: Check PostgreSQL is running
print_info "Step 2: Checking PostgreSQL status..."
if brew services list | grep -q "postgresql.*started"; then
    print_status "PostgreSQL is running"
else
    print_warning "PostgreSQL is not running. Starting..."
    brew services start postgresql@14
    sleep 3
    print_status "PostgreSQL started"
fi

# Step 3: Check database exists
print_info "Step 3: Checking database..."
if psql -lqt | cut -d \| -f 1 | grep -qw mi_core_db; then
    print_status "Database 'mi_core_db' exists"
else
    print_warning "Database 'mi_core_db' not found. Creating..."
    createdb mi_core_db
    print_status "Database created"
fi

# Step 4: Create database schema (if needed)
print_info "Step 4: Setting up database schema..."

# Check if view exists
VIEW_EXISTS=$(psql -d mi_core_db -tAc "SELECT EXISTS (SELECT 1 FROM information_schema.views WHERE table_name = 'v_detailed_inventory');")

if [ "$VIEW_EXISTS" = "t" ]; then
    print_status "Database view 'v_detailed_inventory' already exists"
else
    print_warning "Database view not found. Creating..."
    if [ -f "sql/optimize_detailed_inventory_view.sql" ]; then
        psql -d mi_core_db -f sql/optimize_detailed_inventory_view.sql > /dev/null 2>&1
        print_status "Database view created"
    else
        print_warning "View SQL file not found. Will create with sample data later."
    fi
fi

# Step 5: Create necessary directories
print_info "Step 5: Creating directories..."
mkdir -p storage/cache/inventory
mkdir -p storage/logs
mkdir -p logs/api
mkdir -p logs/frontend
print_status "Directories created"

# Step 6: Set permissions
print_info "Step 6: Setting permissions..."
chmod -R 775 storage
chmod -R 775 logs
print_status "Permissions set"

# Step 7: Install frontend dependencies
print_info "Step 7: Installing frontend dependencies..."
if [ -d "frontend/node_modules" ]; then
    print_status "Frontend dependencies already installed"
else
    cd frontend
    npm install
    cd ..
    print_status "Frontend dependencies installed"
fi

echo ""
echo -e "${GREEN}=========================================="
echo "Setup Complete!"
echo -e "==========================================${NC}"
echo ""
echo -e "${BLUE}Next steps:${NC}"
echo "  1. Start backend:  ./local-backend.sh"
echo "  2. Start frontend: ./local-frontend.sh"
echo "  3. Open browser:   http://localhost:5173"
echo ""
echo -e "${YELLOW}Note:${NC} Run backend and frontend in separate terminal windows"
echo ""
