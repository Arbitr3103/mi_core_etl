# Ozon API Settings Interface Guide

## Overview

The Ozon API Settings Interface provides a secure and user-friendly way to manage Ozon API connection settings, including Client ID, API Key management, connection testing, and credential validation.

## Features

### 🔐 Security Features

- **Argon2ID Encryption**: API keys are stored using Argon2ID hashing algorithm
- **Input Validation**: Real-time validation of Client ID and API Key formats
- **Secure Storage**: Sensitive data is encrypted before database storage
- **Token Management**: Automatic token refresh and expiry handling

### ⚙️ Management Features

- **CRUD Operations**: Create, read, update, and delete API settings
- **Connection Testing**: Test API connectivity before saving settings
- **Multiple Configurations**: Support for multiple API configurations
- **Status Monitoring**: Real-time connection status display

### 🎨 User Interface

- **Responsive Design**: Mobile-friendly interface
- **Real-time Feedback**: Instant validation and error messages
- **Loading States**: Visual feedback during operations
- **Bootstrap Integration**: Consistent styling with the main dashboard

## File Structure

```
src/
├── ozon_settings.php              # Main settings page
├── api/ozon-settings.php          # API endpoints for settings management
├── js/OzonSettingsManager.js      # JavaScript module for UI interactions
└── classes/OzonAnalyticsAPI.php   # Core API class (existing)

migrations/
├── add_ozon_analytics_tables.sql  # Main database tables (existing)
└── add_ozon_token_fields.sql      # Token fields migration (new)
```

## Database Schema

### ozon_api_settings Table

| Column       | Type         | Description                     |
| ------------ | ------------ | ------------------------------- |
| id           | INT          | Primary key                     |
| client_id    | VARCHAR(255) | Ozon API Client ID              |
| api_key_hash | VARCHAR(255) | Hashed API Key (Argon2ID)       |
| access_token | TEXT         | Cached access token             |
| token_expiry | TIMESTAMP    | Token expiration time           |
| is_active    | BOOLEAN      | Whether configuration is active |
| created_at   | TIMESTAMP    | Creation timestamp              |
| updated_at   | TIMESTAMP    | Last update timestamp           |

## Installation

### 1. Database Migration

First, ensure the main Ozon analytics tables exist:

```sql
-- Run if not already executed
SOURCE migrations/add_ozon_analytics_tables.sql;
```

Then add the token fields:

```sql
-- Add token caching fields
SOURCE migrations/add_ozon_token_fields.sql;
```

### 2. File Permissions

Ensure proper file permissions:

```bash
chmod 644 src/ozon_settings.php
chmod 644 src/api/ozon-settings.php
chmod 644 src/js/OzonSettingsManager.js
```

### 3. Web Server Configuration

Make sure the API endpoint is accessible:

- Verify `.htaccess` files in `src/` and `src/api/` directories
- Ensure PHP is configured to handle JSON requests
- Check that the database connection parameters are correct

## Usage

### Accessing the Settings Interface

1. **From Dashboard**: Click the "⚙️ Настройки API" button in the Ozon Analytics view
2. **Direct URL**: Navigate to `src/ozon_settings.php`

### Adding New Settings

1. Fill in the **Client ID** (numeric, 3-20 digits)
2. Enter the **API Key** (alphanumeric with dashes/underscores, 10-100 characters)
3. Click **"Тестировать подключение"** to verify credentials
4. Click **"Сохранить настройки"** to save

### Managing Existing Settings

- **Edit**: Click "Редактировать" to modify existing settings
- **Delete**: Click "Удалить" to remove settings (with confirmation)
- **Status**: View connection status and token validity

## API Endpoints

### GET /src/api/ozon-settings.php

#### Get Settings

```
GET ?action=get_settings
```

Returns all configured settings with status information.

#### Get Connection Status

```
GET ?action=get_connection_status
```

Returns current connection status for active settings.

### POST /src/api/ozon-settings.php

#### Save Settings

```
POST ?action=save_settings
Content-Type: application/json

{
  "client_id": "123456789",
  "api_key": "your-api-key-here"
}
```

#### Test Connection

```
POST ?action=test_connection
Content-Type: application/json

{
  "client_id": "123456789",
  "api_key": "your-api-key-here"
}
```

#### Validate Credentials

```
POST ?action=validate_credentials
Content-Type: application/json

{
  "client_id": "123456789",
  "api_key": "your-api-key-here"
}
```

