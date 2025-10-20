<?php
/**
 * Security Middleware for Regional Analytics API
 * 
 * Provides comprehensive security validation including input sanitization,
 * SQL injection prevention, and security headers.
 */

require_once __DIR__ . '/../SecurityValidator.php';

// Set security headers
setAnalyticsCorsHeaders();

// Validate client IP
$clientIp = SecurityValidator::getClientIp();
$ipValidation = SecurityValidator::validateIpAddress($clientIp);

if (!$ipValidation['valid']) {
    SecurityValidator::logSecurityEvent('INVALID_IP', $ipValidation['error'], ['ip' => $clientIp]);
    sendAnalyticsErrorResponse('Access denied', 403, 'ACCESS_DENIED');
}

// Check for common attack patterns in request
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Check for suspicious patterns in URL
if (SecurityValidator::detectSqlInjection($requestUri)) {
    SecurityValidator::logSecurityEvent('SQL_INJECTION_URL', 'Suspicious patterns in URL', [
        'url' => $requestUri,
        'ip' => $clientIp
    ]);
    sendAnalyticsErrorResponse('Invalid request', 400, 'INVALID_REQUEST');
}

// Check for bot/scanner patterns in User-Agent
$suspiciousUserAgents = [
    'sqlmap', 'nikto', 'nmap', 'masscan', 'nessus', 'openvas',
    'w3af', 'skipfish', 'burp', 'owasp', 'acunetix'
];

foreach ($suspiciousUserAgents as $pattern) {
    if (stripos($userAgent, $pattern) !== false) {
        SecurityValidator::logSecurityEvent('SUSPICIOUS_USER_AGENT', 'Security scanner detected', [
            'user_agent' => $userAgent,
            'ip' => $clientIp
        ]);
        sendAnalyticsErrorResponse('Access denied', 403, 'ACCESS_DENIED');
    }
}

// Rate limiting by IP (basic protection)
$rateLimitKey = 'ip_rate_limit_' . md5($clientIp);
$rateLimitFile = sys_get_temp_dir() . '/' . $rateLimitKey;
$maxRequestsPerMinute = 60;

if (file_exists($rateLimitFile)) {
    $data = json_decode(file_get_contents($rateLimitFile), true);
    $currentMinute = floor(time() / 60);
    
    if ($data['minute'] === $currentMinute) {
        if ($data['count'] >= $maxRequestsPerMinute) {
            SecurityValidator::logSecurityEvent('RATE_LIMIT_IP', 'IP rate limit exceeded', [
                'ip' => $clientIp,
                'requests' => $data['count']
            ]);
            sendAnalyticsErrorResponse('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
        }
        $data['count']++;
    } else {
        $data = ['minute' => $currentMinute, 'count' => 1];
    }
} else {
    $data = ['minute' => floor(time() / 60), 'count' => 1];
}

file_put_contents($rateLimitFile, json_encode($data));

// Log security middleware execution
logAnalyticsActivity('DEBUG', 'Security middleware executed', [
    'ip' => $clientIp,
    'endpoint' => $requestUri,
    'user_agent' => substr($userAgent, 0, 100)
]);
?>