#!/bin/bash

echo "🔧 Fixing nginx API configuration - Final Solution..."

# Backup current config
sudo cp /etc/nginx/sites-available/market-mi.ru /etc/nginx/sites-available/market-mi.ru.backup.final.$(date +%Y%m%d_%H%M%S)

# Step 1: Remove the nested PHP location block from inside /api/
echo "📝 Removing nested PHP block from /api/ section..."
sudo sed -i '/location \/api\/ {/,/^}/{ 
    /# PHP processing for API/,/^[[:space:]]*}[[:space:]]*$/{
        /location ~ \\\.php\$/,/^[[:space:]]*}[[:space:]]*$/d
    }
}' /etc/nginx/sites-available/market-mi.ru

# Step 2: Add separate PHP processing block for API BEFORE the main PHP block
echo "📝 Adding separate PHP block for /api/..."
sudo sed -i '/# PHP processing - existing/i\
# PHP processing for API - separate block\
location ~ ^\/api\/.+\\.php$ {\
    root \/var\/www\/market-mi.ru;\
    \
    fastcgi_pass unix:\/var\/run\/php\/php8.1-fpm.sock;\
    fastcgi_index index.php;\
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\
    include fastcgi_params;\
    \
    fastcgi_read_timeout 300;\
    fastcgi_send_timeout 300;\
    \
    add_header Access-Control-Allow-Origin "*" always;\
    add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;\
    add_header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key" always;\
}\
\
' /etc/nginx/sites-available/market-mi.ru

# Test nginx configuration
echo "🧪 Testing nginx configuration..."
if sudo nginx -t; then
    echo "✅ Configuration is valid"
    sudo systemctl reload nginx
    echo "✅ Nginx reloaded"
    
    # Test API
    echo "🧪 Testing API endpoint..."
    sleep 1
    RESPONSE=$(curl -s http://localhost/api/detailed-stock.php?limit=1)
    
    if echo "$RESPONSE" | grep -q "success"; then
        echo "✅ API is working!"
        echo "$RESPONSE" | head -c 200
    else
        echo "⚠️  API response:"
        echo "$RESPONSE" | head -c 500
    fi
else
    echo "❌ Configuration is invalid, restoring backup"
    LATEST_BACKUP=$(ls -t /etc/nginx/sites-available/market-mi.ru.backup.final.* 2>/dev/null | head -1)
    if [ -n "$LATEST_BACKUP" ]; then
        sudo cp "$LATEST_BACKUP" /etc/nginx/sites-available/market-mi.ru
        echo "✅ Backup restored"
    fi
fi
