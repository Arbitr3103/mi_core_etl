#!/bin/bash

# Final Cleanup and Optimization Script
# This script performs final cleanup and optimization tasks

set -e

echo "╔════════════════════════════════════════════════════════════╗"
echo "║         MI Core ETL - Final Cleanup & Optimization        ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print section headers
print_header() {
    echo ""
    echo "┌─────────────────────────────────────────────────────────────┐"
    printf "│ %-59s │\n" "$1"
    echo "└─────────────────────────────────────────────────────────────┘"
}

# Function to print success
print_success() {
    echo -e "  ${GREEN}✓${NC} $1"
}

# Function to print warning
print_warning() {
    echo -e "  ${YELLOW}⚠${NC} $1"
}

# Function to print error
print_error() {
    echo -e "  ${RED}✗${NC} $1"
}

# Function to print info
print_info() {
    echo -e "  ${BLUE}ℹ${NC} $1"
}

# Check if running from project root
if [ ! -f "composer.json" ]; then
    print_error "Please run this script from the project root directory"
    exit 1
fi

# 1. Check for garbage files
print_header "Checking for Garbage Files"

GARBAGE_PATTERNS=(
    "*_old.*"
    "*_backup.*"
    "*_test.*"
    "*_copy.*"
    "*.bak"
    "*.tmp"
    "*~"
    ".DS_Store"
)

FOUND_GARBAGE=0
for pattern in "${GARBAGE_PATTERNS[@]}"; do
    files=$(find . -name "$pattern" -not -path "./node_modules/*" -not -path "./vendor/*" -not -path "./.git/*" 2>/dev/null || true)
    if [ -n "$files" ]; then
        FOUND_GARBAGE=1
        print_warning "Found files matching pattern: $pattern"
        echo "$files" | while read -r file; do
            echo "    - $file"
        done
    fi
done

if [ $FOUND_GARBAGE -eq 0 ]; then
    print_success "No garbage files found"
else
    print_warning "Found garbage files - consider removing them"
fi

# 2. Check for large log files
print_header "Checking Log Files"

if [ -d "storage/logs" ]; then
    LOG_SIZE=$(du -sh storage/logs 2>/dev/null | cut -f1 || echo "0")
    print_info "Total log size: $LOG_SIZE"
    
    # Find large log files (>10MB)
    LARGE_LOGS=$(find storage/logs -type f -size +10M 2>/dev/null || true)
    if [ -n "$LARGE_LOGS" ]; then
        print_warning "Found large log files (>10MB):"
        echo "$LARGE_LOGS" | while read -r file; do
            size=$(du -h "$file" | cut -f1)
            echo "    - $file ($size)"
        done
        print_info "Consider rotating or archiving these logs"
    else
        print_success "No large log files found"
    fi
else
    print_warning "Log directory not found"
fi

# 3. Check cache size
print_header "Checking Cache"

if [ -d "storage/cache" ]; then
    CACHE_SIZE=$(du -sh storage/cache 2>/dev/null | cut -f1 || echo "0")
    print_info "Cache size: $CACHE_SIZE"
    
    # Count cache files
    CACHE_COUNT=$(find storage/cache -type f 2>/dev/null | wc -l || echo "0")
    print_info "Cache files: $CACHE_COUNT"
    
    if [ "$CACHE_COUNT" -gt 1000 ]; then
        print_warning "Large number of cache files - consider clearing old cache"
    else
        print_success "Cache size is reasonable"
    fi
else
    print_warning "Cache directory not found"
fi

# 4. Check for duplicate files
print_header "Checking for Duplicate Files"

# Check for common duplicate patterns
DUPLICATE_PATTERNS=(
    "dashboard*.php"
    "inventory*.php"
    "test*.html"
)

for pattern in "${DUPLICATE_PATTERNS[@]}"; do
    files=$(find . -name "$pattern" -not -path "./node_modules/*" -not -path "./vendor/*" -not -path "./.git/*" -not -path "./frontend/*" 2>/dev/null || true)
    count=$(echo "$files" | grep -c . || echo "0")
    if [ "$count" -gt 3 ]; then
        print_warning "Found $count files matching pattern: $pattern"
        print_info "Consider consolidating these files"
    fi
