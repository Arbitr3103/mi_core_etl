# Ozon Analytics Security Integration

## Overview

This document describes the comprehensive security integration implemented for the Ozon Analytics system. The security system provides access control, rate limiting, audit logging, and user management for all Ozon Analytics operations.

## Features

### 1. Access Control

- **Multi-level access system**: None (0), Read (1), Export (2), Admin (3)
- **Operation-based permissions**: Different operations require different access levels
- **User management**: Administrators can grant/revoke access levels
- **Session management**: Session timeout and concurrent session limits

### 2. Rate Limiting

- **Per-operation limits**: Different rate limits for different operations
- **User-based tracking**: Individual rate limits per user
- **Sliding window**: Hourly rate limit windows
- **Graceful degradation**: Proper error messages when limits are exceeded

### 3. Audit Logging

- **Comprehensive logging**: All security events are logged
- **Detailed context**: IP addresses, user agents, request details
- **Event types**: Access granted/denied, rate limiting, settings changes
- **Retention management**: Automatic cleanup of old logs

### 4. Security Middleware

- **Request validation**: All API requests go through security checks
- **IP filtering**: Support for allowed/blocked IP lists
- **Authentication integration**: Works with existing authentication systems
- **Error handling**: Proper security error responses

## Architecture

### Database Tables

#### `ozon_access_log`

Stores all security events and audit trail.

```sql
- id: Primary key
- event_type: Type of security event
- user_id: User identifier
- operation: Operation being performed
- ip_address: Client IP address
- user_agent: Client user agent
- context_data: Additional request context (JSON)
- details: Event-specific details (JSON)
- created_at: Timestamp
```

#### `ozon_user_access`

Manages user access levels.

```sql
- user_id: User identifier (Primary key)
- access_level: Access level (0-3)
- granted_by: Who granted the access
- granted_at: When access was granted
- is_active: Whether access is active
- notes: Additional notes
```

#### `ozon_rate_limit_counters`

Tracks rate limiting counters.

```sql
- user_id: User identifier
- operation: Operation being rate limited
- request_count: Number of requests in window
- window_start: Start of rate limit window
- last_request: Time of last request
```

#### `ozon_security_config`

Stores security configuration.

```sql
- config_key: Configuration key
- config_value: Configuration value (JSON)
- description: Configuration description
- updated_by: Who last updated
- updated_at: When last updated
```

### PHP Classes

#### `OzonSecurityManager`

Core security management class.

- Access control checking
- Rate limiting enforcement
- Audit logging
- User management
- Security statistics

#### `OzonSecurityMiddleware`

Middleware for request processing.

- Request validation
- IP filtering
- Authentication checking
- Security context building

#### `OzonSecurityIntegration` (JavaScript)

Frontend security integration.

- Permission checking
- Rate limit feedback
- Security alerts
- UI updates based on permissions

## Installation

### 1. Apply Database Migration

```bash
# Make the script executable
chmod +x apply_ozon_security_migration.sh

# Run the migration
./apply_ozon_security_migration.sh
```

### 2. Update API Endpoints

The security integration is automatically applied to all Ozon Analytics API endpoints through the middleware.

### 3. Configure Users

```sql
-- Grant admin access to a user
INSERT INTO ozon_user_access (user_id, access_level, granted_by, notes)
VALUES ('your_admin_user', 3, 'system', 'Initial admin user');

-- Grant read access to a user
INSERT INTO ozon_user_access (user_id, access_level, granted_by, notes)
VALUES ('regular_user', 1, 'your_admin_user', 'Regular analytics user');
```

### 4. Test the Integration

```bash
php test_ozon_security_integration.php
```

## Configuration

### Access Levels

- **0 - None**: No access to any operations
- **1 - Read**: Can view funnel data, demographics, and campaigns
- **2 - Export**: Read access + can export data
- **3 - Admin**: All access + can manage settings and users

### Rate Limits (per hour)

- **View operations**: 100 requests
- **Export operations**: 10 requests
- **Admin operations**: 50 requests

### Security Settings

```json
{
  "require_authentication": true,
  "enable_rate_limiting": true,
  "log_all_requests": true,
  "session_timeout": 3600,
  "max_concurrent_sessions": 5,
  "cleanup_logs_after_days": 90
}
```

