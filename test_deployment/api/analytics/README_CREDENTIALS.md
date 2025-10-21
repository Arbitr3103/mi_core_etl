# Secure Credential Management System

This document describes the secure credential management system for the Regional Analytics API, which provides encrypted storage and automatic rotation of API credentials for marketplace integrations.

## 🔐 Overview

The credential management system provides:

- **Encrypted Storage**: All credentials are encrypted using AES-256-CBC encryption
- **Automatic Rotation**: Configurable rotation policies with notifications
- **Audit Trail**: Complete history of credential operations
- **Secure Access**: Role-based access with API key authentication
- **Monitoring**: Health checks and expiry warnings

## 📋 Components

### Core Files

- `CredentialManager.php` - Main credential management class
- `manage_credentials.php` - Command-line management tool
- `credential_rotation.php` - Automated rotation service
- `security_audit.php` - Security audit and compliance checking

### Setup Scripts

- `setup_credential_rotation_cron.sh` - Automated setup for cron jobs
- `monitor_credentials.sh` - Quick status monitoring

### Configuration

- `.env.credentials.example` - Example environment configuration
- `README_CREDENTIALS.md` - This documentation

## 🚀 Quick Start

### 1. Initial Setup

```bash
# Make setup script executable
chmod +x api/analytics/setup_credential_rotation_cron.sh

# Run the setup
./api/analytics/setup_credential_rotation_cron.sh
```

### 2. Configure Environment

```bash
# Copy example configuration
cp api/analytics/.env.credentials.example .env.credentials

# Edit configuration with your values
nano .env.credentials
```

### 3. Migrate Existing Credentials

```bash
# Migrate from environment variables to encrypted storage
php api/analytics/manage_credentials.php migrate
```

### 4. Verify Setup

```bash
# Run security audit
php api/analytics/security_audit.php

# Check credential status
php api/analytics/credential_rotation.php report
```

## 💻 Command Line Usage

### Managing Credentials

```bash
# Store a new credential
php manage_credentials.php store ozon api_key "your-api-key-here" 90

# List all credentials for a service
php manage_credentials.php list ozon

# Get credential information (masked)
php manage_credentials.php get ozon api_key

# Rotate a credential
php manage_credentials.php rotate ozon api_key "new-api-key" "scheduled_rotation"

# Deactivate a credential
php manage_credentials.php deactivate ozon api_key
```

### Monitoring and Maintenance

```bash
# Check for expiring credentials
php manage_credentials.php check

# View rotation history
php manage_credentials.php history ozon api_key

# Clean up expired credentials
php manage_credentials.php cleanup

# Run security audit
php security_audit.php

# Generate status report
php credential_rotation.php report
```

## 🔧 Configuration

### Environment Variables

Key environment variables for credential management:

```bash
# Encryption
ENCRYPTION_KEY=your_base64_encoded_key

# Notifications
EMAIL_ALERTS_TO=admin@company.com
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...

# Rotation Policies
CREDENTIAL_WARNING_DAYS=7
CREDENTIAL_AUTO_ROTATE_DAYS=3
CREDENTIAL_MAX_AGE_DAYS=90
```

### Rotation Policies

Configure rotation policies in `credential_rotation.php`:

```php
'rotation_policies' => [
    'ozon' => [
        'auto_rotate' => false,  // Manual rotation required
        'notify_expiry' => true,
        'expiry_warning_days' => 14
    ],
    'wildberries' => [
        'auto_rotate' => false,  // Manual rotation required
        'notify_expiry' => true,
        'expiry_warning_days' => 14
    ]
]
```

## 🔒 Security Features

### Encryption

- **Algorithm**: AES-256-CBC with random IV per credential
- **Key Management**: Separate encryption key stored securely
- **Key Rotation**: Support for encryption key rotation

### Access Control

- **File Permissions**: Strict file permissions (600 for keys, 644 for code)
- **Database Security**: Encrypted storage in database
- **Audit Logging**: All operations logged with timestamps

### Monitoring

- **Health Checks**: Automated system health monitoring
- **Security Audits**: Regular security compliance checking
- **Expiry Warnings**: Proactive notification of expiring credentials

## 📊 Monitoring and Alerts

### Automated Monitoring

The system includes automated monitoring via cron jobs:

```bash
# Credential rotation check (every 6 hours)
0 */6 * * * /path/to/credential_rotation_cron.sh

# Daily status report (8 AM)
0 8 * * * /path/to/credential_report_cron.sh

# Weekly cleanup (Sunday 2 AM)
0 2 * * 0 find /path/to/logs -name '*.log.old' -mtime +30 -delete
```

### Notifications

The system sends notifications for:

- **Expiring Credentials**: 7 days before expiry (configurable)
- **Failed Operations**: Authentication failures, encryption errors
- **Security Issues**: Audit failures, suspicious activity
- **System Health**: Daily status reports

