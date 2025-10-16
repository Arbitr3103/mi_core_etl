<?php

namespace MDM\ETL\Config;

use Exception;
use PDO;

/**
 * Менеджер конфигурации ETL системы
 * Управляет настройками извлечения данных из различных источников
 */
class ETLConfigManager
{
    private PDO $pdo;
    private array $config;
    private array $cache = [];
    
    public function __construct(PDO $pdo, array $baseConfig = [])
    {
        $this->pdo = $pdo;
        $this->config = $baseConfig;
        
        $this->loadConfigFromDatabase();
    }
    
    /**
     * Получение полной конфигурации для ETL системы
     * 
     * @return array Конфигурация
     */
    public function getETLConfig(): array
    {
        return [
            'ozon' => $this->getOzonConfig(),
            'wildberries' => $this->getWildberriesConfig(),
            'internal' => $this->getInternalConfig(),
            'scheduler' => $this->getSchedulerConfig()
        ];
    }
    
    /**
     * Получение конфигурации для Ozon API
     * 
     * @return array Конфигурация Ozon
     */
    public function getOzonConfig(): array
    {
        $config = [
            'client_id' => $this->getEnvValue('OZON_CLIENT_ID'),
            'api_key' => $this->getEnvValue('OZON_API_KEY'),
            'base_url' => $this->getConfigValue('ozon', 'base_url', 'https://api-seller.ozon.ru'),
            'rate_limits' => [
                'requests_per_second' => (int)$this->getConfigValue('ozon', 'rate_limit_requests_per_second', 10),
                'delay_between_requests' => (float)$this->getConfigValue('ozon', 'rate_limit_delay', 0.1)
            ],
            'max_retries' => (int)$this->getConfigValue('ozon', 'max_retries', 3),
            'timeout' => (int)$this->getConfigValue('ozon', 'timeout', 30),
            'default_filters' => [
                'visibility' => 'ALL',
                'limit' => (int)$this->getConfigValue('ozon', 'batch_size', 1000)
            ],
            
            // Active product filtering configuration
            'filter_active_only' => $this->getConfigValue('ozon', 'filter_active_only', 'true') === 'true',
            'activity_check_interval' => (int)$this->getConfigValue('ozon', 'activity_check_interval', 3600),
            'activity_checker' => [
                'stock_threshold' => (int)$this->getConfigValue('ozon', 'stock_threshold', 0),
                'required_states' => explode(',', $this->getConfigValue('ozon', 'required_states', 'processed')),
                'required_visibility' => $this->getConfigValue('ozon', 'required_visibility', 'VISIBLE'),
                'check_pricing' => $this->getConfigValue('ozon', 'check_pricing', 'true') === 'true',
                'batch_size' => (int)$this->getConfigValue('ozon', 'activity_batch_size', 100)
            ]
        ];
        
        if (empty($config['client_id']) || empty($config['api_key'])) {
            throw new Exception('Ozon API credentials not configured. Please set OZON_CLIENT_ID and OZON_API_KEY environment variables.');
        }
        
        return $config;
    }
    
    /**
     * Получение конфигурации для Wildberries API
     * 
     * @return array Конфигурация Wildberries
     */
    public function getWildberriesConfig(): array
    {
        $config = [
            'api_token' => $this->getEnvValue('WB_API_KEY'),
            'base_urls' => [
                'suppliers' => $this->getConfigValue('wildberries', 'suppliers_url', 'https://suppliers-api.wildberries.ru'),
                'content' => $this->getConfigValue('wildberries', 'content_url', 'https://content-api.wildberries.ru'),
                'statistics' => $this->getConfigValue('wildberries', 'statistics_url', 'https://statistics-api.wildberries.ru')
            ],
            'rate_limits' => [
                'requests_per_minute' => (int)$this->getConfigValue('wildberries', 'rate_limit_requests_per_minute', 100),
                'delay_between_requests' => (float)$this->getConfigValue('wildberries', 'rate_limit_delay', 0.6)
            ],
            'max_retries' => (int)$this->getConfigValue('wildberries', 'max_retries', 3),
            'timeout' => (int)$this->getConfigValue('wildberries', 'timeout', 30),
            'default_filters' => [
                'limit' => (int)$this->getConfigValue('wildberries', 'batch_size', 100)
            ]
        ];
        
        if (empty($config['api_token'])) {
            throw new Exception('Wildberries API token not configured. Please set WB_API_KEY environment variable.');
        }
        
        return $config;
    }
    
