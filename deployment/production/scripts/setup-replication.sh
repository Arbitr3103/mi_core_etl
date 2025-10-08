#!/bin/bash

# MySQL Master-Slave Replication Setup Script

set -e

echo "Setting up MySQL Master-Slave Replication..."

# Wait for master to be ready
echo "Waiting for MySQL master to be ready..."
until docker exec mdm-db-master mysqladmin ping -h localhost --silent; do
    echo "Waiting for MySQL master..."
    sleep 2
done

# Wait for slave to be ready
echo "Waiting for MySQL slave to be ready..."
until docker exec mdm-db-slave mysqladmin ping -h localhost --silent; do
    echo "Waiting for MySQL slave..."
    sleep 2
done

# Create replication user on master
echo "Creating replication user on master..."
docker exec mdm-db-master mysql -u root -p${DB_ROOT_PASSWORD} -e "
CREATE USER IF NOT EXISTS 'replication'@'%' IDENTIFIED BY '${REPLICATION_PASSWORD}';
GRANT REPLICATION SLAVE ON *.* TO 'replication'@'%';
FLUSH PRIVILEGES;
"

# Get master status
echo "Getting master status..."
MASTER_STATUS=$(docker exec mdm-db-master mysql -u root -p${DB_ROOT_PASSWORD} -e "SHOW MASTER STATUS\G")
MASTER_FILE=$(echo "$MASTER_STATUS" | grep "File:" | awk '{print $2}')
MASTER_POSITION=$(echo "$MASTER_STATUS" | grep "Position:" | awk '{print $2}')

echo "Master file: $MASTER_FILE"
echo "Master position: $MASTER_POSITION"

# Configure slave
echo "Configuring slave..."
docker exec mdm-db-slave mysql -u root -p${DB_ROOT_PASSWORD} -e "
STOP SLAVE;
CHANGE MASTER TO
    MASTER_HOST='mdm-db-master',
    MASTER_USER='replication',
    MASTER_PASSWORD='${REPLICATION_PASSWORD}',
    MASTER_LOG_FILE='$MASTER_FILE',
    MASTER_LOG_POS=$MASTER_POSITION;
START SLAVE;
"

# Check slave status
echo "Checking slave status..."
SLAVE_STATUS=$(docker exec mdm-db-slave mysql -u root -p${DB_ROOT_PASSWORD} -e "SHOW SLAVE STATUS\G")
echo "$SLAVE_STATUS"

# Verify replication is working
IO_RUNNING=$(echo "$SLAVE_STATUS" | grep "Slave_IO_Running:" | awk '{print $2}')
SQL_RUNNING=$(echo "$SLAVE_STATUS" | grep "Slave_SQL_Running:" | awk '{print $2}')

if [ "$IO_RUNNING" = "Yes" ] && [ "$SQL_RUNNING" = "Yes" ]; then
    echo "✅ Replication setup completed successfully!"
else
    echo "❌ Replication setup failed!"
    echo "IO Running: $IO_RUNNING"
    echo "SQL Running: $SQL_RUNNING"
    exit 1
fi

echo "Replication setup completed!"