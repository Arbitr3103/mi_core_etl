<?php
/**
 * Compression Middleware
 * 
 * Implements response compression for large API responses
 * 
 * @version 1.0
 * @author Manhattan System
 */

class CompressionMiddleware {
    
    private $logger;
    private $enabled;
    private $compressionLevel;
    private $minSize;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->enabled = $this->isCompressionSupported();
        $this->compressionLevel = 6; // Balance between compression ratio and CPU usage
        $this->minSize = 1024; // Only compress responses larger than 1KB
    }
    
    /**
     * Check if compression should be applied
     * 
     * @param string $content - Response content
     * @param array $headers - Response headers
     * @return bool True if should compress
     */
    public function shouldCompress(string $content, array $headers = []): bool {
        if (!$this->enabled) {
            return false;
        }
        
        // Check content size
        if (strlen($content) < $this->minSize) {
            return false;
        }
        
        // Check if client accepts compression
        if (!$this->clientAcceptsCompression()) {
            return false;
        }
        
        // Check content type
        $contentType = $headers['Content-Type'] ?? 'application/json';
        if (!$this->isCompressibleContentType($contentType)) {
            return false;
        }
        
        // Don't compress if already compressed
        if (isset($headers['Content-Encoding'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Compress response content
     * 
     * @param string $content - Content to compress
     * @param string $method - Compression method (gzip, deflate)
     * @return array Compressed content and headers
     */
    public function compressResponse(string $content, string $method = 'gzip'): array {
        try {
            $originalSize = strlen($content);
            $startTime = microtime(true);
            
            switch ($method) {
                case 'gzip':
                    $compressed = gzencode($content, $this->compressionLevel);
                    break;
                    
                case 'deflate':
                    $compressed = gzdeflate($content, $this->compressionLevel);
                    break;
                    
                default:
                    throw new Exception("Unsupported compression method: {$method}");
            }
            
            if ($compressed === false) {
                throw new Exception("Compression failed");
            }
            
            $compressedSize = strlen($compressed);
            $compressionTime = microtime(true) - $startTime;
            $compressionRatio = round((1 - $compressedSize / $originalSize) * 100, 2);
            
            $this->logger->debug('Response compressed', [
                'method' => $method,
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'compression_ratio' => $compressionRatio . '%',
                'compression_time_ms' => round($compressionTime * 1000, 2)
            ]);
            
            return [
                'content' => $compressed,
                'headers' => [
                    'Content-Encoding' => $method,
                    'Content-Length' => $compressedSize,
                    'X-Original-Size' => $originalSize,
                    'X-Compression-Ratio' => $compressionRatio . '%'
                ]
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Compression failed', [
                'method' => $method,
                'content_size' => strlen($content),
                'error' => $e->getMessage()
            ]);
            
            // Return original content if compression fails
            return [
                'content' => $content,
                'headers' => []
            ];
        }
    }
    
    /**
     * Get best compression method supported by client
     * 
     * @return string|null Best compression method or null if none supported
     */
    public function getBestCompressionMethod(): ?string {
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        
        // Check for gzip support (preferred)
        if (strpos($acceptEncoding, 'gzip') !== false) {
            return 'gzip';
        }
        
        // Check for deflate support
        if (strpos($acceptEncoding, 'deflate') !== false) {
            return 'deflate';
        }
        
        return null;
    }
    
    /**
     * Check if client accepts compression
     * 
     * @return bool True if client accepts compression
     */
    private function clientAcceptsCompression(): bool {
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        return !empty($acceptEncoding) && (
            strpos($acceptEncoding, 'gzip') !== false ||
            strpos($acceptEncoding, 'deflate') !== false
        );
    }
    
    /**
     * Check if content type is compressible
     * 
     * @param string $contentType - Content type to check
     * @return bool True if compressible
     */
    private function isCompressibleContentType(string $contentType): bool {
        $compressibleTypes = [
            'application/json',
            'application/xml',
            'text/plain',
            'text/html',
            'text/css',
            'text/javascript',
            'application/javascript',
            'text/csv'
        ];
        
        foreach ($compressibleTypes as $type) {
            if (strpos($contentType, $type) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if compression is supported by the server
     * 
     * @return bool True if compression is supported
     */
    private function isCompressionSupported(): bool {
        return function_exists('gzencode') && function_exists('gzdeflate');
    }
    
    /**
     * Apply compression to response if appropriate
     * 
     * @param string $content - Response content
     * @param array $headers - Response headers
     * @return array Processed response with content and headers
     */
    public function processResponse(string $content, array $headers = []): array {
        if (!$this->shouldCompress($content, $headers)) {
            return [
                'content' => $content,
                'headers' => $headers
            ];
        }
        
        $compressionMethod = $this->getBestCompressionMethod();
        if (!$compressionMethod) {
            return [
                'content' => $content,
                'headers' => $headers
            ];
        }
        
        $result = $this->compressResponse($content, $compressionMethod);
        
        // Merge headers
        $finalHeaders = array_merge($headers, $result['headers']);
        
        return [
            'content' => $result['content'],
            'headers' => $finalHeaders
        ];
    }
    
    /**
     * Get compression statistics
     * 
     * @return array Compression statistics
     */
    public function getCompressionStats(): array {
        return [
            'enabled' => $this->enabled,
            'supported' => $this->isCompressionSupported(),
            'compression_level' => $this->compressionLevel,
            'min_size' => $this->minSize,
            'client_accepts' => $this->clientAcceptsCompression(),
            'best_method' => $this->getBestCompressionMethod()
        ];
    }
    
    /**
     * Set compression level
     * 
     * @param int $level - Compression level (1-9)
     */
    public function setCompressionLevel(int $level): void {
        $this->compressionLevel = max(1, min(9, $level));
        
        $this->logger->info('Compression level changed', [
            'level' => $this->compressionLevel
        ]);
    }
    
    /**
     * Set minimum size for compression
     * 
     * @param int $size - Minimum size in bytes
     */
    public function setMinSize(int $size): void {
        $this->minSize = max(0, $size);
        
        $this->logger->info('Compression minimum size changed', [
            'min_size' => $this->minSize
        ]);
    }
    
    /**
     * Enable or disable compression
     * 
     * @param bool $enabled - Whether to enable compression
     */
    public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled && $this->isCompressionSupported();
        
        $this->logger->info('Compression status changed', [
            'enabled' => $this->enabled
        ]);
    }
    
    /**
     * Estimate compression ratio for content
     * 
     * @param string $content - Content to estimate
     * @param string $method - Compression method
     * @return float Estimated compression ratio (0-1)
     */
    public function estimateCompressionRatio(string $content, string $method = 'gzip'): float {
        try {
            $originalSize = strlen($content);
            
            if ($originalSize === 0) {
                return 0.0;
            }
            
            // Use a sample for large content to estimate quickly
            $sampleSize = min(8192, $originalSize); // 8KB sample
            $sample = substr($content, 0, $sampleSize);
            
            switch ($method) {
                case 'gzip':
                    $compressed = gzencode($sample, $this->compressionLevel);
                    break;
                    
                case 'deflate':
                    $compressed = gzdeflate($sample, $this->compressionLevel);
                    break;
                    
                default:
                    return 0.0;
            }
            
            if ($compressed === false) {
                return 0.0;
            }
            
            $compressedSize = strlen($compressed);
            $ratio = 1 - ($compressedSize / $sampleSize);
            
            return max(0.0, min(1.0, $ratio));
            
        } catch (Exception $e) {
            $this->logger->error('Failed to estimate compression ratio', [
                'method' => $method,
                'content_size' => strlen($content),
                'error' => $e->getMessage()
            ]);
            
            return 0.0;
        }
    }
}