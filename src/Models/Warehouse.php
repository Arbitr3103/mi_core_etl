<?php
/**
 * Warehouse Model for mi_core_etl
 * Represents warehouse data and operations
 */

require_once __DIR__ . '/../utils/Logger.php';

class Warehouse {
    private $id;
    private $name;
    private $code;
    private $address;
    private $city;
    private $country;
    private $isActive;
    private $capacity;
    private $currentUtilization;
    private $contactEmail;
    private $contactPhone;
    private $timezone;
    private $createdAt;
    private $updatedAt;
    private $logger;
    
    // Warehouse types
    const TYPE_MAIN = 'main';
    const TYPE_REGIONAL = 'regional';
    const TYPE_DISTRIBUTION = 'distribution';
    const TYPE_FULFILLMENT = 'fulfillment';
    
    public function __construct(array $data = []) {
        $this->logger = Logger::getInstance();
        
        if (!empty($data)) {
            $this->fillFromArray($data);
        }
    }
    
    /**
     * Fill model from array data
     */
    public function fillFromArray(array $data): void {
        $this->id = isset($data['id']) ? (int)$data['id'] : null;
        $this->name = $data['name'] ?? '';
        $this->code = $data['code'] ?? '';
        $this->address = $data['address'] ?? '';
        $this->city = $data['city'] ?? '';
        $this->country = $data['country'] ?? '';
        $this->isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
        $this->capacity = isset($data['capacity']) ? (int)$data['capacity'] : 0;
        $this->currentUtilization = isset($data['current_utilization']) ? (int)$data['current_utilization'] : 0;
        $this->contactEmail = $data['contact_email'] ?? null;
        $this->contactPhone = $data['contact_phone'] ?? null;
        $this->timezone = $data['timezone'] ?? 'Europe/Moscow';
        
        // Handle date fields
        if (isset($data['created_at'])) {
            $this->createdAt = is_string($data['created_at']) 
                ? new DateTime($data['created_at']) 
                : $data['created_at'];
        } else {
            $this->createdAt = new DateTime();
        }
        
        if (isset($data['updated_at'])) {
            $this->updatedAt = is_string($data['updated_at']) 
                ? new DateTime($data['updated_at']) 
                : $data['updated_at'];
        } else {
            $this->updatedAt = new DateTime();
        }
        
        // Validate data after filling
        $this->validate();
    }
    