done

print_success "Duplicate file check complete"

# 5. Check database indexes (if PostgreSQL is available)
print_header "Checking Database Optimization"

if command -v psql &> /dev/null; then
    # Load database config from .env
    if [ -f ".env" ]; then
        export $(grep -v '^#' .env | xargs)
        
        # Check for unused indexes
        UNUSED_INDEXES=$(psql -h "${PG_HOST:-localhost}" -U "${PG_USER:-mi_core_user}" -d "${PG_NAME:-mi_core_db}" -t -c "
            SELECT schemaname || '.' || tablename || '.' || indexname as index_name
            FROM pg_stat_user_indexes
            WHERE idx_scan = 0 AND idx_tup_read = 0
            LIMIT 5;
        " 2>/dev/null || echo "")
        
        if [ -n "$UNUSED_INDEXES" ] && [ "$UNUSED_INDEXES" != " " ]; then
            print_warning "Found potentially unused indexes:"
            echo "$UNUSED_INDEXES" | while read -r index; do
                if [ -n "$index" ] && [ "$index" != " " ]; then
                    echo "    - $index"
                fi
            done
            print_info "Review these indexes for potential removal"
        else
            print_success "All indexes appear to be in use"
        fi
        
        # Check table bloat
        print_info "Checking for table bloat..."
        BLOAT_CHECK=$(psql -h "${PG_HOST:-localhost}" -U "${PG_USER:-mi_core_user}" -d "${PG_NAME:-mi_core_db}" -t -c "
            SELECT COUNT(*) FROM pg_stat_user_tables WHERE n_dead_tup > 1000;
        " 2>/dev/null || echo "0")
        
        if [ "$BLOAT_CHECK" -gt 0 ]; then
            print_warning "Found $BLOAT_CHECK tables with significant dead tuples"
            print_info "Consider running VACUUM ANALYZE"
        else
            print_success "No significant table bloat detected"
        fi
    else
        print_warning ".env file not found - skipping database checks"
    fi
else
    print_warning "PostgreSQL client not available - skipping database checks"
fi

# 6. Check frontend build
print_header "Checking Frontend Build"

if [ -d "frontend/dist" ]; then
    BUILD_SIZE=$(du -sh frontend/dist 2>/dev/null | cut -f1 || echo "0")
    print_info "Frontend build size: $BUILD_SIZE"
    
    # Check if build is deployed to public
    if [ -d "public/build" ]; then
        PUBLIC_SIZE=$(du -sh public/build 2>/dev/null | cut -f1 || echo "0")
        print_success "Frontend deployed to public/ ($PUBLIC_SIZE)"
    else
        print_warning "Frontend not deployed to public/ - run deployment script"
    fi
    
    # Check for source maps in production
    if [ -f "frontend/dist/assets/*.map" ]; then
        print_warning "Source maps found in production build"
        print_info "Consider removing source maps for production"
    else
        print_success "No source maps in production build"
    fi
else
    print_warning "Frontend build not found - run 'npm run build'"
fi

# 7. Check file permissions
print_header "Checking File Permissions"

# Check storage directory permissions
if [ -d "storage" ]; then
    STORAGE_PERMS=$(stat -f "%A" storage 2>/dev/null || stat -c "%a" storage 2>/dev/null || echo "unknown")
    if [ "$STORAGE_PERMS" = "755" ] || [ "$STORAGE_PERMS" = "775" ]; then
        print_success "Storage directory permissions are correct ($STORAGE_PERMS)"
    else
        print_warning "Storage directory permissions: $STORAGE_PERMS (should be 755 or 775)"
    fi
fi

# Check .env permissions
if [ -f ".env" ]; then
    ENV_PERMS=$(stat -f "%A" .env 2>/dev/null || stat -c "%a" .env 2>/dev/null || echo "unknown")
    if [ "$ENV_PERMS" = "600" ] || [ "$ENV_PERMS" = "400" ]; then
        print_success ".env file permissions are secure ($ENV_PERMS)"
    else
        print_warning ".env file permissions: $ENV_PERMS (should be 600 or 400)"
        print_info "Run: chmod 600 .env"
    fi
fi

# 8. Check for outdated dependencies
print_header "Checking Dependencies"

# Check PHP dependencies
if [ -f "composer.lock" ]; then
    print_info "Checking PHP dependencies..."
    if command -v composer &> /dev/null; then
        OUTDATED=$(composer outdated --direct 2>/dev/null | grep -v "^$" | wc -l || echo "0")
        if [ "$OUTDATED" -gt 0 ]; then
            print_warning "Found $OUTDATED outdated PHP packages"
            print_info "Run: composer outdated"
        else
            print_success "PHP dependencies are up to date"
        fi
    else
        print_warning "Composer not available - skipping PHP dependency check"
    fi
fi

# Check Node dependencies
if [ -f "frontend/package.json" ]; then
    print_info "Checking Node.js dependencies..."
    if command -v npm &> /dev/null; then
        cd frontend
        OUTDATED=$(npm outdated 2>/dev/null | grep -v "^$" | wc -l || echo "0")
        cd ..
        if [ "$OUTDATED" -gt 0 ]; then
            print_warning "Found $OUTDATED outdated Node packages"
            print_info "Run: cd frontend && npm outdated"
        else
            print_success "Node.js dependencies are up to date"
        fi
    else
        print_warning "npm not available - skipping Node dependency check"
    fi
fi

# 9. Generate optimization report
print_header "Optimization Recommendations"

echo ""
echo "Based on the checks above, here are the recommended actions:"
echo ""

# Create recommendations array
RECOMMENDATIONS=()

if [ $FOUND_GARBAGE -eq 1 ]; then
    RECOMMENDATIONS+=("Remove garbage files with patterns: ${GARBAGE_PATTERNS[*]}")
fi

if [ -n "$LARGE_LOGS" ]; then
    RECOMMENDATIONS+=("Rotate or archive large log files")
fi

if [ "$CACHE_COUNT" -gt 1000 ]; then
    RECOMMENDATIONS+=("Clear old cache files: rm -rf storage/cache/*")
fi

if [ "$BLOAT_CHECK" -gt 0 ]; then
    RECOMMENDATIONS+=("Run VACUUM ANALYZE on PostgreSQL database")
fi

if [ ! -d "public/build" ]; then
    RECOMMENDATIONS+=("Deploy frontend build: bash deployment/scripts/deploy.sh")
fi

if [ "$ENV_PERMS" != "600" ] && [ "$ENV_PERMS" != "400" ]; then
    RECOMMENDATIONS+=("Secure .env file: chmod 600 .env")
fi

if [ "$OUTDATED" -gt 0 ]; then
    RECOMMENDATIONS+=("Update outdated dependencies")
fi

# Print recommendations
if [ ${#RECOMMENDATIONS[@]} -eq 0 ]; then
    print_success "No optimization recommendations - system is well maintained!"
else
    for i in "${!RECOMMENDATIONS[@]}"; do
        echo "  $((i+1)). ${RECOMMENDATIONS[$i]}"
    done
fi

# 10. Summary
print_header "Summary"

echo ""
print_info "Cleanup and optimization check complete"
print_info "Review the recommendations above and take appropriate action"
echo ""

# Create optimization script
cat > /tmp/optimize_system.sh << 'EOF'
#!/bin/bash
# Auto-generated optimization script

echo "Running system optimizations..."

# Clear old cache (older than 7 days)
find storage/cache -type f -mtime +7 -delete 2>/dev/null || true
echo "✓ Cleared old cache files"

# Rotate large logs
find storage/logs -type f -size +50M -exec gzip {} \; 2>/dev/null || true
echo "✓ Compressed large log files"

# Clear temporary files
find /tmp -name "mi_core_*" -mtime +1 -delete 2>/dev/null || true
echo "✓ Cleared temporary files"

echo "Optimization complete!"
EOF

chmod +x /tmp/optimize_system.sh
print_info "Generated optimization script: /tmp/optimize_system.sh"
print_info "Run it with: bash /tmp/optimize_system.sh"

echo ""
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                    Cleanup Complete                        ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
