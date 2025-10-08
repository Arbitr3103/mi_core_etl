<?php

namespace MDM\Services;

/**
 * History Service for MDM System
 * Handles product history and audit trail
 */
class HistoryService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Get product history
     */
    public function getProductHistory(string $masterId, int $limit = 50): array
    {
        $sql = "
            SELECT 
                id,
                action,
                old_data,
                new_data,
                created_at
            FROM product_history 
            WHERE master_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$masterId, $limit]);
        $history = $stmt->fetchAll();

        // Process history entries to make them more readable
        return array_map([$this, 'processHistoryEntry'], $history);
    }

    /**
     * Get system-wide activity log
     */
    public function getActivityLog(int $page = 1, int $limit = 50, string $filter = 'all'): array
    {
        $offset = ($page - 1) * $limit;
        
        $whereClause = '';
        $params = [];
        
        if ($filter !== 'all') {
            $whereClause = 'WHERE action = ?';
            $params[] = $filter;
        }
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM product_history {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Get activity entries
        $sql = "
            SELECT 
                ph.id,
                ph.master_id,
                ph.action,
                ph.old_data,
                ph.new_data,
                ph.created_at,
                mp.canonical_name
            FROM product_history ph
            LEFT JOIN master_products mp ON ph.master_id = mp.master_id
            {$whereClause}
            ORDER BY ph.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $activities = $stmt->fetchAll();

        return [
            'activities' => array_map([$this, 'processHistoryEntry'], $activities),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ];
    }

    /**
     * Get history statistics
     */
    public function getHistoryStatistics(): array
    {
        $sql = "
            SELECT 
                action,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM product_history 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY action, DATE(created_at)
            ORDER BY date DESC, action
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Process history entry to make it more readable
     */
    private function processHistoryEntry(array $entry): array
    {
        $processed = $entry;
        
        // Decode JSON data
        if (!empty($entry['old_data'])) {
            $processed['old_data'] = json_decode($entry['old_data'], true);
        }
        
        if (!empty($entry['new_data'])) {
            $processed['new_data'] = json_decode($entry['new_data'], true);
        }
        
        // Generate human-readable description
        $processed['description'] = $this->generateActionDescription($entry);
        
        // Calculate changes if both old and new data exist
        if ($processed['old_data'] && $processed['new_data']) {
            $processed['changes'] = $this->calculateChanges($processed['old_data'], $processed['new_data']);
        }
        
        return $processed;
    }

    /**
     * Generate human-readable action description
     */
    private function generateActionDescription(array $entry): string
    {
        $action = $entry['action'];
        $masterId = $entry['master_id'];
        $productName = $entry['canonical_name'] ?? $masterId;
        
        switch ($action) {
            case 'created':
                return "Создан новый мастер-товар: {$productName}";
            
            case 'updated':
                $changes = [];
                if (!empty($entry['old_data']) && !empty($entry['new_data'])) {
                    $oldData = json_decode($entry['old_data'], true);
                    $newData = json_decode($entry['new_data'], true);
                    
                    foreach ($newData as $field => $newValue) {
                        $oldValue = $oldData[$field] ?? null;
                        if ($oldValue !== $newValue) {
                            $changes[] = $this->getFieldDisplayName($field);
                        }
                    }
                }
                
                $changesText = !empty($changes) ? ' (' . implode(', ', $changes) . ')' : '';
                return "Обновлен товар: {$productName}{$changesText}";
            
            case 'deleted':
                return "Удален товар: {$productName}";
            
            case 'merged_into':
                $newData = json_decode($entry['new_data'] ?? '{}', true);
                $primaryId = $newData['primary_master_id'] ?? 'неизвестно';
                return "Товар {$productName} объединен с {$primaryId}";
            
            case 'status_changed':
                $newData = json_decode($entry['new_data'] ?? '{}', true);
                $newStatus = $newData['status'] ?? 'неизвестно';
                return "Изменен статус товара {$productName} на: {$newStatus}";
            
            default:
                return "Выполнено действие '{$action}' для товара: {$productName}";
        }
    }

    /**
     * Calculate changes between old and new data
     */
    private function calculateChanges(array $oldData, array $newData): array
    {
        $changes = [];
        
        foreach ($newData as $field => $newValue) {
            $oldValue = $oldData[$field] ?? null;
            
            if ($oldValue !== $newValue) {
                $changes[] = [
                    'field' => $field,
                    'field_name' => $this->getFieldDisplayName($field),
                    'old_value' => $oldValue,
                    'new_value' => $newValue
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Get display name for field
     */
    private function getFieldDisplayName(string $field): string
    {
        $fieldNames = [
            'canonical_name' => 'Название',
            'canonical_brand' => 'Бренд',
            'canonical_category' => 'Категория',
            'description' => 'Описание',
            'status' => 'Статус',
            'attributes' => 'Атрибуты'
        ];
        
        return $fieldNames[$field] ?? $field;
    }

    /**
     * Export history to CSV
     */
    public function exportHistory(string $masterId = null, string $format = 'csv'): string
    {
        $whereClause = $masterId ? 'WHERE master_id = ?' : '';
        $params = $masterId ? [$masterId] : [];
        
        $sql = "
            SELECT 
                ph.master_id,
                ph.action,
                ph.created_at,
                mp.canonical_name
            FROM product_history ph
            LEFT JOIN master_products mp ON ph.master_id = mp.master_id
            {$whereClause}
            ORDER BY ph.created_at DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        
        if ($format === 'csv') {
            return $this->exportHistoryToCsv($history);
        }
        
        return json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export history to CSV format
     */
    private function exportHistoryToCsv(array $history): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, [
            'Master ID',
            'Product Name',
            'Action',
            'Date'
        ]);
        
        // Write data
        foreach ($history as $entry) {
            fputcsv($output, [
                $entry['master_id'],
                $entry['canonical_name'],
                $entry['action'],
                $entry['created_at']
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}