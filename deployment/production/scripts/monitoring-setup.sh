#!/bin/bash

# Monitoring Setup Script for MDM System

set -e

# Configuration
PROJECT_DIR="/opt/mdm"
GRAFANA_DASHBOARDS_DIR="$PROJECT_DIR/config/grafana/dashboards"
GRAFANA_DATASOURCES_DIR="$PROJECT_DIR/config/grafana/datasources"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

# Create Grafana directories
mkdir -p "$GRAFANA_DASHBOARDS_DIR"
mkdir -p "$GRAFANA_DATASOURCES_DIR"

# Create Grafana datasource configuration
create_grafana_datasources() {
    log "Creating Grafana datasources configuration..."
    
    cat > "$GRAFANA_DATASOURCES_DIR/prometheus.yml" << 'EOF'
apiVersion: 1

datasources:
  - name: Prometheus
    type: prometheus
    access: proxy
    url: http://mdm-prometheus:9090
    isDefault: true
    editable: true
EOF

    log "Grafana datasources configuration created"
}

# Create MDM System dashboard
create_mdm_dashboard() {
    log "Creating MDM System dashboard..."
    
    cat > "$GRAFANA_DASHBOARDS_DIR/mdm-system.json" << 'EOF'
{
  "dashboard": {
    "id": null,
    "title": "MDM System Overview",
    "tags": ["mdm", "system"],
    "timezone": "browser",
    "panels": [
      {
        "id": 1,
        "title": "Application Status",
        "type": "stat",
        "targets": [
          {
            "expr": "up{job=\"mdm-app\"}",
            "legendFormat": "App Status"
          }
        ],
        "fieldConfig": {
          "defaults": {
            "color": {
              "mode": "thresholds"
            },
            "thresholds": {
              "steps": [
                {"color": "red", "value": 0},
                {"color": "green", "value": 1}
              ]
            }
          }
        },
        "gridPos": {"h": 8, "w": 6, "x": 0, "y": 0}
      },
      {
        "id": 2,
        "title": "Request Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(http_requests_total{job=\"mdm-app\"}[5m])",
            "legendFormat": "Requests/sec"
          }
        ],
        "gridPos": {"h": 8, "w": 18, "x": 6, "y": 0}
      },
      {
        "id": 3,
        "title": "Response Time",
        "type": "graph",
        "targets": [
          {
            "expr": "histogram_quantile(0.95, rate(http_request_duration_seconds_bucket{job=\"mdm-app\"}[5m]))",
            "legendFormat": "95th percentile"
          },
          {
            "expr": "histogram_quantile(0.50, rate(http_request_duration_seconds_bucket{job=\"mdm-app\"}[5m]))",
            "legendFormat": "50th percentile"
          }
        ],
        "gridPos": {"h": 8, "w": 12, "x": 0, "y": 8}
      },
      {
        "id": 4,
        "title": "Database Connections",
        "type": "graph",
        "targets": [
          {
            "expr": "mysql_global_status_threads_connected{job=\"mysql-master\"}",
            "legendFormat": "Active Connections"
          }
        ],
        "gridPos": {"h": 8, "w": 12, "x": 12, "y": 8}
      },
      {
        "id": 5,
        "title": "Memory Usage",
        "type": "graph",
        "targets": [
          {
            "expr": "process_resident_memory_bytes{job=\"mdm-app\"}",
            "legendFormat": "App Memory"
          },
          {
            "expr": "redis_memory_used_bytes{job=\"redis\"}",
            "legendFormat": "Redis Memory"
          }
        ],
        "gridPos": {"h": 8, "w": 24, "x": 0, "y": 16}
      }
    ],
    "time": {
      "from": "now-1h",
      "to": "now"
    },
    "refresh": "30s"
  }
}
EOF

    log "MDM System dashboard created"
}

# Create database dashboard
create_database_dashboard() {
    log "Creating Database dashboard..."
    
    cat > "$GRAFANA_DASHBOARDS_DIR/database.json" << 'EOF'
{
  "dashboard": {
    "id": null,
    "title": "Database Monitoring",
    "tags": ["database", "mysql"],
    "timezone": "browser",
    "panels": [
      {
        "id": 1,
        "title": "Database Status",
        "type": "stat",
        "targets": [
          {
            "expr": "up{job=\"mysql-master\"}",
            "legendFormat": "Master"
          },
          {
            "expr": "up{job=\"mysql-slave\"}",
            "legendFormat": "Slave"
          }
        ],
        "gridPos": {"h": 8, "w": 8, "x": 0, "y": 0}
      },
      {
        "id": 2,
        "title": "Query Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(mysql_global_status_queries{job=\"mysql-master\"}[5m])",
            "legendFormat": "Queries/sec"
          }
        ],
        "gridPos": {"h": 8, "w": 16, "x": 8, "y": 0}
      },
      {
        "id": 3,
        "title": "Replication Lag",
        "type": "graph",
        "targets": [
          {
            "expr": "mysql_slave_lag_seconds{job=\"mysql-slave\"}",
            "legendFormat": "Lag (seconds)"
          }
        ],
        "gridPos": {"h": 8, "w": 12, "x": 0, "y": 8}
      },
      {
        "id": 4,
        "title": "InnoDB Buffer Pool",
        "type": "graph",
        "targets": [
          {
            "expr": "mysql_global_status_innodb_buffer_pool_pages_total{job=\"mysql-master\"} * mysql_global_variables_innodb_page_size{job=\"mysql-master\"}",
            "legendFormat": "Total"
          },
          {
            "expr": "mysql_global_status_innodb_buffer_pool_pages_free{job=\"mysql-master\"} * mysql_global_variables_innodb_page_size{job=\"mysql-master\"}",
            "legendFormat": "Free"
          }
        ],
        "gridPos": {"h": 8, "w": 12, "x": 12, "y": 8}
      }
    ],
    "time": {
      "from": "now-1h",
      "to": "now"
    },
    "refresh": "30s"
  }
}
EOF

    log "Database dashboard created"
}

