# Ozon Analytics Export Functionality - Implementation Guide

## Overview

This document describes the implementation of the export functionality for Ozon analytics data, which allows users to export funnel data, demographics, and campaign information in CSV and JSON formats with support for pagination and temporary download links.

## Features Implemented

### ✅ 1. Export Data Formats

- **CSV Export**: Excel-compatible CSV with UTF-8 BOM and localized number formatting
- **JSON Export**: Pretty-printed JSON with Unicode support

### ✅ 2. Data Types Supported

- **Funnel Data**: Views, cart additions, orders, and conversion rates
- **Demographics**: Age groups, gender, regions, orders count, and revenue
- **Campaigns**: Impressions, clicks, spend, orders, revenue, CTR, CPC, ROAS

### ✅ 3. Pagination Support

- Large datasets are automatically paginated
- Configurable page size (100-10,000 records)
- Pagination metadata included in responses

### ✅ 4. Temporary Download Links

- Secure temporary URLs for large files
- 1-hour expiration time
- Automatic cleanup of expired files
- Download tracking and statistics

### ✅ 5. User Interface Integration

- Modal dialog for advanced export options
- Quick export buttons in each analytics tab
- Progress indicators and error handling
- Toast notifications for user feedback

## File Structure

```
src/
├── classes/
│   └── OzonAnalyticsAPI.php          # Updated with export methods
├── api/
│   └── ozon-analytics.php            # Updated with export endpoints
├── js/
│   └── OzonExportManager.js          # New: Frontend export manager
└── dashboard_marketplace_enhanced.php # Updated with export buttons

migrations/
└── add_ozon_temp_downloads_table.sql # Database migration

scripts/
├── apply_ozon_export_migration.sh    # Migration script
├── test_ozon_export_basic.php        # Basic functionality test
└── test_ozon_export_functionality.php # Full functionality test
```

## Implementation Details

### Backend Changes

#### 1. OzonAnalyticsAPI Class Updates

**New Methods Added:**

- `exportData($dataType, $format, $filters)` - Main export method
- `exportToCsv($data, $dataType)` - CSV generation with proper formatting
- `getCsvHeaders($dataType)` - Localized CSV headers
- `formatRowForCsv($row, $dataType)` - Data formatting for CSV
- `createTemporaryDownloadLink($content, $filename, $contentType)` - Temporary file management
- `getTemporaryFile($token)` - File retrieval by token
- `cleanupExpiredFiles()` - Automatic cleanup
- `exportDataWithPagination($dataType, $format, $filters, $page, $pageSize)` - Paginated export

**Key Features:**

- UTF-8 BOM for Excel compatibility
- Localized number formatting (comma as decimal separator)
- Secure temporary file storage
- Automatic file cleanup
- Error handling and validation

#### 2. API Endpoints Updates

**New Endpoints:**

- `POST /export-data` - Export data with pagination support
- `GET /download?token=xxx` - Download temporary files
- `POST /cleanup-downloads` - Manual cleanup trigger

**Enhanced Features:**

- Support for pagination parameters
- Temporary download link generation
- File download with proper headers
- Download statistics tracking

#### 3. Database Schema

**New Table: `ozon_temp_downloads`**

```sql
CREATE TABLE ozon_temp_downloads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL UNIQUE,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    content_type VARCHAR(100) DEFAULT 'text/csv',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    downloaded_at TIMESTAMP NULL,
    download_count INT DEFAULT 0,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
);
```

### Frontend Changes

#### 1. OzonExportManager Class

**Key Features:**

- Modal dialog for export configuration
- Progress tracking with animations
- Quick export buttons
- Toast notifications
- File download handling
- Error management

**Methods:**

- `showExportModal(dataType)` - Show export dialog
- `exportData(params)` - Perform export via API
- `quickExport(dataType, format)` - One-click export
- `downloadFile(url)` - Handle file downloads
- `validateExportForm()` - Form validation

#### 2. Dashboard Integration

**Updates Made:**

- Added export buttons to analytics tabs
- Integrated OzonExportManager script
- Added quick export buttons for each data type
- Enhanced UI with progress indicators

## Usage Instructions

### For Users

#### 1. Full Export Dialog

1. Navigate to "Аналитика Ozon" tab in the dashboard
2. Click "📤 Экспорт данных" button
3. Configure export parameters:
   - Data type (funnel, demographics, campaigns)
   - Format (CSV or JSON)
   - Date range
   - Optional filters
   - Pagination settings
4. Click "Начать экспорт"
5. Download the generated file

#### 2. Quick Export

1. Navigate to any analytics tab (Воронка, Демография, Кампании)
2. Click "📄 CSV" or "📋 JSON" button in the tab header
3. File will be automatically downloaded

### For Developers

#### 1. API Usage

**Export Data:**