## API Endpoints

### Security-Specific Endpoints

#### `GET /security-stats`

Get security statistics.

- **Permission required**: Admin
- **Parameters**: `period` (hour, day, week, month)

#### `GET /user-permissions`

Get user permissions and configuration.

- **Permission required**: Read (for own permissions)
- **Parameters**: `target_user_id` (optional)

#### `POST /manage-access`

Manage user access levels.

- **Permission required**: Admin
- **Body**: `{"target_user_id": "user", "access_level": 2}`

#### `POST /cleanup-security`

Clean up old security records.

- **Permission required**: Admin
- **Body**: `{"days_to_keep": 90}`

## Frontend Integration

### HTML Attributes

Add security attributes to elements:

```html
<!-- Require specific permission -->
<button data-permission="export_data">Export Data</button>

<!-- Show rate limit info -->
<button data-rate-limit="export_data">Export</button>

<!-- Security check on form submission -->
<form data-security-check="manage_settings"></form>
```

### JavaScript Integration

```javascript
// Initialize security integration
const security = new OzonSecurityIntegration({
  apiBaseUrl: "/src/api/ozon-analytics.php",
  showSecurityAlerts: true,
  logClientEvents: true,
});

// Check permissions
if (security.hasPermission("export_data")) {
  // Show export button
}

// Listen for security events
security.addEventListener("accessDenied", (data) => {
  console.log("Access denied:", data);
});
```

## Monitoring and Maintenance

### Security Statistics

Monitor security through the dashboard or API:

- Failed authentication attempts
- Rate limit violations
- Access denied events
- User activity patterns

### Log Cleanup

Automatic cleanup runs daily at 2 AM:

```sql
-- Manual cleanup
CALL CleanupOzonSecurityLogs(90);
```

### User Management

```sql
-- View user activity
SELECT * FROM v_ozon_user_activity;

-- View security statistics
SELECT * FROM v_ozon_security_stats;

-- Update user access level
UPDATE ozon_user_access
SET access_level = 2, granted_by = 'admin'
WHERE user_id = 'user123';
```

## Troubleshooting

### Common Issues

#### 1. Access Denied Errors

- Check user access level in `ozon_user_access` table
- Verify operation permissions in security configuration
- Check audit logs for detailed error information

#### 2. Rate Limit Exceeded

- Check current rate limits in `ozon_security_config`
- Review user activity in `ozon_rate_limit_counters`
- Consider increasing limits for specific users/operations

#### 3. Authentication Issues

- Verify user ID extraction in middleware
- Check session configuration
- Review authentication integration

### Debug Mode

Enable detailed logging by setting log level:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Log Analysis

```sql
-- Recent security events
SELECT * FROM ozon_access_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC;

-- Failed access attempts
SELECT user_id, COUNT(*) as failures
FROM ozon_access_log
WHERE event_type = 'ACCESS_DENIED'
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY user_id
ORDER BY failures DESC;

-- Rate limit violations
SELECT user_id, operation, COUNT(*) as violations
FROM ozon_access_log
WHERE event_type = 'RATE_LIMITED'
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY user_id, operation
ORDER BY violations DESC;
```

## Security Best Practices

### 1. User Management

- Regularly review user access levels
- Remove access for inactive users
- Use principle of least privilege
- Document access grants in notes field

### 2. Monitoring

- Monitor failed authentication attempts
- Set up alerts for unusual activity
- Review security statistics regularly
- Investigate access denied patterns

### 3. Configuration

- Adjust rate limits based on usage patterns
- Keep security logs for compliance requirements
- Regularly update security configuration
- Test security integration after changes

### 4. Incident Response

- Have procedures for security incidents
- Know how to quickly revoke user access
- Maintain backup of security configurations
- Document security events and responses

## Support

For issues with the security integration:

1. Check the test script results
2. Review audit logs for error details
3. Verify database table structure
4. Test with minimal configuration
5. Contact system administrator

## Changelog

### Version 1.0 (2025-01-05)

- Initial security integration implementation
- Access control system
- Rate limiting functionality
- Audit logging
- Security middleware
- Frontend integration
- Database migration scripts
- Test suite
- Documentation