    /**
     * Получение конфигурации для внутренних систем
     * 
     * @return array Конфигурация внутренних систем
     */
    public function getInternalConfig(): array
    {
        return [
            'source_tables' => [
                'products' => $this->getConfigValue('internal', 'products_table', 'products'),
                'inventory' => $this->getConfigValue('internal', 'inventory_table', 'inventory'),
                'orders' => $this->getConfigValue('internal', 'orders_table', 'orders')
            ],
            'field_mappings' => [
                'products' => [
                    'sku' => $this->getConfigValue('internal', 'sku_field', 'sku'),
                    'name' => $this->getConfigValue('internal', 'name_field', 'name'),
                    'brand' => $this->getConfigValue('internal', 'brand_field', 'brand'),
                    'category' => $this->getConfigValue('internal', 'category_field', 'category'),
                    'price' => $this->getConfigValue('internal', 'price_field', 'price'),
                    'description' => $this->getConfigValue('internal', 'description_field', 'description')
                ]
            ],
            'max_retries' => (int)$this->getConfigValue('internal', 'max_retries', 2),
            'batch_size' => (int)$this->getConfigValue('internal', 'batch_size', 5000),
            'query_timeout' => (int)$this->getConfigValue('internal', 'query_timeout', 60)
        ];
    }
    
    /**
     * Получение конфигурации планировщика
     * 
     * @return array Конфигурация планировщика
     */
    public function getSchedulerConfig(): array
    {
        return [
            'lock_file' => $this->getConfigValue('scheduler', 'lock_file', sys_get_temp_dir() . '/mdm_etl.lock'),
            'full_sync_interval' => (int)$this->getConfigValue('scheduler', 'full_sync_interval', 24),
            'incremental_sync_interval' => (int)$this->getConfigValue('scheduler', 'incremental_sync_interval', 1),
            'max_parallel_jobs' => (int)$this->getConfigValue('scheduler', 'max_parallel_jobs', 3),
            'cleanup_logs_days' => (int)$this->getConfigValue('scheduler', 'cleanup_logs_days', 30),
            'default_limits' => [
                'max_pages' => 10,
                'records_per_run' => 10000
            ]
        ];
    }
    
    /**
     * Сохранение значения конфигурации
     * 
     * @param string $source Источник
     * @param string $key Ключ
     * @param string $value Значение
     * @param string|null $description Описание
     * @param bool $isEncrypted Зашифровано ли значение
     */
    public function setConfigValue(string $source, string $key, string $value, ?string $description = null, bool $isEncrypted = false): void
    {
        try {
            if ($isEncrypted) {
                $value = $this->encryptValue($value);
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_config (source, config_key, config_value, is_encrypted, description)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                config_value = VALUES(config_value),
                is_encrypted = VALUES(is_encrypted),
                description = VALUES(description),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([$source, $key, $value, $isEncrypted, $description]);
            
            // Очищаем кэш
            unset($this->cache[$source][$key]);
            
        } catch (Exception $e) {
            throw new Exception("Ошибка сохранения конфигурации: " . $e->getMessage());
        }
    }
    
    /**
     * Получение значения конфигурации
     * 
     * @param string $source Источник
     * @param string $key Ключ
     * @param mixed $default Значение по умолчанию
     * @return string Значение конфигурации
     */
    public function getConfigValue(string $source, string $key, $default = null): string
    {
        // Проверяем кэш
        if (isset($this->cache[$source][$key])) {
            return $this->cache[$source][$key];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT config_value, is_encrypted 
                FROM etl_config 
                WHERE source = ? AND config_key = ?
            ");
            $stmt->execute([$source, $key]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $value = $result['config_value'];
                
                if ($result['is_encrypted']) {
                    $value = $this->decryptValue($value);
                }
                
                // Кэшируем результат
                $this->cache[$source][$key] = $value;
                
                return $value;
            }
            
        } catch (Exception $e) {
            error_log("Ошибка получения конфигурации $source.$key: " . $e->getMessage());
        }
        
        return (string)$default;
    }
    
    /**
     * Получение значения из переменных окружения
     * 
     * @param string $key Ключ переменной окружения
     * @param mixed $default Значение по умолчанию
     * @return string|null Значение переменной
     */
    private function getEnvValue(string $key, $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key) ?? $default;
        return $value !== false ? $value : $default;
    }
    
    /**
     * Загрузка конфигурации из базы данных
     */
    private function loadConfigFromDatabase(): void
    {
        try {
            $stmt = $this->pdo->query("
                SELECT source, config_key, config_value, is_encrypted 
                FROM etl_config 
                WHERE 1=1
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = $row['config_value'];
                
                if ($row['is_encrypted']) {
                    $value = $this->decryptValue($value);
                }
                
                $this->cache[$row['source']][$row['config_key']] = $value;
            }
            
        } catch (Exception $e) {
            // Если таблица не существует, игнорируем ошибку
            error_log("Предупреждение: не удалось загрузить конфигурацию из БД: " . $e->getMessage());
        }
    }
    
