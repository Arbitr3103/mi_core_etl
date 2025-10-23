#!/bin/bash

# Uptime Monitoring Script for Warehouse Dashboard
# This script checks if the application is responding and logs uptime metrics

set -e

# Configuration
SITE_URL="${SITE_URL:-https://www.market-mi.ru}"
HEALTH_ENDPOINT="${HEALTH_ENDPOINT:-/api/monitoring.php?action=health}"
TIMEOUT="${TIMEOUT:-10}"
LOG_DIR="${LOG_PATH:-/var/log/warehouse-dashboard}"
ALERT_EMAIL="${EMAIL_ALERTS_TO:-}"
SLACK_WEBHOOK="${SLACK_WEBHOOK_URL:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" >&2
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

# Create log directory if it doesn't exist
mkdir -p "$LOG_DIR"

# Uptime log file
UPTIME_LOG="$LOG_DIR/uptime_$(date +%Y-%m-%d).log"

# Function to send alerts
send_alert() {
    local status=$1
    local message=$2
    local response_time=$3
    
    # Email alert
    if [ -n "$ALERT_EMAIL" ]; then
        local subject="Warehouse Dashboard - $status Alert"
        local body="Status: $status
Message: $message
Time: $(date)
Response Time: ${response_time}ms
URL: $SITE_URL$HEALTH_ENDPOINT

Please check the application immediately."
        
        echo "$body" | mail -s "$subject" "$ALERT_EMAIL" || warning "Failed to send email alert"
    fi
    
    # Slack alert
    if [ -n "$SLACK_WEBHOOK" ]; then
        local color="danger"
        [ "$status" = "WARNING" ] && color="warning"
        [ "$status" = "RECOVERED" ] && color="good"
        
        local payload=$(cat <<EOF
{
    "text": "Warehouse Dashboard Alert",
    "attachments": [
        {
            "color": "$color",
            "fields": [
                {"title": "Status", "value": "$status", "short": true},
                {"title": "Message", "value": "$message", "short": false},
                {"title": "Response Time", "value": "${response_time}ms", "short": true},
                {"title": "URL", "value": "$SITE_URL$HEALTH_ENDPOINT", "short": true}
            ],
            "footer": "Uptime Monitor",
            "ts": $(date +%s)
        }
    ]
}
EOF
        )
        
        curl -X POST -H 'Content-type: application/json' \
             --data "$payload" \
             "$SLACK_WEBHOOK" >/dev/null 2>&1 || warning "Failed to send Slack alert"
    fi
}

# Function to check application health
check_health() {
    local start_time=$(date +%s.%N)
    local status_code
    local response
    local response_time
    
    # Make HTTP request
    response=$(curl -s -w "%{http_code}" -m "$TIMEOUT" "$SITE_URL$HEALTH_ENDPOINT" 2>/dev/null || echo "000")
    status_code="${response: -3}"
    response_body="${response%???}"
    
    local end_time=$(date +%s.%N)
    response_time=$(echo "($end_time - $start_time) * 1000" | bc -l | cut -d. -f1)
    
    # Log the check
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local log_entry="$timestamp,$status_code,$response_time"
    
    if [ "$status_code" = "200" ]; then
        # Parse JSON response to check health status
        local health_status=$(echo "$response_body" | grep -o '"status":"[^"]*"' | cut -d'"' -f4 2>/dev/null || echo "unknown")
        
        log_entry="$log_entry,UP,$health_status"
        echo "$log_entry" >> "$UPTIME_LOG"
        
        if [ "$health_status" = "healthy" ]; then
            log "✓ Application is healthy (${response_time}ms)"
            return 0
        elif [ "$health_status" = "warning" ]; then
            warning "⚠ Application has warnings (${response_time}ms)"
            send_alert "WARNING" "Application health check returned warnings" "$response_time"
            return 1
        else
            error "✗ Application is unhealthy: $health_status (${response_time}ms)"
            send_alert "CRITICAL" "Application health check failed: $health_status" "$response_time"
            return 2
        fi
    else
        log_entry="$log_entry,DOWN,HTTP_$status_code"
        echo "$log_entry" >> "$UPTIME_LOG"
        
        if [ "$status_code" = "000" ]; then
            error "✗ Application is not responding (timeout or connection error)"
            send_alert "CRITICAL" "Application is not responding (timeout or connection error)" "timeout"
        else
            error "✗ Application returned HTTP $status_code (${response_time}ms)"
            send_alert "CRITICAL" "Application returned HTTP $status_code" "$response_time"
        fi
        return 2
    fi
}