    /**
     * Validate warehouse data
     */
    public function validate(): array {
        $errors = [];
        
        // Required fields validation
        if (empty($this->name)) {
            $errors[] = 'Warehouse name is required';
        }
        
        if (empty($this->code)) {
            $errors[] = 'Warehouse code is required';
        }
        
        // Code format validation (alphanumeric with dashes/underscores)
        if (!empty($this->code) && !preg_match('/^[A-Za-z0-9_-]+$/', $this->code)) {
            $errors[] = 'Warehouse code must contain only alphanumeric characters, dashes, and underscores';
        }
        
        // Capacity validation
        if ($this->capacity < 0) {
            $errors[] = 'Warehouse capacity cannot be negative';
        }
        
        if ($this->currentUtilization < 0) {
            $errors[] = 'Current utilization cannot be negative';
        }
        
        if ($this->capacity > 0 && $this->currentUtilization > $this->capacity) {
            $errors[] = 'Current utilization cannot exceed capacity';
        }
        
        // Email validation
        if ($this->contactEmail && !filter_var($this->contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid contact email format';
        }
        
        // Timezone validation
        if (!in_array($this->timezone, timezone_identifiers_list())) {
            $errors[] = 'Invalid timezone';
        }
        
        if (!empty($errors)) {
            $this->logger->warning('Warehouse validation failed', [
                'warehouse_code' => $this->code,
                'errors' => $errors
            ]);
        }
        
        return $errors;
    }
    
    /**
     * Check if warehouse data is valid
     */
    public function isValid(): bool {
        return empty($this->validate());
    }
    
    /**
     * Calculate utilization percentage
     */
    public function getUtilizationPercentage(): float {
        if ($this->capacity <= 0) {
            return 0.0;
        }
        
        return ($this->currentUtilization / $this->capacity) * 100;
    }
    
    /**
     * Check if warehouse is at capacity
     */
    public function isAtCapacity(): bool {
        return $this->capacity > 0 && $this->currentUtilization >= $this->capacity;
    }
    
    /**
     * Check if warehouse is near capacity (>90%)
     */
    public function isNearCapacity(): bool {
        return $this->getUtilizationPercentage() >= 90.0;
    }
    
    /**
     * Get available capacity
     */
    public function getAvailableCapacity(): int {
        if ($this->capacity <= 0) {
            return 0;
        }
        
        return max(0, $this->capacity - $this->currentUtilization);
    }
    
    /**
     * Update utilization
     */
    public function updateUtilization(int $utilization): void {
        $oldUtilization = $this->currentUtilization;
        $this->currentUtilization = $utilization;
        $this->updatedAt = new DateTime();
        
        $this->logger->info('Warehouse utilization updated', [
            'warehouse_code' => $this->code,
            'old_utilization' => $oldUtilization,
            'new_utilization' => $this->currentUtilization,
            'utilization_percentage' => $this->getUtilizationPercentage(),
            'available_capacity' => $this->getAvailableCapacity()
        ]);
    }
    
    /**
     * Get warehouse status based on utilization
     */
    public function getStatus(): string {
        if (!$this->isActive) {
            return 'inactive';
        }
        
        if ($this->isAtCapacity()) {
            return 'at_capacity';
        }
        
        if ($this->isNearCapacity()) {
            return 'near_capacity';
        }
        
        $utilizationPercentage = $this->getUtilizationPercentage();
        
        if ($utilizationPercentage >= 70) {
            return 'high_utilization';
        }
        
        if ($utilizationPercentage >= 40) {
            return 'medium_utilization';
        }
        
        return 'low_utilization';
    }
    
    /**
     * Get status display text
     */
    public function getStatusDisplayText(): string {
        switch ($this->getStatus()) {
            case 'inactive':
                return 'Неактивный';
            case 'at_capacity':
                return 'Заполнен';
            case 'near_capacity':
                return 'Почти заполнен';
            case 'high_utilization':
                return 'Высокая загрузка';
            case 'medium_utilization':
                return 'Средняя загрузка';
            case 'low_utilization':
                return 'Низкая загрузка';
            default:
                return 'Неизвестно';
        }
    }
    
    /**
     * Get status badge color for UI
     */
    public function getStatusBadgeColor(): string {
        switch ($this->getStatus()) {
            case 'inactive':
                return 'gray';
            case 'at_capacity':
                return 'red';
            case 'near_capacity':
                return 'orange';
            case 'high_utilization':
                return 'yellow';
            case 'medium_utilization':
                return 'blue';
            case 'low_utilization':
                return 'green';
            default:
                return 'gray';
        }
    }
    
    /**
     * Get full address string
     */
    public function getFullAddress(): string {
        $parts = array_filter([$this->address, $this->city, $this->country]);
        return implode(', ', $parts);
    }
    
    /**
     * Get display name with code
     */
    public function getDisplayName(): string {
        return $this->name . ' (' . $this->code . ')';
    }
    
    /**
     * Convert to array for API responses
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'full_address' => $this->getFullAddress(),
            'is_active' => $this->isActive,
            'capacity' => $this->capacity,
            'current_utilization' => $this->currentUtilization,
            'available_capacity' => $this->getAvailableCapacity(),
            'utilization_percentage' => $this->getUtilizationPercentage(),
            'status' => $this->getStatus(),
            'status_display_text' => $this->getStatusDisplayText(),
            'status_badge_color' => $this->getStatusBadgeColor(),
            'is_at_capacity' => $this->isAtCapacity(),
            'is_near_capacity' => $this->isNearCapacity(),
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'timezone' => $this->timezone,
            'display_name' => $this->getDisplayName(),
            'created_at' => $this->createdAt ? $this->createdAt->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null
        ];
    }
    
    /**
     * Convert to JSON
     */
    public function toJson(): string {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Create from database row
     */
    public static function fromDatabase(array $row): Warehouse {
        return new self($row);
    }
    
    /**
     * Create collection from database rows
     */
    public static function collectionFromDatabase(array $rows): array {
        return array_map(function($row) {
            return self::fromDatabase($row);
        }, $rows);
    }
    
    /**
     * Get warehouse statistics
     */
    public function getStatistics(): array {
        return [
            'utilization_percentage' => $this->getUtilizationPercentage(),
            'available_capacity' => $this->getAvailableCapacity(),
            'status' => $this->getStatus(),
            'is_active' => $this->isActive,
            'is_at_capacity' => $this->isAtCapacity(),
            'is_near_capacity' => $this->isNearCapacity()
        ];
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCode(): string { return $this->code; }
    public function getAddress(): string { return $this->address; }
    public function getCity(): string { return $this->city; }
    public function getCountry(): string { return $this->country; }
    public function isActive(): bool { return $this->isActive; }
    public function getCapacity(): int { return $this->capacity; }
    public function getCurrentUtilization(): int { return $this->currentUtilization; }
    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function getContactPhone(): ?string { return $this->contactPhone; }
    public function getTimezone(): string { return $this->timezone; }
    public function getCreatedAt(): ?DateTime { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTime { return $this->updatedAt; }
    
    // Setters
    public function setId(int $id): void { $this->id = $id; }
    public function setName(string $name): void { $this->name = $name; }
    public function setCode(string $code): void { $this->code = $code; }
    public function setAddress(string $address): void { $this->address = $address; }
    public function setCity(string $city): void { $this->city = $city; }
    public function setCountry(string $country): void { $this->country = $country; }
    public function setActive(bool $isActive): void { $this->isActive = $isActive; }
    public function setCapacity(int $capacity): void { $this->capacity = $capacity; }
    public function setContactEmail(?string $contactEmail): void { $this->contactEmail = $contactEmail; }
    public function setContactPhone(?string $contactPhone): void { $this->contactPhone = $contactPhone; }
    public function setTimezone(string $timezone): void { $this->timezone = $timezone; }
}