#!/bin/bash

# Local Frontend Server for Warehouse Dashboard
# Runs Vite dev server

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}=========================================="
echo "Starting Frontend Server (Vite)"
echo -e "==========================================${NC}"
echo ""

# Check if frontend directory exists
if [ ! -d "frontend" ]; then
    echo -e "${RED}[✗]${NC} Frontend directory not found"
    exit 1
fi

cd frontend

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    echo -e "${YELLOW}[!]${NC} Installing dependencies..."
    npm install
    echo -e "${GREEN}[✓]${NC} Dependencies installed"
fi

# Create local environment file for frontend
cat > .env.local <<EOF
VITE_API_BASE_URL=http://localhost:8080
VITE_APP_ENV=development
EOF

echo -e "${GREEN}[✓]${NC} Created frontend .env.local"
echo ""
echo -e "${BLUE}Frontend server starting...${NC}"
echo "  URL: http://localhost:5173"
echo "  API: http://localhost:8080"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop${NC}"
echo ""

# Start Vite dev server
npm run dev