# Create dashboard provisioning configuration
create_dashboard_provisioning() {
    log "Creating dashboard provisioning configuration..."
    
    cat > "$GRAFANA_DASHBOARDS_DIR/dashboards.yml" << 'EOF'
apiVersion: 1

providers:
  - name: 'MDM Dashboards'
    orgId: 1
    folder: 'MDM System'
    type: file
    disableDeletion: false
    updateIntervalSeconds: 10
    allowUiUpdates: true
    options:
      path: /etc/grafana/provisioning/dashboards
EOF

    log "Dashboard provisioning configuration created"
}

# Setup alerting rules
setup_alerting() {
    log "Setting up alerting rules..."
    
    # Alertmanager configuration
    cat > "$PROJECT_DIR/config/alertmanager/alertmanager.yml" << 'EOF'
global:
  smtp_smarthost: '${SMTP_HOST}:${SMTP_PORT}'
  smtp_from: '${SMTP_USER}'
  smtp_auth_username: '${SMTP_USER}'
  smtp_auth_password: '${SMTP_PASSWORD}'

route:
  group_by: ['alertname']
  group_wait: 10s
  group_interval: 10s
  repeat_interval: 1h
  receiver: 'web.hook'

receivers:
  - name: 'web.hook'
    email_configs:
      - to: '${EMAIL_ALERTS_TO}'
        subject: 'MDM System Alert: {{ .GroupLabels.alertname }}'
        body: |
          {{ range .Alerts }}
          Alert: {{ .Annotations.summary }}
          Description: {{ .Annotations.description }}
          {{ end }}
    slack_configs:
      - api_url: '${SLACK_WEBHOOK_URL}'
        channel: '#alerts'
        title: 'MDM System Alert'
        text: '{{ range .Alerts }}{{ .Annotations.summary }}{{ end }}'

inhibit_rules:
  - source_match:
      severity: 'critical'
    target_match:
      severity: 'warning'
    equal: ['alertname', 'dev', 'instance']
EOF

    log "Alerting rules configured"
}

# Install monitoring exporters
install_exporters() {
    log "Installing monitoring exporters..."
    
    # Add exporters to docker-compose
    cat >> "$PROJECT_DIR/docker-compose.prod.yml" << 'EOF'

  node-exporter:
    image: prom/node-exporter:latest
    container_name: mdm-node-exporter
    restart: unless-stopped
    ports:
      - "9100:9100"
    volumes:
      - /proc:/host/proc:ro
      - /sys:/host/sys:ro
      - /:/rootfs:ro
    command:
      - '--path.procfs=/host/proc'
      - '--path.rootfs=/rootfs'
      - '--path.sysfs=/host/sys'
      - '--collector.filesystem.mount-points-exclude=^/(sys|proc|dev|host|etc)($$|/)'
    networks:
      - mdm-network

  mysql-exporter:
    image: prom/mysqld-exporter:latest
    container_name: mdm-mysql-exporter
    restart: unless-stopped
    ports:
      - "9104:9104"
    environment:
      - DATA_SOURCE_NAME=${DB_USER}:${DB_PASSWORD}@(mdm-db-master:3306)/
    networks:
      - mdm-network
    depends_on:
      - mdm-db-master

  redis-exporter:
    image: oliver006/redis_exporter:latest
    container_name: mdm-redis-exporter
    restart: unless-stopped
    ports:
      - "9121:9121"
    environment:
      - REDIS_ADDR=redis://mdm-redis:6379
      - REDIS_PASSWORD=${REDIS_PASSWORD}
    networks:
      - mdm-network
    depends_on:
      - mdm-redis

  nginx-exporter:
    image: nginx/nginx-prometheus-exporter:latest
    container_name: mdm-nginx-exporter
    restart: unless-stopped
    ports:
      - "9113:9113"
    command:
      - '-nginx.scrape-uri=http://mdm-nginx:8080/nginx_status'
    networks:
      - mdm-network
    depends_on:
      - mdm-nginx
EOF

    log "Monitoring exporters configured"
}

# Main setup process
main() {
    log "Setting up monitoring for MDM System..."
    
    create_grafana_datasources
    create_mdm_dashboard
    create_database_dashboard
    create_dashboard_provisioning
    setup_alerting
    install_exporters
    
    log "✅ Monitoring setup completed successfully!"
    log "Access Grafana at: http://localhost:3000 (admin/admin)"
    log "Access Prometheus at: http://localhost:9090"
}

# Run main function
main "$@"