# Function to check if we're recovering from downtime
check_recovery() {
    local last_status=$(tail -n 1 "$UPTIME_LOG" 2>/dev/null | cut -d',' -f4 || echo "")
    local current_status=$1
    
    if [ "$last_status" = "DOWN" ] && [ "$current_status" = "UP" ]; then
        log "🎉 Application has recovered!"
        send_alert "RECOVERED" "Application is back online" "$2"
    fi
}

# Main monitoring function
monitor() {
    log "Starting uptime check for $SITE_URL$HEALTH_ENDPOINT"
    
    if check_health; then
        check_recovery "UP" "$(tail -n 1 "$UPTIME_LOG" | cut -d',' -f3)"
    fi
    
    # Generate daily uptime report if it's the end of the day
    local hour=$(date +%H)
    if [ "$hour" = "23" ]; then
        generate_daily_report
    fi
}

# Function to generate daily uptime report
generate_daily_report() {
    local today=$(date +%Y-%m-%d)
    local report_file="$LOG_DIR/uptime_report_$today.txt"
    
    if [ ! -f "$UPTIME_LOG" ]; then
        return
    fi
    
    local total_checks=$(wc -l < "$UPTIME_LOG")
    local up_checks=$(grep ",UP," "$UPTIME_LOG" | wc -l)
    local down_checks=$(grep ",DOWN," "$UPTIME_LOG" | wc -l)
    local uptime_percent=$(echo "scale=2; $up_checks * 100 / $total_checks" | bc -l)
    
    # Calculate average response time for successful requests
    local avg_response_time=$(grep ",UP," "$UPTIME_LOG" | cut -d',' -f3 | awk '{sum+=$1} END {print sum/NR}' | cut -d. -f1)
    
    # Find longest downtime
    local max_downtime=0
    local current_downtime=0
    local in_downtime=false
    
    while IFS=',' read -r timestamp status_code response_time status health; do
        if [ "$status" = "DOWN" ]; then
            if [ "$in_downtime" = false ]; then
                in_downtime=true
                current_downtime=1
            else
                current_downtime=$((current_downtime + 1))
            fi
        else
            if [ "$in_downtime" = true ]; then
                if [ "$current_downtime" -gt "$max_downtime" ]; then
                    max_downtime=$current_downtime
                fi
                in_downtime=false
                current_downtime=0
            fi
        fi
    done < "$UPTIME_LOG"
    
    # Create report
    cat > "$report_file" << EOF
Warehouse Dashboard Uptime Report - $today
==========================================

Summary:
- Total Checks: $total_checks
- Successful: $up_checks
- Failed: $down_checks
- Uptime: $uptime_percent%
- Average Response Time: ${avg_response_time}ms
- Longest Downtime: $max_downtime checks

Detailed Log:
$(cat "$UPTIME_LOG")

Generated: $(date)
EOF
    
    log "Daily uptime report generated: $report_file"
    
    # Send daily report if configured
    if [ -n "$ALERT_EMAIL" ] && [ "$uptime_percent" != "100.00" ]; then
        mail -s "Daily Uptime Report - $today ($uptime_percent% uptime)" "$ALERT_EMAIL" < "$report_file" || warning "Failed to send daily report"
    fi
}

# Function to show usage
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  -u, --url URL          Site URL (default: $SITE_URL)"
    echo "  -e, --endpoint PATH    Health check endpoint (default: $HEALTH_ENDPOINT)"
    echo "  -t, --timeout SECONDS  Request timeout (default: $TIMEOUT)"
    echo "  -r, --report           Generate and show current day report"
    echo "  -h, --help             Show this help message"
    echo ""
    echo "Environment Variables:"
    echo "  SITE_URL              Site URL to monitor"
    echo "  HEALTH_ENDPOINT       Health check endpoint path"
    echo "  TIMEOUT               Request timeout in seconds"
    echo "  LOG_PATH              Log directory path"
    echo "  EMAIL_ALERTS_TO       Email address for alerts"
    echo "  SLACK_WEBHOOK_URL     Slack webhook URL for alerts"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--url)
            SITE_URL="$2"
            shift 2
            ;;
        -e|--endpoint)
            HEALTH_ENDPOINT="$2"
            shift 2
            ;;
        -t|--timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        -r|--report)
            generate_daily_report
            exit 0
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

# Run the monitor
monitor