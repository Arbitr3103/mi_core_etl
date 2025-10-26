<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

/**
 * ETL Result Class
 * 
 * Represents the result of an ETL execution with success status,
 * metrics, and additional metadata
 */
class ETLResult
{
    private bool $success;
    private int $recordsProcessed;
    private float $duration;
    private array $metrics;
    private ?string $errorMessage;

    public function __construct(
        bool $success,
        int $recordsProcessed,
        float $duration,
        array $metrics = [],
        ?string $errorMessage = null
    ) {
        $this->success = $success;
        $this->recordsProcessed = $recordsProcessed;
        $this->duration = $duration;
        $this->metrics = $metrics;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Check if ETL execution was successful
     * 
     * @return bool True if successful, false otherwise
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get number of records processed
     * 
     * @return int Number of records processed
     */
    public function getRecordsProcessed(): int
    {
        return $this->recordsProcessed;
    }

    /**
     * Get execution duration in seconds
     * 
     * @return float Duration in seconds
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Get detailed metrics
     * 
     * @return array Detailed execution metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get error message if execution failed
     * 
     * @return string|null Error message or null if successful
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get formatted duration string
     * 
     * @return string Human-readable duration
     */
    public function getFormattedDuration(): string
    {
        if ($this->duration < 60) {
            return sprintf('%.2f seconds', $this->duration);
        } elseif ($this->duration < 3600) {
            return sprintf('%.1f minutes', $this->duration / 60);
        } else {
            return sprintf('%.1f hours', $this->duration / 3600);
        }
    }

    /**
     * Get summary of the ETL execution
     * 
     * @return array Summary data
     */
    public function getSummary(): array
    {
        return [
            'success' => $this->success,
            'records_processed' => $this->recordsProcessed,
            'duration' => $this->duration,
            'formatted_duration' => $this->getFormattedDuration(),
            'error_message' => $this->errorMessage,
            'metrics' => $this->metrics
        ];
    }

    /**
     * Convert result to JSON string
     * 
     * @return string JSON representation
     */
    public function toJson(): string
    {
        return json_encode($this->getSummary(), JSON_PRETTY_PRINT);
    }

    /**
     * Create a failed result
     * 
     * @param string $errorMessage Error message
     * @param float $duration Execution duration
     * @param array $metrics Partial metrics
     * @return self Failed ETL result
     */
    public static function failed(string $errorMessage, float $duration = 0.0, array $metrics = []): self
    {
        return new self(false, 0, $duration, $metrics, $errorMessage);
    }

    /**
     * Create a successful result
     * 
     * @param int $recordsProcessed Number of records processed
     * @param float $duration Execution duration
     * @param array $metrics Detailed metrics
     * @return self Successful ETL result
     */
    public static function success(int $recordsProcessed, float $duration, array $metrics = []): self
    {
        return new self(true, $recordsProcessed, $duration, $metrics);
    }
}