    /**
     * Шифрование значения
     * 
     * @param string $value Значение для шифрования
     * @return string Зашифрованное значение
     */
    private function encryptValue(string $value): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Расшифровка значения
     * 
     * @param string $encryptedValue Зашифрованное значение
     * @return string Расшифрованное значение
     */
    private function decryptValue(string $encryptedValue): string
    {
        $key = $this->getEncryptionKey();
        $data = base64_decode($encryptedValue);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * Получение ключа шифрования
     * 
     * @return string Ключ шифрования
     */
    private function getEncryptionKey(): string
    {
        $key = $this->getEnvValue('ETL_ENCRYPTION_KEY');
        
        if (empty($key)) {
            // Генерируем ключ на основе конфигурации БД
            $dbConfig = [
                $this->getEnvValue('DB_HOST', 'localhost'),
                $this->getEnvValue('DB_NAME', 'mdm_db'),
                $this->getEnvValue('DB_USER', 'root')
            ];
            
            $key = hash('sha256', implode('|', $dbConfig) . 'mdm_etl_secret');
        }
        
        return substr($key, 0, 32); // AES-256 требует 32 байта
    }
    
    /**
     * Валидация конфигурации
     * 
     * @return array Список ошибок валидации
     */
    public function validateConfig(): array
    {
        $errors = [];
        
        try {
            // Проверяем Ozon конфигурацию
            $ozonConfig = $this->getOzonConfig();
            if (empty($ozonConfig['client_id'])) {
                $errors[] = 'Ozon Client ID не настроен';
            }
            if (empty($ozonConfig['api_key'])) {
                $errors[] = 'Ozon API Key не настроен';
            }
        } catch (Exception $e) {
            $errors[] = 'Ошибка конфигурации Ozon: ' . $e->getMessage();
        }
        
        try {
            // Проверяем Wildberries конфигурацию
            $wbConfig = $this->getWildberriesConfig();
            if (empty($wbConfig['api_token'])) {
                $errors[] = 'Wildberries API Token не настроен';
            }
        } catch (Exception $e) {
            $errors[] = 'Ошибка конфигурации Wildberries: ' . $e->getMessage();
        }
        
        // Проверяем подключение к БД
        try {
            $this->pdo->query('SELECT 1');
        } catch (Exception $e) {
            $errors[] = 'Ошибка подключения к базе данных: ' . $e->getMessage();
        }
        
        return $errors;
    }
    
    /**
     * Получение всех настроек для источника
     * 
     * @param string $source Источник
     * @return array Настройки источника
     */
    public function getSourceConfig(string $source): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT config_key, config_value, is_encrypted, description
                FROM etl_config 
                WHERE source = ?
                ORDER BY config_key
            ");
            $stmt->execute([$source]);
            
            $config = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = $row['config_value'];
                
                if ($row['is_encrypted']) {
                    $value = '[ENCRYPTED]'; // Не показываем зашифрованные значения
                }
                
                $config[$row['config_key']] = [
                    'value' => $value,
                    'is_encrypted' => (bool)$row['is_encrypted'],
                    'description' => $row['description']
                ];
            }
            
            return $config;
            
        } catch (Exception $e) {
            throw new Exception("Ошибка получения конфигурации источника $source: " . $e->getMessage());
        }
    }
    
    /**
     * Удаление настройки
     * 
     * @param string $source Источник
     * @param string $key Ключ
     */
    public function deleteConfigValue(string $source, string $key): void
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM etl_config 
                WHERE source = ? AND config_key = ?
            ");
            $stmt->execute([$source, $key]);
            
            // Очищаем кэш
            unset($this->cache[$source][$key]);
            
        } catch (Exception $e) {
            throw new Exception("Ошибка удаления конфигурации: " . $e->getMessage());
        }
    }
    
    /**
     * Экспорт конфигурации в файл
     * 
     * @param string $filePath Путь к файлу
     * @param bool $includeSecrets Включать ли секретные данные
     */
    public function exportConfig(string $filePath, bool $includeSecrets = false): void
    {
        try {
            $config = [];
            
            $stmt = $this->pdo->query("
                SELECT source, config_key, config_value, is_encrypted, description
                FROM etl_config 
                ORDER BY source, config_key
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['is_encrypted'] && !$includeSecrets) {
                    continue; // Пропускаем зашифрованные значения
                }
                
                $config[$row['source']][$row['config_key']] = [
                    'value' => $row['config_value'],
                    'is_encrypted' => (bool)$row['is_encrypted'],
                    'description' => $row['description']
                ];
            }
            
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if (file_put_contents($filePath, $json) === false) {
                throw new Exception("Не удалось записать файл конфигурации");
            }
            
        } catch (Exception $e) {
            throw new Exception("Ошибка экспорта конфигурации: " . $e->getMessage());
        }
    }
    
    /**
     * Импорт конфигурации из файла
     * 
     * @param string $filePath Путь к файлу
     */
    public function importConfig(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception("Файл конфигурации не найден: $filePath");
        }
        
        $json = file_get_contents($filePath);
        $config = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Ошибка парсинга JSON: " . json_last_error_msg());
        }
        
        try {
            $this->pdo->beginTransaction();
            
            foreach ($config as $source => $sourceConfig) {
                foreach ($sourceConfig as $key => $keyConfig) {
                    $this->setConfigValue(
                        $source,
                        $key,
                        $keyConfig['value'],
                        $keyConfig['description'] ?? null,
                        $keyConfig['is_encrypted'] ?? false
                    );
                }
            }
            
            $this->pdo->commit();
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Ошибка импорта конфигурации: " . $e->getMessage());
        }
    }
}