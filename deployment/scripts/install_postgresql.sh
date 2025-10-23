#!/bin/bash

# ===================================================================
# PostgreSQL Installation and Configuration Script
# For mi_core_etl project migration from MySQL to PostgreSQL
# ===================================================================

set -e

echo "🐘 Starting PostgreSQL installation and configuration..."

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   echo "❌ This script should not be run as root for security reasons"
   exit 1
fi

# Update package list
echo "📦 Updating package list..."
sudo apt update

# Install PostgreSQL and additional packages
echo "🔧 Installing PostgreSQL 15 and required packages..."
sudo apt install -y postgresql-15 postgresql-client-15 postgresql-contrib-15

# Install additional tools
sudo apt install -y postgresql-15-postgis-3 postgresql-15-postgis-3-scripts

# Start and enable PostgreSQL service
echo "🚀 Starting PostgreSQL service..."
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Check PostgreSQL status
echo "✅ Checking PostgreSQL status..."
sudo systemctl status postgresql --no-pager

# Configure PostgreSQL
echo "⚙️ Configuring PostgreSQL..."

# Create database user
echo "👤 Creating database user..."
sudo -u postgres createuser --interactive --pwprompt mi_core_user || echo "User may already exist"

# Create database
echo "🗄️ Creating database..."
sudo -u postgres createdb -O mi_core_user mi_core_db || echo "Database may already exist"

# Configure PostgreSQL settings for performance
echo "🔧 Configuring PostgreSQL settings..."

# Backup original postgresql.conf
sudo cp /etc/postgresql/15/main/postgresql.conf /etc/postgresql/15/main/postgresql.conf.backup

# Update PostgreSQL configuration
sudo tee -a /etc/postgresql/15/main/postgresql.conf.d/mi_core.conf > /dev/null << 'EOF'
# mi_core_etl PostgreSQL Configuration
# Performance optimizations

# Memory settings
shared_buffers = 256MB
effective_cache_size = 1GB
work_mem = 4MB
maintenance_work_mem = 64MB

# Checkpoint settings
checkpoint_completion_target = 0.9
wal_buffers = 16MB

# Connection settings
max_connections = 200

# Logging settings
log_destination = 'stderr'
logging_collector = on
log_directory = 'log'
log_filename = 'postgresql-%Y-%m-%d_%H%M%S.log'
log_rotation_age = 1d
log_rotation_size = 100MB
log_min_duration_statement = 1000
log_line_prefix = '%t [%p]: [%l-1] user=%u,db=%d,app=%a,client=%h '
log_checkpoints = on
log_connections = on
log_disconnections = on
log_lock_waits = on

# Performance monitoring
track_activities = on
track_counts = on
track_io_timing = on
track_functions = all

# Autovacuum settings
autovacuum = on
autovacuum_max_workers = 3
autovacuum_naptime = 1min
EOF

# Configure pg_hba.conf for authentication
echo "🔐 Configuring authentication..."
sudo cp /etc/postgresql/15/main/pg_hba.conf /etc/postgresql/15/main/pg_hba.conf.backup

# Add configuration for mi_core_user
sudo tee -a /etc/postgresql/15/main/pg_hba.conf > /dev/null << 'EOF'

# mi_core_etl project configuration
local   mi_core_db      mi_core_user                    md5
host    mi_core_db      mi_core_user    127.0.0.1/32    md5
host    mi_core_db      mi_core_user    ::1/128         md5
EOF

# Restart PostgreSQL to apply configuration
echo "🔄 Restarting PostgreSQL to apply configuration..."
sudo systemctl restart postgresql

# Test connection
echo "🧪 Testing PostgreSQL connection..."
sudo -u postgres psql -c "SELECT version();"

# Create .pgpass file for password-less connections
echo "🔑 Setting up .pgpass file..."
if [ ! -f ~/.pgpass ]; then
    touch ~/.pgpass
    chmod 600 ~/.pgpass
fi

# Add entry to .pgpass (user will need to update with actual password)
echo "localhost:5432:mi_core_db:mi_core_user:your_password_here" >> ~/.pgpass

echo "✅ PostgreSQL installation and configuration completed!"
echo ""
echo "📋 Next steps:"
echo "1. Update the password in ~/.pgpass file"
echo "2. Update .env file with PostgreSQL connection details"
echo "3. Run the database schema migration script"
echo ""
echo "🔧 Connection details:"
echo "Host: localhost"
echo "Port: 5432"
echo "Database: mi_core_db"
echo "User: mi_core_user"
echo ""
echo "📊 PostgreSQL status:"
sudo systemctl status postgresql --no-pager -l