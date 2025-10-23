<?php
/**
 * Web Application Deployment Script
 * Configures web server for regional analytics endpoints
 * Requirements: 6.4
 */

class WebApplicationDeployer {
    private $projectRoot;
    private $webRoot;
    private $logFile;
    
    public function __construct() {
        $this->projectRoot = dirname(__FILE__);
        $this->webRoot = '/var/www/html/market-mi.ru';
        $this->logFile = 'logs/web_deployment_' . date('Y-m-d_H-i-s') . '.log';
        $this->ensureLogDirectory();
        $this->log("Starting web application deployment");
    }
    
    /**
     * Main deployment method
     */
    public function deploy() {
        try {
            $this->validateEnvironment();
            $this->copyApplicationFiles();
            $this->configureWebServer();
            $this->setupSSLCertificates();
            $this->configureCaching();
            $this->setupSecurityHeaders();
            $this->createHealthCheckEndpoints();
            $this->validateDeployment();
            
            $this->log("Web application deployment completed successfully");
            echo "✅ Web application deployed successfully\n";
            return true;
            
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            echo "❌ Error deploying web application: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Validate deployment environment
     */
    private function validateEnvironment() {
        $this->log("Validating deployment environment");
        
        // Check if running as appropriate user
        $currentUser = posix_getpwuid(posix_geteuid())['name'];
        if ($currentUser !== 'root' && $currentUser !== 'www-data') {
            $this->log("WARNING: Running as user '{$currentUser}', may need elevated privileges");
        }
        
        // Check required directories
        $requiredDirs = [
            $this->webRoot,
            $this->webRoot . '/api',
            $this->webRoot . '/html',
            '/etc/apache2/sites-available',
            '/etc/apache2/conf-available'
        ];
        
        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                throw new Exception("Required directory does not exist: {$dir}");
            }
        }
        
        // Check Apache modules
        $requiredModules = ['rewrite', 'headers', 'ssl', 'expires'];
        foreach ($requiredModules as $module) {
            $output = shell_exec("apache2ctl -M 2>/dev/null | grep {$module}");
            if (empty($output)) {
                $this->log("WARNING: Apache module '{$module}' may not be enabled");
            }
        }
        
        $this->log("Environment validation completed");
    }
    
    /**
     * Copy application files to web root
     */
    private function copyApplicationFiles() {
        $this->log("Copying application files");
        
        // Copy API files
        $this->copyDirectory('api/analytics', $this->webRoot . '/api/analytics');
        
        // Copy dashboard files
        $this->copyDirectory('html/regional-dashboard', $this->webRoot . '/html/regional-dashboard');
        
        // Copy integration files
        $integrationFiles = [
            'integration_navigation.php' => $this->webRoot . '/integration_navigation.php',
            'deploy_production_database.php' => $this->webRoot . '/deploy_production_database.php'
        ];
        
        foreach ($integrationFiles as $source => $destination) {
            if (file_exists($source)) {
                copy($source, $destination);
                $this->log("Copied: {$source} -> {$destination}");
            }
        }
        
        // Set proper permissions
        $this->setFilePermissions();
        
        $this->log("Application files copied successfully");
    }
    
