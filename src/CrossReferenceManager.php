<?php
/**
 * CrossReferenceManager - Менеджер таблицы product_cross_reference
 * 
 * Управляет созданием и обновлением записей в таблице сопоставления товаров,
 * обновлением кэшированных названий и синхронизацией статусов.
 * 
 * Requirements: 1.1, 3.1, 8.4
 */

class CrossReferenceManager {
    private $db;
    private $logger;
    
    /**
     * Конструктор
     * 
     * @param PDO $db Подключение к базе данных
     * @param object|null $logger Логгер
     */
    public function __construct($db, $logger = null) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Создает или обновляет запись в cross_reference
     * 
     * @param array $data Данные товара
     * @return int|null ID записи или null при ошибке
     */
    public function createOrUpdate($data) {
        try {
            // Проверяем, существует ли запись
            $existingId = $this->findExistingRecord($data);
            
            if ($existingId) {
                $this->log('debug', 'Updating existing cross_reference record', [
                    'id' => $existingId,
                    'data' => $data
                ]);
                
                return $this->updateRecord($existingId, $data);
            } else {
                $this->log('debug', 'Creating new cross_reference record', ['data' => $data]);
                
                return $this->createRecord($data);
            }
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to create or update cross_reference', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return null;
        }
    }
    
    /**
     * Ищет существующую запись по различным ID
     * 
     * @param array $data Данные для поиска
     * @return int|null ID записи или null
     */
    private function findExistingRecord($data) {
        $conditions = [];
        $params = [];
        
        // Ищем по любому из доступных ID
        if (!empty($data['inventory_product_id'])) {
            $conditions[] = "inventory_product_id = :inventory_id";
            $params[':inventory_id'] = (string)$data['inventory_product_id'];
        }
        
        if (!empty($data['ozon_product_id'])) {
            $conditions[] = "ozon_product_id = :ozon_id";
            $params[':ozon_id'] = (string)$data['ozon_product_id'];
        }
        
        if (!empty($data['analytics_product_id'])) {
            $conditions[] = "analytics_product_id = :analytics_id";
            $params[':analytics_id'] = (string)$data['analytics_product_id'];
        }
        
        if (!empty($data['sku_ozon'])) {
            $conditions[] = "sku_ozon = :sku_ozon";
            $params[':sku_ozon'] = (string)$data['sku_ozon'];
        }
        
        if (empty($conditions)) {
            return null;
        }
        
        $sql = "
            SELECT id
            FROM product_cross_reference
            WHERE " . implode(' OR ', $conditions) . "
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return $result ? (int)$result['id'] : null;
    }
    
