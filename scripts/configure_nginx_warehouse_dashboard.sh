#!/bin/bash

# Configure Nginx for Warehouse Dashboard deployment
# This script updates the nginx configuration for proper SPA routing and API handling

set -e

echo "Configuring Nginx for Warehouse Dashboard..."

# Backup current configuration
sudo cp /etc/nginx/sites-available/market-mi.ru /etc/nginx/sites-available/market-mi.ru.backup.$(date +%Y%m%d_%H%M%S)

# Copy updated configuration
sudo cp market-mi-updated.conf /etc/nginx/sites-available/market-mi.ru

# Test nginx configuration
echo "Testing nginx configuration..."
sudo nginx -t

if [ $? -eq 0 ]; then
    echo "Nginx configuration is valid. Reloading nginx..."
    sudo systemctl reload nginx
    echo "Nginx reloaded successfully!"
else
    echo "Nginx configuration test failed. Please check the configuration."
    exit 1
fi

# Verify the site is accessible
echo "Verifying site accessibility..."
curl -I https://www.market-mi.ru/warehouse-dashboard/ || echo "Warning: Site may not be accessible yet"

echo "Nginx configuration completed!"