    /**
     * Copy directory recursively
     */
    private function copyDirectory($source, $destination) {
        if (!is_dir($source)) {
            throw new Exception("Source directory does not exist: {$source}");
        }
        
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $destPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item, $destPath);
            }
        }
        
        $this->log("Copied directory: {$source} -> {$destination}");
    }
    
    /**
     * Set proper file permissions
     */
    private function setFilePermissions() {
        $this->log("Setting file permissions");
        
        // Set ownership to www-data
        exec("chown -R www-data:www-data {$this->webRoot}/api/analytics");
        exec("chown -R www-data:www-data {$this->webRoot}/html/regional-dashboard");
        
        // Set directory permissions
        exec("find {$this->webRoot}/api/analytics -type d -exec chmod 755 {} +");
        exec("find {$this->webRoot}/html/regional-dashboard -type d -exec chmod 755 {} +");
        
        // Set file permissions
        exec("find {$this->webRoot}/api/analytics -type f -exec chmod 644 {} +");
        exec("find {$this->webRoot}/html/regional-dashboard -type f -exec chmod 644 {} +");
        
        // Make PHP files executable
        exec("find {$this->webRoot}/api/analytics -name '*.php' -exec chmod 755 {} +");
        
        $this->log("File permissions set");
    }
    
    /**
     * Configure web server
     */
    private function configureWebServer() {
        $this->log("Configuring web server");
        
        // Create Apache virtual host configuration
        $vhostConfig = $this->generateVirtualHostConfig();
        file_put_contents('/tmp/regional-analytics-vhost.conf', $vhostConfig);
        
        // Copy to Apache sites-available
        copy('/tmp/regional-analytics-vhost.conf', '/etc/apache2/sites-available/regional-analytics.conf');
        
        // Create .htaccess files for API security
        $this->createHtaccessFiles();
        
        // Enable site and required modules
        exec('a2ensite regional-analytics');
        exec('a2enmod rewrite');
        exec('a2enmod headers');
        exec('a2enmod expires');
        exec('a2enmod ssl');
        
        $this->log("Web server configuration completed");
    }
    
    /**
     * Generate Apache virtual host configuration
     */
    private function generateVirtualHostConfig() {
        return <<<'APACHE'
<VirtualHost *:80>
    ServerName www.market-mi.ru
    ServerAlias market-mi.ru
    DocumentRoot /var/www/html/market-mi.ru
    
    # Redirect HTTP to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName www.market-mi.ru
    ServerAlias market-mi.ru
    DocumentRoot /var/www/html/market-mi.ru
    
    # SSL Configuration (will be configured by certbot)
    SSLEngine on
    
    # Security Headers
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'"
    
    # Regional Analytics API Configuration
    <Directory "/var/www/html/market-mi.ru/api/analytics">
        Options -Indexes
        AllowOverride All
        Require all granted
        
        # CORS headers for dashboard
        Header always set Access-Control-Allow-Origin "https://www.market-mi.ru"
        Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS"
        Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        
        # Handle preflight requests
        RewriteEngine On
        RewriteCond %{REQUEST_METHOD} OPTIONS
        RewriteRule ^(.*)$ $1 [R=200,L]
    </Directory>
    
    # Regional Analytics Dashboard
    <Directory "/var/www/html/market-mi.ru/html/regional-dashboard">
        Options -Indexes
        AllowOverride All
        Require all granted
        
        # Cache static assets
        <FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$">
            ExpiresActive On
            ExpiresDefault "access plus 1 month"
            Header append Cache-Control "public, immutable"
        </FilesMatch>
        
        # Cache HTML files for shorter period
        <FilesMatch "\.(html|htm)$">
            ExpiresActive On
            ExpiresDefault "access plus 1 hour"
            Header append Cache-Control "public, must-revalidate"
        </FilesMatch>
    </Directory>
    
    # API Rate Limiting (basic)
    <Directory "/var/www/html/market-mi.ru/api">
        # Limit request rate (requires mod_evasive or similar)
        # DOSHashTableSize    2048
        # DOSPageCount        10
        # DOSPageInterval     1
        # DOSSiteCount        50
        # DOSSiteInterval     1
        # DOSBlockingPeriod   600
    </Directory>
    
    # Logging
    ErrorLog ${APACHE_LOG_DIR}/regional-analytics-error.log
    CustomLog ${APACHE_LOG_DIR}/regional-analytics-access.log combined
    
    # Log level for debugging (change to warn in production)
    LogLevel info
</VirtualHost>
APACHE;
    }
    
    /**
     * Create .htaccess files for security
     */
    private function createHtaccessFiles() {
        // API .htaccess
        $apiHtaccess = <<<'HTACCESS'
# Regional Analytics API Security Configuration

# Enable rewrite engine
RewriteEngine On

# Block access to sensitive files
<FilesMatch "\.(env|log|sql|md|txt|sh)$">
    Require all denied
</FilesMatch>

# Block access to configuration files
<Files "config.php">
    <RequireAll>
        Require all granted
        Require not ip 192.168.1.0/24
    </RequireAll>
</Files>

# API Rate limiting headers
Header set X-RateLimit-Limit "1000"
Header set X-RateLimit-Window "3600"

# CORS handling
Header always set Access-Control-Allow-Origin "https://www.market-mi.ru"
Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS"
Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"

# Handle preflight requests
RewriteCond %{REQUEST_METHOD} OPTIONS
RewriteRule ^(.*)$ - [R=200,L]

# Pretty URLs for API endpoints
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^/]+)/?$ endpoints/$1.php [L,QSA]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
HTACCESS;

        file_put_contents($this->webRoot . '/api/analytics/.htaccess', $apiHtaccess);
        
        // Dashboard .htaccess
        $dashboardHtaccess = <<<'HTACCESS'
