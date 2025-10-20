# Regional Analytics API - Authentication & Security

This document describes the authentication and security features implemented for the Regional Analytics API system.

## Overview

The Regional Analytics API implements a comprehensive security system including:

- API key-based authentication
- Rate limiting per API key and IP address
- Input validation and sanitization
- SQL injection prevention
- CORS configuration
- Security headers
- Request logging and monitoring

## Authentication System

### API Key Management

API keys are required for all protected endpoints. Each API key includes:

- Unique identifier (format: `ra_` + 64 hex characters)
- Name/description
- Client ID association
- Rate limit configuration
- Expiration date (optional)
- Usage tracking

### Generating API Keys

Use the CLI tool to generate new API keys:

```bash
# Generate a basic API key
php api/analytics/manage_api_keys.php generate "My API Key"

# Generate with custom settings
php api/analytics/manage_api_keys.php generate "Dashboard Key" 1 200 180
```

Parameters:

- `name`: Descriptive name for the API key
- `client_id`: Client ID (default: 1 for TD Manhattan)
- `rate_limit`: Requests per hour (default: 100)
- `expiry_days`: Days until expiration (default: 90)

### Using API Keys

API keys can be provided in three ways:

1. **X-API-Key Header** (Recommended):

   ```
   X-API-Key: ra_abc123...
   ```

2. **Authorization Bearer Token**:

   ```
   Authorization: Bearer ra_abc123...
   ```

3. **Query Parameter** (Development only):
   ```
   ?api_key=ra_abc123...
   ```

### Managing API Keys

```bash
# List all API keys for a client
php api/analytics/manage_api_keys.php list 1

# Revoke an API key
php api/analytics/manage_api_keys.php revoke ra_abc123...

# Clean up expired keys and old logs
php api/analytics/manage_api_keys.php cleanup
```

## Security Features

### Input Validation

All API parameters are validated using the `SecurityValidator` class:

- **Date validation**: YYYY-MM-DD format, valid calendar dates
- **Marketplace validation**: Only 'ozon', 'wildberries', or 'all'
- **Numeric validation**: Positive integers within defined ranges
- **String sanitization**: XSS prevention, HTML tag removal

### SQL Injection Prevention

- Pattern-based detection of SQL injection attempts
- Parameterized queries for all database operations
- Column name validation against whitelists
- SQL operator validation

### Rate Limiting

Two levels of rate limiting:

1. **API Key Rate Limiting**: Configurable per API key (default: 100 requests/hour)
2. **IP Rate Limiting**: 60 requests per minute per IP address

### CORS Configuration

Configured for specific allowed origins:

- `http://www.market-mi.ru`
- `https://www.market-mi.ru`
- Development origins (localhost, 127.0.0.1)

### Security Headers

Automatically applied to all responses:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy: default-src 'self'`

## Implementation

### Protecting Endpoints

To protect an API endpoint, include the authentication middleware:

```php
<?php
// At the top of your endpoint file
require_once __DIR__ . '/../middleware/auth.php';

// Optional: Include security middleware for additional validation
require_once __DIR__ . '/../middleware/security.php';

// Your endpoint code here...
?>
```

### Using Security Validator

For comprehensive input validation:

```php
<?php
require_once __DIR__ . '/../SecurityValidator.php';

// Define parameter schema
$paramSchema = [
    'date_from' => [
        'type' => 'date',
        'required' => false,
        'default' => date('Y-m-d', strtotime('-30 days'))
    ],
    'marketplace' => [
        'type' => 'marketplace',
        'required' => false,
        'default' => 'all'
    ]
];

// Validate request parameters
$params = validateSecureInput($paramSchema);
?>
```

## Database Schema

### API Keys Table

```sql
CREATE TABLE analytics_api_keys (
    id INT PRIMARY KEY AUTO_INCREMENT,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    client_id INT DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    rate_limit_per_hour INT DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    usage_count INT DEFAULT 0
);
```

### API Requests Log Table

```sql
CREATE TABLE analytics_api_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    api_key_id INT NOT NULL,
    endpoint VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_key_id) REFERENCES analytics_api_keys(id)
);
```

## Testing

Run the authentication test suite:

```bash
php api/analytics/test_authentication.php
```

This will test:

- API key generation and validation
- Rate limiting functionality
- Input validation
- SQL injection detection
- Date range validation

## Development Mode

For development, you can bypass authentication by adding `?dev_bypass=true` to requests when accessing from localhost. This should never be used in production.

## Monitoring and Logging

All security events are logged to:

- `logs/analytics.log` - General activity
- `logs/analytics_errors.log` - Errors and security events

Security events include:

- Invalid API key attempts
- Rate limit violations
- SQL injection attempts
- Suspicious user agents
- Invalid input patterns

## Best Practices

1. **Rotate API Keys**: Regularly generate new keys and revoke old ones
2. **Monitor Logs**: Check security logs for suspicious activity
3. **Use HTTPS**: Always use HTTPS in production
4. **Validate Input**: Use the SecurityValidator for all user input
5. **Rate Limiting**: Set appropriate rate limits based on usage patterns
6. **Regular Cleanup**: Run cleanup tasks to remove expired keys and old logs

## Error Responses

Authentication errors return standardized JSON responses:

```json
{
  "error": true,
  "message": "Authentication required. Please provide a valid API key.",
  "error_code": "AUTH_REQUIRED",
  "timestamp": "2025-10-20T10:30:00+00:00"
}
```

Common error codes:

- `AUTH_REQUIRED`: No API key provided
- `INVALID_API_KEY`: API key is invalid or expired
- `RATE_LIMIT_EXCEEDED`: Rate limit exceeded
- `INVALID_INPUT`: Input validation failed
- `ACCESS_DENIED`: IP or other access restriction

## Support

For issues with authentication or security features, check the logs first and ensure you're using a valid, non-expired API key with appropriate rate limits.
