#!/bin/bash

# Configuration script for deployment paths
# Run this before first deployment

echo "🔧 Configuring deployment scripts for your server..."

# Get current directory
CURRENT_DIR=$(pwd)
echo "Current directory: $CURRENT_DIR"

# Ask for project directory
read -p "Enter project directory path [$CURRENT_DIR]: " PROJECT_DIR
PROJECT_DIR=${PROJECT_DIR:-$CURRENT_DIR}

# Ask for vladimir user
read -p "Enter maintenance user name [vladimir]: " VLADIMIR_USER
VLADIMIR_USER=${VLADIMIR_USER:-vladimir}

# Ask for web server user
read -p "Enter web server user [www-data]: " APP_USER
APP_USER=${APP_USER:-www-data}

# Ask for web server group
read -p "Enter web server group [www-data]: " APP_GROUP
APP_GROUP=${APP_GROUP:-www-data}

echo ""
echo "Configuration:"
echo "  Project directory: $PROJECT_DIR"
echo "  Maintenance user: $VLADIMIR_USER"
echo "  Web server user: $APP_USER"
echo "  Web server group: $APP_GROUP"
echo ""

read -p "Is this correct? (y/n): " CONFIRM
if [ "$CONFIRM" != "y" ]; then
    echo "Configuration cancelled."
    exit 1
fi

# Update safe_deploy.sh
if [ -f "safe_deploy.sh" ]; then
    sed -i "s|PROJECT_DIR=\".*\"|PROJECT_DIR=\"$PROJECT_DIR\"|g" safe_deploy.sh
    sed -i "s|VLADIMIR_USER=\".*\"|VLADIMIR_USER=\"$VLADIMIR_USER\"|g" safe_deploy.sh
    sed -i "s|APP_USER=\".*\"|APP_USER=\"$APP_USER\"|g" safe_deploy.sh
    sed -i "s|APP_GROUP=\".*\"|APP_GROUP=\"$APP_GROUP\"|g" safe_deploy.sh
    echo "✅ safe_deploy.sh configured"
fi

# Update quick_update.sh
if [ -f "quick_update.sh" ]; then
    sed -i "s|PROJECT_DIR=\".*\"|PROJECT_DIR=\"$PROJECT_DIR\"|g" quick_update.sh
    sed -i "s|VLADIMIR_USER=\".*\"|VLADIMIR_USER=\"$VLADIMIR_USER\"|g" quick_update.sh
    sed -i "s|APP_USER=\".*\"|APP_USER=\"$APP_USER\"|g" quick_update.sh
    sed -i "s|APP_GROUP=\".*\"|APP_GROUP=\"$APP_GROUP\"|g" quick_update.sh
    echo "✅ quick_update.sh configured"
fi

# Update deploy_server_update.sh
if [ -f "deploy_server_update.sh" ]; then
    sed -i "s|PROJECT_DIR=\".*\"|PROJECT_DIR=\"$PROJECT_DIR\"|g" deploy_server_update.sh
    sed -i "s|VLADIMIR_USER=\".*\"|VLADIMIR_USER=\"$VLADIMIR_USER\"|g" deploy_server_update.sh
    sed -i "s|APP_USER=\".*\"|APP_USER=\"$APP_USER\"|g" deploy_server_update.sh
    sed -i "s|APP_GROUP=\".*\"|APP_GROUP=\"$APP_GROUP\"|g" deploy_server_update.sh
    echo "✅ deploy_server_update.sh configured"
fi

echo ""
echo "🎉 All deployment scripts configured!"
echo ""
echo "Next steps:"
echo "1. Run: sudo ./safe_deploy.sh"
echo "2. Or for quick updates: sudo ./quick_update.sh"