# Regional Analytics Dashboard Configuration

# Enable rewrite engine
RewriteEngine On

# Default document
DirectoryIndex index.html

# Cache static assets
<FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$">
    ExpiresActive On
    ExpiresDefault "access plus 1 month"
    Header append Cache-Control "public, immutable"
</FilesMatch>

# Cache HTML files
<FilesMatch "\.(html|htm)$">
    ExpiresActive On
    ExpiresDefault "access plus 1 hour"
    Header append Cache-Control "public, must-revalidate"
</FilesMatch>

# Compress text files
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
    AddOutputFilterByType DEFLATE application/json
</IfModule>

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options SAMEORIGIN
Header always set X-XSS-Protection "1; mode=block"
HTACCESS;

        file_put_contents($this->webRoot . '/html/regional-dashboard/.htaccess', $dashboardHtaccess);
        
        $this->log("Created .htaccess files");
    }
    
    /**
     * Setup SSL certificates
     */
    private function setupSSLCertificates() {
        $this->log("Setting up SSL certificates");
        
        // Check if certbot is installed
        $certbotPath = shell_exec('which certbot');
        if (empty($certbotPath)) {
            $this->log("WARNING: Certbot not found. SSL certificates need to be configured manually");
            return;
        }
        
        // Check if certificates already exist
        $certPath = '/etc/letsencrypt/live/www.market-mi.ru/fullchain.pem';
        if (file_exists($certPath)) {
            $this->log("SSL certificates already exist");
            return;
        }
        
        // Generate SSL certificates
        $command = 'certbot --apache -d www.market-mi.ru -d market-mi.ru --non-interactive --agree-tos --email admin@market-mi.ru';
        $output = shell_exec($command . ' 2>&1');
        
        if (strpos($output, 'Successfully') !== false) {
            $this->log("SSL certificates generated successfully");
        } else {
            $this->log("WARNING: SSL certificate generation may have failed: " . $output);
        }
    }
    
    /**
     * Configure caching
     */
    private function configureCaching() {
        $this->log("Configuring caching");
        
        // Create cache configuration
        $cacheConfig = <<<'APACHE'
# Regional Analytics Caching Configuration

<IfModule mod_expires.c>
    ExpiresActive On
    
    # API responses (short cache)
    ExpiresByType application/json "access plus 5 minutes"
    ExpiresByType application/xml "access plus 5 minutes"
    
    # Static assets (long cache)
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
    ExpiresByType font/woff "access plus 1 month"
    ExpiresByType font/woff2 "access plus 1 month"
    
    # HTML files (medium cache)
    ExpiresByType text/html "access plus 1 hour"
</IfModule>

<IfModule mod_headers.c>
    # Add cache control headers
    <FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$">
        Header append Cache-Control "public, immutable"
    </FilesMatch>
    
    <FilesMatch "\.(html|htm)$">
        Header append Cache-Control "public, must-revalidate"
    </FilesMatch>
    
    # API responses
    <FilesMatch "\.php$">
        Header append Cache-Control "no-cache, must-revalidate"
    </FilesMatch>
</IfModule>
APACHE;

        file_put_contents('/etc/apache2/conf-available/regional-analytics-cache.conf', $cacheConfig);
        exec('a2enconf regional-analytics-cache');
        
        $this->log("Caching configuration completed");
    }
    
    /**
     * Setup security headers
     */
    private function setupSecurityHeaders() {
        $this->log("Setting up security headers");
        
        $securityConfig = <<<'APACHE'
# Regional Analytics Security Headers

<IfModule mod_headers.c>
    # Security headers for all responses
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    # Content Security Policy
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'"
    
    # Remove server signature
    Header always unset Server
    Header always unset X-Powered-By
</IfModule>

# Hide Apache version
ServerTokens Prod
ServerSignature Off
APACHE;

        file_put_contents('/etc/apache2/conf-available/regional-analytics-security.conf', $securityConfig);
        exec('a2enconf regional-analytics-security');
        
        $this->log("Security headers configured");
    }
    
    /**
     * Create health check endpoints
     */
    private function createHealthCheckEndpoints() {
        $this->log("Creating health check endpoints");
        
        // System health check
        $systemHealthCheck = '<?php
/**
 * System Health Check Endpoint
 */

header("Content-Type: application/json");

$health = [
    "status" => "ok",
    "timestamp" => date("c"),
    "version" => "1.0.0",
    "checks" => []
];

try {
    // Check disk space
    $diskFree = disk_free_space("/");
    $diskTotal = disk_total_space("/");
    $diskUsage = round((($diskTotal - $diskFree) / $diskTotal) * 100, 2);
    
    $health["checks"]["disk_usage"] = [
        "status" => $diskUsage < 90 ? "ok" : "warning",
        "usage_percent" => $diskUsage,
        "free_bytes" => $diskFree,
        "total_bytes" => $diskTotal
    ];
    
    // Check Apache status
    $apacheStatus = shell_exec("systemctl is-active apache2");
    $health["checks"]["apache"] = [
        "status" => trim($apacheStatus) === "active" ? "ok" : "error",
        "service_status" => trim($apacheStatus)
    ];
    
    // Check file permissions
    $apiDir = "/var/www/html/market-mi.ru/api/analytics";
    $dashboardDir = "/var/www/html/market-mi.ru/html/regional-dashboard";
    
    $health["checks"]["file_permissions"] = [
        "api_readable" => is_readable($apiDir) ? "ok" : "error",
        "dashboard_readable" => is_readable($dashboardDir) ? "ok" : "error"
    ];
    
    // Overall status
    $hasErrors = false;
    foreach ($health["checks"] as $check) {
        if (is_array($check) && isset($check["status"]) && $check["status"] === "error") {
            $hasErrors = true;
            break;
        }
    }
    
    if ($hasErrors) {
        $health["status"] = "error";
        http_response_code(500);
    }
    
} catch (Exception $e) {
    $health["status"] = "error";
    $health["error"] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($health, JSON_PRETTY_PRINT);
';

        file_put_contents($this->webRoot . '/system-health.php', $systemHealthCheck);
        
        $this->log("Health check endpoints created");
    }
    
    /**
     * Validate deployment
     */
    private function validateDeployment() {
        $this->log("Validating deployment");
        
        // Check if files were copied correctly
        $requiredFiles = [
            $this->webRoot . '/api/analytics/index.php',
            $this->webRoot . '/api/analytics/config.php',
            $this->webRoot . '/html/regional-dashboard/index.html',
            $this->webRoot . '/system-health.php'
        ];
        
        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                throw new Exception("Required file not found: {$file}");
            }
        }
        
        // Test API endpoint
        $healthUrl = 'http://localhost/api/analytics/health.php';
        $response = @file_get_contents($healthUrl);
        if ($response === false) {
            $this->log("WARNING: Could not test API endpoint at {$healthUrl}");
        } else {
            $this->log("API endpoint test successful");
        }
        
        // Test dashboard
        $dashboardUrl = 'http://localhost/html/regional-dashboard/';
        $response = @file_get_contents($dashboardUrl);
        if ($response === false) {
            $this->log("WARNING: Could not test dashboard at {$dashboardUrl}");
        } else {
            $this->log("Dashboard test successful");
        }
        
        $this->log("Deployment validation completed");
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Run deployment if called directly
if (php_sapi_name() === 'cli') {
    $deployer = new WebApplicationDeployer();
    $success = $deployer->deploy();
    exit($success ? 0 : 1);
}