```javascript
const response = await fetch("/src/api/ozon-analytics.php", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    action: "export-data",
    data_type: "funnel",
    format: "csv",
    date_from: "2025-01-01",
    date_to: "2025-01-31",
    use_pagination: true,
    page_size: 1000,
  }),
});
```

**Download File:**

```javascript
window.location.href = "/src/api/ozon-analytics.php?action=download&token=xxx";
```

#### 2. PHP Usage

```php
$ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);

// Simple export
$csvContent = $ozonAPI->exportData('funnel', 'csv', [
    'date_from' => '2025-01-01',
    'date_to' => '2025-01-31'
]);

// Paginated export
$result = $ozonAPI->exportDataWithPagination('demographics', 'json', $filters, 1, 500);
```

## Configuration

### Environment Variables

```bash
# Database connection
DB_HOST=127.0.0.1
DB_NAME=mi_core_db
DB_USER=ingest_user
DB_PASSWORD=your_password

# Ozon API credentials
OZON_CLIENT_ID=26100
OZON_API_KEY=your_api_key
```

### File Permissions

```bash
# Ensure temp directory is writable
chmod 755 /tmp/ozon_exports
```

### Cron Job for Cleanup

```bash
# Add to crontab for automatic cleanup every 30 minutes
*/30 * * * * curl -X POST 'https://api.zavodprostavok.ru/src/api/ozon-analytics.php?action=cleanup-downloads'
```

## Testing

### 1. Database Migration

```bash
./apply_ozon_export_migration.sh
```

### 2. Basic Functionality Test

```bash
php test_ozon_export_basic.php
```

### 3. Full Functionality Test (requires database)

```bash
php test_ozon_export_functionality.php
```

### 4. Manual Testing

1. Open dashboard: `https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php`
2. Switch to "Аналитика Ozon" tab
3. Test export functionality
4. Verify file downloads

## Security Considerations

### 1. File Security

- Temporary files stored outside web root
- Unique tokens for file access
- Automatic expiration (1 hour)
- Download tracking and limits

### 2. Input Validation

- Date range validation (max 90 days)
- Data type validation
- Format validation
- SQL injection prevention

### 3. Access Control

- API key authentication required
- Rate limiting implemented
- Error message sanitization

## Performance Optimization

### 1. Pagination

- Large datasets automatically paginated
- Configurable page sizes
- Memory-efficient processing

### 2. Caching

- Temporary file caching
- Database query optimization
- Client-side progress tracking

### 3. File Handling

- Stream-based CSV generation
- Temporary file cleanup
- Efficient memory usage

## Error Handling

### 1. API Errors

- Structured error responses
- Error type classification
- User-friendly error messages

### 2. File Errors

- File creation validation
- Download error handling
- Cleanup error recovery

### 3. Frontend Errors

- Progress bar error states
- Toast notifications
- Retry mechanisms

## Monitoring and Maintenance

### 1. Log Files

- Export request logging
- Error logging
- Performance metrics

### 2. Database Monitoring

- Temporary file table size
- Download statistics
- Cleanup effectiveness

### 3. Regular Maintenance

- Expired file cleanup
- Log rotation
- Performance monitoring

## Future Enhancements

### Potential Improvements

1. **Email Export**: Send large files via email
2. **Scheduled Exports**: Automated recurring exports
3. **Export Templates**: Predefined export configurations
4. **Data Compression**: ZIP compression for large files
5. **Export History**: Track and manage export history
6. **Advanced Filters**: More granular filtering options
7. **Real-time Progress**: WebSocket-based progress updates

## Troubleshooting

### Common Issues

#### 1. Export Button Not Working

- Check JavaScript console for errors
- Verify OzonExportManager.js is loaded
- Check API endpoint accessibility

#### 2. File Download Fails

- Verify temporary file exists
- Check token expiration
- Validate file permissions

#### 3. CSV Encoding Issues

- Ensure UTF-8 BOM is present
- Check Excel import settings
- Verify locale settings

#### 4. Large File Timeouts

- Enable pagination for large datasets
- Increase PHP execution time limits
- Use temporary download links

### Debug Commands

```bash
# Check database table
mysql -u user -p -e "SELECT COUNT(*) FROM ozon_temp_downloads;"

# Check temporary files
ls -la /tmp/ozon_exports/

# Test API endpoint
curl -X POST 'https://api.zavodprostavok.ru/src/api/ozon-analytics.php?action=health'
```

## Conclusion

The Ozon Analytics export functionality has been successfully implemented with comprehensive features including:

- ✅ Multiple export formats (CSV, JSON)
- ✅ Support for all data types (funnel, demographics, campaigns)
- ✅ Pagination for large datasets
- ✅ Temporary download links
- ✅ User-friendly interface
- ✅ Comprehensive error handling
- ✅ Security measures
- ✅ Performance optimizations

The implementation is production-ready and provides a robust solution for exporting Ozon analytics data with excellent user experience and maintainability.
