# Data Quality Monitoring System

This module implements a comprehensive data quality monitoring system for the MDM (Master Data Management) platform. It provides real-time monitoring, alerting, and reporting capabilities for data quality metrics.

## Features Implemented

### 6.1 Data Quality Metrics Calculation ✅

**Enhanced DataQualityService** (`src/MDM/Services/DataQualityService.php`)

- **Master Data Coverage Calculation**: Calculates percentage of external SKUs mapped to master products
- **Attribute Completeness Metrics**: Tracks completeness of name, brand, category, description, and additional attributes
- **Automatic Matching Accuracy Tracking**: Monitors confidence scores and success rates of automatic matching
- **System Performance Metrics**: Tracks processing times, queue sizes, and system health indicators

**New PerformanceMonitoringService** (`src/MDM/Services/PerformanceMonitoringService.php`)

- **Operation Tracking**: Start/end tracking for performance measurement
- **Performance Summary**: Aggregated performance metrics by operation type
- **Memory Usage Statistics**: Tracks memory consumption patterns
- **Slow Operations Detection**: Identifies operations exceeding performance thresholds

**Database Schema** (`sql/performance_monitoring_schema.sql`)

- `performance_metrics` table for tracking execution times and memory usage
- `system_health_metrics` table for system-wide health indicators
- `matching_accuracy_log` table for tracking matching performance over time
- `data_quality_alerts` table for storing quality alerts and notifications

### 6.2 Problem Notification System ✅

**Enhanced NotificationService** (`src/MDM/Services/NotificationService.php`)

- **Multi-channel Support**: Log, email, webhook, and Slack notification channels
- **Data Quality Alerts**: Specialized alerts for quality metric breaches
- **Stock Alerts**: Critical and warning level stock notifications
- **System Update Notifications**: Automated system change notifications

**DataQualityAlertService** (`src/MDM/Services/DataQualityAlertService.php`)

- **Automatic Quality Monitoring**: Continuous monitoring of all quality metrics
- **Threshold-based Alerting**: Configurable warning and critical thresholds
- **Specific Issue Detection**: Monitors for unknown brands, missing categories, pending verifications
- **Weekly Report Generation**: Automated comprehensive quality reports
- **Recommendation Engine**: Generates actionable recommendations based on quality issues

**DataQualityScheduler** (`src/MDM/Services/DataQualityScheduler.php`)

- **Scheduled Quality Checks**: Configurable intervals for different check types
- **Execution History**: Complete audit trail of all scheduled operations
- **Status Management**: Enable/disable individual checks
- **Force Execution**: Manual trigger for immediate quality checks

**CLI Tool** (`data_quality_monitor.php`)

- **Command-line Interface**: Full CLI for managing quality monitoring
- **Scheduler Management**: Start/stop/configure scheduled checks
- **Status Reporting**: View execution history and current status
- **Force Operations**: Manually trigger quality checks and reports

### 6.3 Quality Monitoring Dashboard ✅

**QualityMonitoringController** (`src/MDM/Controllers/QualityMonitoringController.php`)

- **Dashboard View**: Main quality monitoring interface
- **API Endpoints**: RESTful API for metrics, trends, and alerts
- **Real-time Data**: Live updates of quality metrics
- **Drill-down Analysis**: Detailed views of problematic products
- **Scheduler Control**: Web interface for managing scheduled tasks

**Dashboard View** (`src/MDM/Views/quality_monitoring.php`)

- **Overall Health Score**: Single metric showing system-wide data quality
- **Metrics Grid**: Visual cards for each quality dimension
- **Trend Charts**: Historical quality trends and matching accuracy
- **Coverage Analysis**: Source-by-source coverage breakdown
- **Attribute Completeness**: Visual representation of field completeness
- **Recent Alerts**: Real-time alert feed with severity indicators
- **Problematic Products**: Drill-down tables for issue investigation
- **Scheduler Status**: Live view and control of scheduled tasks

**Responsive CSS** (`src/MDM/assets/css/quality_monitoring.css`)