    /**
     * Создает новую запись
     * 
     * @param array $data Данные товара
     * @return int ID созданной записи
     */
    private function createRecord($data) {
        $sql = "
            INSERT INTO product_cross_reference (
                inventory_product_id,
                analytics_product_id,
                ozon_product_id,
                sku_ozon,
                cached_name,
                cached_brand,
                last_api_sync,
                sync_status,
                created_at,
                updated_at
            ) VALUES (
                :inventory_product_id,
                :analytics_product_id,
                :ozon_product_id,
                :sku_ozon,
                :cached_name,
                :cached_brand,
                :last_api_sync,
                :sync_status,
                NOW(),
                NOW()
            )
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':inventory_product_id' => $data['inventory_product_id'] ?? null,
            ':analytics_product_id' => $data['analytics_product_id'] ?? null,
            ':ozon_product_id' => $data['ozon_product_id'] ?? null,
            ':sku_ozon' => $data['sku_ozon'] ?? null,
            ':cached_name' => $data['cached_name'] ?? null,
            ':cached_brand' => $data['cached_brand'] ?? null,
            ':last_api_sync' => $data['last_api_sync'] ?? null,
            ':sync_status' => $data['sync_status'] ?? 'pending'
        ]);
        
        $id = (int)$this->db->lastInsertId();
        
        $this->log('info', 'Created cross_reference record', [
            'id' => $id,
            'inventory_product_id' => $data['inventory_product_id'] ?? null
        ]);
        
        return $id;
    }
    
    /**
     * Обновляет существующую запись
     * 
     * @param int $id ID записи
     * @param array $data Данные для обновления
     * @return int ID записи
     */
    private function updateRecord($id, $data) {
        $updates = [];
        $params = [':id' => $id];
        
        // Обновляем только предоставленные поля
        $updateableFields = [
            'inventory_product_id',
            'analytics_product_id',
            'ozon_product_id',
            'sku_ozon',
            'cached_name',
            'cached_brand',
            'last_api_sync',
            'sync_status'
        ];
        
        foreach ($updateableFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return $id;
        }
        
        $updates[] = "updated_at = NOW()";
        
        $sql = "
            UPDATE product_cross_reference
            SET " . implode(', ', $updates) . "
            WHERE id = :id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $this->log('info', 'Updated cross_reference record', [
            'id' => $id,
            'updated_fields' => array_keys($params)
        ]);
        
        return $id;
    }
    
    /**
     * Обновляет кэшированное название товара
     * 
     * @param string $productId ID товара (любой тип)
     * @param string $name Название товара
     * @param string|null $brand Бренд товара
     * @return bool true если обновление успешно
     */
    public function updateCachedName($productId, $name, $brand = null) {
        try {
            $sql = "
                UPDATE product_cross_reference
                SET 
                    cached_name = :name,
                    cached_brand = :brand,
                    last_api_sync = NOW(),
                    sync_status = 'synced',
                    updated_at = NOW()
                WHERE inventory_product_id = :product_id
                   OR ozon_product_id = :product_id
                   OR analytics_product_id = :product_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':brand' => $brand,
                ':product_id' => (string)$productId
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            $this->log('info', 'Updated cached name', [
                'product_id' => $productId,
                'name' => $name,
                'rows_affected' => $rowsAffected
            ]);
            
            return $rowsAffected > 0;
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to update cached name', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Обновляет статус синхронизации товара
     * 
     * @param string $productId ID товара
     * @param string $status Статус ('synced', 'pending', 'failed')
     * @return bool true если обновление успешно
     */
    public function updateSyncStatus($productId, $status) {
        try {
            $validStatuses = ['synced', 'pending', 'failed'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception("Invalid sync status: {$status}");
            }
            
            $sql = "
                UPDATE product_cross_reference
                SET 
                    sync_status = :status,
                    updated_at = NOW()
                WHERE inventory_product_id = :product_id
                   OR ozon_product_id = :product_id
                   OR analytics_product_id = :product_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':product_id' => (string)$productId
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            $this->log('debug', 'Updated sync status', [
                'product_id' => $productId,
                'status' => $status,
                'rows_affected' => $rowsAffected
            ]);
            
            return $rowsAffected > 0;
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to update sync status', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Создает записи для новых товаров из inventory_data
     * 
     * @param int $limit Максимальное количество товаров
     * @return int Количество созданных записей
     */
    public function createEntriesForNewProducts($limit = 100) {
        try {
            // Находим товары из inventory_data, которых нет в cross_reference
            $sql = "
                INSERT INTO product_cross_reference (
                    inventory_product_id,
                    ozon_product_id,
                    sku_ozon,
                    sync_status,
                    created_at,
                    updated_at
                )
                SELECT DISTINCT
                    CAST(i.product_id AS CHAR) as inventory_product_id,
                    CAST(i.product_id AS CHAR) as ozon_product_id,
                    CAST(i.product_id AS CHAR) as sku_ozon,
                    'pending' as sync_status,
                    NOW() as created_at,
                    NOW() as updated_at
                FROM inventory_data i
                LEFT JOIN product_cross_reference pcr 
                    ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
                WHERE i.product_id != 0
                  AND pcr.id IS NULL
                LIMIT :limit
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $created = $stmt->rowCount();
            
            $this->log('info', 'Created entries for new products', [
                'created' => $created,
                'limit' => $limit
            ]);
            
            return $created;
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to create entries for new products', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Получает товар по ID
     * 
     * @param string $productId ID товара
     * @return array|null Данные товара или null
     */
    public function getProduct($productId) {
        try {
            $sql = "
                SELECT *
                FROM product_cross_reference
                WHERE inventory_product_id = :product_id
                   OR ozon_product_id = :product_id
                   OR analytics_product_id = :product_id
                LIMIT 1
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':product_id' => (string)$productId]);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to get product', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Получает товары по статусу синхронизации
     * 
     * @param string $status Статус ('synced', 'pending', 'failed')
     * @param int $limit Максимальное количество
     * @return array Массив товаров
     */
    public function getProductsByStatus($status, $limit = 100) {
        try {
            $sql = "
                SELECT *
                FROM product_cross_reference
                WHERE sync_status = :status
                ORDER BY updated_at ASC
                LIMIT :limit
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to get products by status', [
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Связывает запись cross_reference с dim_products
     * 
     * @param int $crossRefId ID записи в cross_reference
     * @param string $skuOzon SKU в Ozon
     * @return bool true если связь создана
     */
    public function linkToDimProducts($crossRefId, $skuOzon) {
        try {
            $sql = "
                UPDATE dim_products
                SET cross_ref_id = :cross_ref_id
                WHERE sku_ozon = :sku_ozon
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':cross_ref_id' => $crossRefId,
                ':sku_ozon' => (string)$skuOzon
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            $this->log('debug', 'Linked to dim_products', [
                'cross_ref_id' => $crossRefId,
                'sku_ozon' => $skuOzon,
                'rows_affected' => $rowsAffected
            ]);
            
            return $rowsAffected > 0;
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to link to dim_products', [
                'cross_ref_id' => $crossRefId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Получает статистику таблицы cross_reference
     * 
     * @return array Статистика
     */
    public function getStatistics() {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
                    SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN cached_name IS NOT NULL AND cached_name NOT LIKE 'Товар%ID%' THEN 1 ELSE 0 END) as with_real_names,
                    MAX(last_api_sync) as last_sync_time
                FROM product_cross_reference
            ";
            
            $stmt = $this->db->query($sql);
            $stats = $stmt->fetch();
            
            return [
                'total' => (int)$stats['total'],
                'synced' => (int)$stats['synced'],
                'pending' => (int)$stats['pending'],
                'failed' => (int)$stats['failed'],
                'with_real_names' => (int)$stats['with_real_names'],
                'last_sync_time' => $stats['last_sync_time'],
                'sync_percentage' => $stats['total'] > 0 
                    ? round(($stats['synced'] / $stats['total']) * 100, 2)
                    : 0
            ];
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to get statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Логирует сообщение
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log($level, $message, $context = []) {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message, $context);
        }
    }
}
