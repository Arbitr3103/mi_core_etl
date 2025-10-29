#!/bin/bash

echo "🔧 Fixing nginx API configuration..."

# Backup current config
sudo cp /etc/nginx/sites-available/market-mi.ru /etc/nginx/sites-available/market-mi.ru.backup.$(date +%Y%m%d_%H%M%S)

# Remove the problematic nested location block from API section
sudo sed -i '/# PHP processing for API/,/^[[:space:]]*}[[:space:]]*$/d' /etc/nginx/sites-available/market-mi.ru

# Add the correct PHP processing block after the API section
sudo sed -i '/^[[:space:]]*}[[:space:]]*$/a\\n# PHP processing for API - separate location block\nlocation ~ ^\/api\/.*\.php$ {\n    root \/var\/www\/market-mi.ru;\n    \n    fastcgi_pass unix:\/var\/run\/php\/php8.1-fpm.sock;\n    fastcgi_index index.php;\n    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n    include fastcgi_params;\n    \n    # Increase timeout for large data processing\n    fastcgi_read_timeout 300;\n    fastcgi_send_timeout 300;\n    \n    # CORS headers\n    add_header Access-Control-Allow-Origin "*" always;\n    add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;\n    add_header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key" always;\n}' /etc/nginx/sites-available/market-mi.ru

# Test nginx configuration
echo "🧪 Testing nginx configuration..."
if sudo nginx -t; then
    echo "✅ Configuration is valid"
    sudo systemctl reload nginx
    echo "✅ Nginx reloaded"
    
    # Test API
    echo "🧪 Testing API..."
    if curl -s http://localhost/api/inventory/detailed-stock.php?limit=1 | grep -q "success"; then
        echo "✅ API is working!"
    else
        echo "⚠️ API test failed, but nginx config is valid"
    fi
else
    echo "❌ Configuration is invalid, restoring backup"
    sudo cp /etc/nginx/sites-available/market-mi.ru.backup.$(date +%Y%m%d_%H%M%S) /etc/nginx/sites-available/market-mi.ru
fi