- **Modern Design**: Clean, professional interface design
- **Mobile Responsive**: Optimized for all device sizes
- **Interactive Elements**: Hover effects, progress bars, status indicators
- **Color-coded Status**: Visual indicators for different quality levels
- **Chart Styling**: Consistent styling for all data visualizations

**Interactive JavaScript** (`src/MDM/assets/js/quality_monitoring.js`)

- **Real-time Updates**: Auto-refresh every 5 minutes
- **Interactive Charts**: Chart.js integration for trends and analytics
- **AJAX Operations**: Seamless API interactions without page reloads
- **Drill-down Navigation**: Dynamic loading of detailed product data
- **Scheduler Controls**: Toggle and manage scheduled tasks
- **Export Functionality**: Download quality reports in JSON format
- **Notification System**: In-app notifications for user feedback

## Key Metrics Tracked

### Data Quality Dimensions

1. **Completeness** - Percentage of filled required fields
2. **Accuracy** - Confidence scores and verification rates
3. **Consistency** - Duplicate detection and brand standardization
4. **Coverage** - Percentage of external SKUs mapped to master data
5. **Freshness** - Recency of data updates
6. **Matching Performance** - Automatic matching success rates
7. **System Performance** - Processing speeds and resource usage

### Alert Types

- **Completeness Alerts**: High numbers of products with missing data
- **Accuracy Alerts**: Low confidence matching rates
- **Performance Alerts**: Large pending verification queues
- **System Alerts**: Critical errors or resource constraints

### Scheduled Operations

- **Quality Metrics Update** (Hourly) - Refresh all quality calculations
- **Alert Check** (Every 30 minutes) - Monitor for threshold breaches
- **Weekly Report** (Weekly) - Generate comprehensive quality reports
- **Performance Cleanup** (Daily) - Remove old performance metrics

## Usage

### Web Dashboard

Access the quality monitoring dashboard at `/mdm/quality-monitoring`

### CLI Operations

```bash
# Run all scheduled checks
php data_quality_monitor.php run

# Force a specific check
php data_quality_monitor.php check alert_check

# View scheduler status
php data_quality_monitor.php status

# View execution history
php data_quality_monitor.php history

# Initialize scheduler (first time setup)
php data_quality_monitor.php init
```

### API Endpoints

- `GET /mdm/api/metrics` - Current quality metrics
- `GET /mdm/api/quality-trends?days=30` - Historical trends
- `GET /mdm/api/alerts?hours=24` - Recent alerts
- `POST /mdm/api/force-quality-check` - Trigger quality check
- `GET /mdm/api/report?type=current` - Generate quality report

## Configuration

### Alert Thresholds

Thresholds can be configured in `DataQualityAlertService`:

- Unknown brand threshold: 100 products
- No category threshold: 50 products
- Pending verification threshold: 500 items (warning), 2000 items (critical)
- Low confidence rate threshold: 30%

### Notification Channels

Configure notification channels in the NotificationService constructor:

- **Log**: File-based logging (always enabled)
- **Email**: SMTP email notifications
- **Webhook**: HTTP webhook integration
- **Slack**: Slack channel notifications

### Scheduler Intervals

Default intervals (configurable via CLI or web interface):

- Quality metrics update: 1 hour
- Alert check: 30 minutes
- Weekly report: 7 days
- Performance cleanup: 24 hours

## Database Requirements

Run the following SQL files to set up the required tables:

1. `sql/performance_monitoring_schema.sql` - Performance and alert tables
2. Existing MDM schema with `master_products`, `sku_mapping`, and `data_quality_metrics` tables

## Dependencies

- **PHP 7.4+** with PDO MySQL extension
- **Chart.js** for dashboard visualizations
- **MySQL 5.7+** for data storage
- **Web server** (Apache/Nginx) for dashboard access

## Integration Points

This quality monitoring system integrates with:

- **Existing MDM Services**: DataQualityService, MatchingService
- **ETL Pipeline**: Monitoring of data processing operations
- **Notification System**: Multi-channel alert delivery
- **Dashboard System**: Embedded quality widgets
- **API System**: RESTful endpoints for external integrations