### Notification Channels

- **Email**: SMTP-based email notifications
- **Slack**: Webhook-based Slack notifications
- **Logs**: Detailed logging to files

## 🛠 Troubleshooting

### Common Issues

#### Encryption Key Not Found

```bash
# Check if key file exists
ls -la .credentials_key

# Regenerate key if needed
php manage_credentials.php store test test_key "test" 1
php manage_credentials.php deactivate test test_key
```

#### Permission Denied Errors

```bash
# Fix file permissions
chmod 600 .credentials_key
chmod 644 api/analytics/CredentialManager.php
chmod 755 api/analytics/
```

#### Database Connection Issues

```bash
# Test database connection
php -r "require 'api/analytics/config.php'; var_dump(getAnalyticsDbConnection());"
```

#### Cron Jobs Not Running

```bash
# Check cron status
crontab -l | grep credential

# Check logs
tail -f logs/credential_rotation.log
```

### Debug Mode

Enable debug mode for troubleshooting:

```bash
# Set in .env.credentials
CREDENTIAL_DEBUG_MODE=true

# Run with verbose output
php manage_credentials.php list ozon
```

## 🔄 Rotation Procedures

### Manual Rotation

1. **Generate New Credentials**: Obtain new API keys from marketplace
2. **Store New Credentials**: Use management tool to store new keys
3. **Test New Credentials**: Verify new keys work correctly
4. **Deactivate Old Credentials**: Deactivate old keys after verification

```bash
# Example rotation procedure
php manage_credentials.php store ozon api_key "new-key-here" 90
php manage_credentials.php get ozon api_key  # Verify storage
# Test API calls with new credentials
php manage_credentials.php deactivate ozon old_api_key
```

### Automated Rotation

Currently, automated rotation is not implemented for marketplace APIs as it requires manual intervention with the marketplace platforms. The system will:

1. **Monitor Expiry**: Check credential expiry dates
2. **Send Warnings**: Notify administrators of upcoming expiry
3. **Send Urgent Alerts**: Critical notifications for imminent expiry
4. **Log Events**: Record all rotation-related events

## 📈 Best Practices

### Security

1. **Regular Audits**: Run security audits monthly
2. **Key Rotation**: Rotate encryption keys annually
3. **Access Control**: Limit access to credential management tools
4. **Monitoring**: Monitor logs for suspicious activity

### Operations

1. **Regular Backups**: Backup encrypted credentials securely
2. **Documentation**: Keep rotation procedures documented
3. **Testing**: Test credential rotation procedures regularly
4. **Monitoring**: Set up proper alerting for failures

### Compliance

1. **Audit Trail**: Maintain complete audit logs
2. **Access Logging**: Log all credential access
3. **Retention**: Follow data retention policies
4. **Reporting**: Generate regular compliance reports

## 📚 API Reference

### CredentialManager Class

```php
// Store credential
$manager->storeCredential($service, $type, $value, $expiryDays);

// Retrieve credential
$value = $manager->getCredential($service, $type);

// Rotate credential
$manager->rotateCredential($service, $type, $newValue, $reason);

// List credentials
$credentials = $manager->listServiceCredentials($service);

// Check expiring
$expiring = $manager->getExpiringCredentials($warningDays);
```

### Helper Functions

```php
// Get secure Ozon credentials
$ozonCreds = getSecureOzonCredentials();

// Get secure Wildberries credentials
$wbCreds = getSecureWildberriesCredentials();
```

## 🆘 Support

For issues with the credential management system:

1. **Check Logs**: Review credential rotation logs
2. **Run Audit**: Execute security audit for issues
3. **Check Configuration**: Verify environment variables
4. **Test Components**: Use management tools to test functionality

### Log Locations

- **Main Log**: `logs/credential_rotation.log`
- **Error Log**: `logs/credential_rotation_error.log`
- **Analytics Log**: `logs/analytics.log`
- **Audit Reports**: Generated on demand

### Emergency Procedures

If credentials are compromised:

1. **Immediate Deactivation**: Deactivate compromised credentials
2. **Generate New Keys**: Obtain new API keys from marketplace
3. **Update System**: Store new credentials securely
4. **Audit System**: Run full security audit
5. **Review Logs**: Check for unauthorized access

```bash
# Emergency credential deactivation
php manage_credentials.php deactivate ozon api_key
php manage_credentials.php deactivate ozon client_id

# Generate new credentials and store
php manage_credentials.php store ozon api_key "new-emergency-key" 30
php manage_credentials.php store ozon client_id "new-emergency-client-id" 30

# Run security audit
php security_audit.php --export emergency_audit_$(date +%Y%m%d_%H%M%S).json
```