### PUT /src/api/ozon-settings.php

#### Update Settings

```
PUT ?action=update_settings
Content-Type: application/json

{
  "settings_id": 1,
  "client_id": "123456789",
  "api_key": "your-new-api-key"
}
```

### DELETE /src/api/ozon-settings.php

#### Delete Settings

```
DELETE ?action=delete_settings&id=1
```

## Validation Rules

### Client ID

- **Required**: Cannot be empty
- **Format**: Must contain only digits
- **Length**: 3-20 characters
- **Example**: `123456789`

### API Key

- **Required**: Cannot be empty
- **Format**: Alphanumeric characters, dashes, and underscores only
- **Length**: 10-100 characters
- **Example**: `abc123-def456_ghi789`

## Security Considerations

### Password Hashing

API keys are hashed using Argon2ID with the following parameters:

- **Memory Cost**: 65536 KB
- **Time Cost**: 4 iterations
- **Threads**: 3 parallel threads

### Token Management

- Access tokens are cached for 30 minutes
- Automatic token refresh when expired
- Tokens are stored encrypted in the database

### Input Sanitization

- All inputs are validated and sanitized
- SQL injection protection via prepared statements
- XSS protection via HTML escaping

## Error Handling

### Common Error Types

| Error Type           | Description               | Solution                    |
| -------------------- | ------------------------- | --------------------------- |
| AUTHENTICATION_ERROR | Invalid credentials       | Check Client ID and API Key |
| RATE_LIMIT_EXCEEDED  | Too many API requests     | Wait and retry              |
| API_UNAVAILABLE      | Ozon API is down          | Try again later             |
| INVALID_PARAMETERS   | Validation failed         | Check input format          |
| DATABASE_ERROR       | Database connection issue | Check DB settings           |

### Error Response Format

```json
{
  "success": false,
  "error": "error_type",
  "message": "Human-readable error message",
  "field": "field_name" // For validation errors
}
```

## Testing

### Manual Testing

1. Run the test script: `php test_ozon_settings.php`
2. Check database tables and structure
3. Test the web interface functionality
4. Verify API endpoint responses

### Test Cases

- ✅ Database connection and table structure
- ✅ Input validation (Client ID and API Key)
- ✅ Password hashing (Argon2ID)
- ✅ CRUD operations
- ✅ File permissions and accessibility
- ✅ API endpoint responses

## Integration with Dashboard

The settings interface is integrated with the main dashboard:

1. **Navigation**: Settings button in Ozon Analytics view
2. **Styling**: Consistent Bootstrap theme
3. **Authentication**: Uses same database connection
4. **API Integration**: Seamless connection with OzonAnalyticsAPI class

## Troubleshooting

### Common Issues

#### "Database connection failed"

- Check database credentials in both files
- Verify MySQL service is running
- Ensure database exists and user has permissions

#### "API endpoint not accessible"

- Check web server configuration
- Verify `.htaccess` files
- Ensure PHP is processing requests correctly

#### "Token fields not found"

- Run the token fields migration: `migrations/add_ozon_token_fields.sql`
- Check table structure with `DESCRIBE ozon_api_settings`

#### "Validation errors"

- Ensure Client ID contains only digits
- Check API Key format (alphanumeric + dashes/underscores)
- Verify field lengths are within limits

### Debug Mode

To enable debug mode, add this to the top of `ozon_settings.php`:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Best Practices

### Security

1. **Regular Key Rotation**: Update API keys periodically
2. **Access Control**: Limit access to settings page
3. **Audit Logging**: Monitor settings changes
4. **Backup**: Regular database backups

### Performance

1. **Token Caching**: Utilize cached tokens when valid
2. **Connection Pooling**: Reuse database connections
3. **Rate Limiting**: Respect Ozon API limits
4. **Error Handling**: Graceful degradation on failures

### Maintenance

1. **Monitor Logs**: Check for errors and warnings
2. **Update Dependencies**: Keep libraries current
3. **Test Regularly**: Verify functionality after changes
4. **Documentation**: Keep this guide updated

## Support

For issues or questions:

1. Check the troubleshooting section
2. Run the test script for diagnostics
3. Review error logs
4. Verify database and API connectivity

## Changelog

### Version 1.0 (2025-01-05)

- Initial implementation
- Secure credential management
- Real-time validation
- Connection testing
- Bootstrap UI integration
- API endpoint structure
- Database migrations
- Comprehensive testing
