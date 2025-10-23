<?php
/**
 * API класс для работы с рекомендациями по пополнению запасов
 * Подходит для встраивания в WordPress (через REST/admin-ajax/шорткод)
 */

class RecommendationsAPI {
    private $pdo;
    private $logFile = 'recommendations_api.log';

    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }

    /**
     * Логирование для отладки
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    /**
     * Получить сводку по рекомендациям
     */
    public function getSummary($marketplace = null) {
        $sql = "
            SELECT 
                COUNT(*) as total_recommendations,
                SUM(CASE WHEN sr.status = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
                SUM(CASE WHEN sr.status = 'normal' THEN 1 ELSE 0 END) as normal_count,
                SUM(CASE WHEN sr.status = 'low_priority' THEN 1 ELSE 0 END) as low_priority_count,
                SUM(sr.recommended_order_qty) as total_recommended_qty
            FROM stock_recommendations sr
        ";
        
        $params = [];
        
        // Add marketplace filtering if specified
        if ($marketplace !== null) {
            $sql .= " LEFT JOIN dim_products dp ON sr.product_id = dp.id WHERE ";
            $marketplace = strtolower(trim($marketplace));
            
            switch ($marketplace) {
                case 'ozon':
                    $sql .= " dp.sku_ozon IS NOT NULL";
                    break;
                case 'wildberries':
                    $sql .= " dp.sku_wb IS NOT NULL";
                    break;
                default:
                    throw new InvalidArgumentException("Неподдерживаемый маркетплейс: {$marketplace}. Допустимые значения: ozon, wildberries");
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $summary = $stmt->fetch();
        return $summary ?: [];
    }

    /**
     * Получить рекомендации с фильтрами и пагинацией
     */
    public function getRecommendations($status = null, $limit = 50, $offset = 0, $search = null, $marketplace = null) {
        $sql = "
            SELECT 
                sr.id,
                sr.product_id,
                sr.product_name,
                sr.current_stock,
                sr.recommended_order_qty,
                sr.status,
                sr.reason,
                sr.created_at,
                sr.updated_at,
                dp.sku_ozon,
                dp.sku_wb,
                CASE 
                    WHEN :marketplace_param = 'ozon' THEN dp.sku_ozon
                    WHEN :marketplace_param = 'wildberries' THEN dp.sku_wb
                    ELSE COALESCE(dp.sku_ozon, dp.sku_wb, sr.product_id)
                END as display_sku,
                :marketplace_param as marketplace_filter
            FROM stock_recommendations sr
            LEFT JOIN dim_products dp ON sr.product_id = dp.id
            WHERE 1=1
        ";
        $params = ['marketplace_param' => $marketplace];

        // Add marketplace filtering
        if ($marketplace !== null) {
            $marketplace = strtolower(trim($marketplace));
            switch ($marketplace) {
                case 'ozon':
                    $sql .= " AND dp.sku_ozon IS NOT NULL";
                    break;
                case 'wildberries':
                    $sql .= " AND dp.sku_wb IS NOT NULL";
                    break;
                default:
                    throw new InvalidArgumentException("Неподдерживаемый маркетплейс: {$marketplace}. Допустимые значения: ozon, wildberries");
            }
        }

        if ($status) {
            $sql .= " AND sr.status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $sql .= " AND (sr.product_id LIKE :search OR sr.product_name LIKE :search OR dp.sku_ozon LIKE :search OR dp.sku_wb LIKE :search)";
            $params['search'] = "%" . $search . "%";
        }

        $sql .= " ORDER BY 
            FIELD(sr.status, 'urgent','normal','low_priority'), 
            sr.recommended_order_qty DESC, 
            sr.updated_at DESC 
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":".$k, $v, $k === 'limit' || $k === 'offset' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Экспорт CSV
     */
    public function exportCSV($status = null, $marketplace = null) {
        $rows = $this->getRecommendations($status, 10000, 0, null, $marketplace);
        $fh = fopen('php://temp', 'w+');
        
        $headers = ['ID','SKU','Product Name','Current Stock','Recommended Qty','Status','Reason','Updated'];
        if ($marketplace === null) {
            $headers[] = 'Ozon SKU';
            $headers[] = 'WB SKU';
        }
        
        fputcsv($fh, $headers);
        
        foreach ($rows as $r) {
            $row = [
                $r['id'], 
                $r['display_sku'] ?? $r['product_id'], 
                $r['product_name'], 
                $r['current_stock'],
                $r['recommended_order_qty'], 
                $r['status'], 
                $r['reason'], 
                $r['updated_at']
            ];
            
            if ($marketplace === null) {
                $row[] = $r['sku_ozon'] ?? '';
                $row[] = $r['sku_wb'] ?? '';
            }
            
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Получить топ по оборачиваемости из представления v_product_turnover_30d
     */
    public function getTurnoverTop($limit = 10, $order = 'ASC', $marketplace = null) {
        $limit = max(1, min(100, (int)$limit));
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $sql = "
            SELECT 
                vt.product_id,
                vt.sku_ozon,
                vt.product_name,
                vt.total_sold_30d,
                vt.current_stock,
                vt.days_of_stock,
                dp.sku_wb,
                CASE 
                    WHEN :marketplace_param = 'ozon' THEN vt.sku_ozon
                    WHEN :marketplace_param = 'wildberries' THEN dp.sku_wb
                    ELSE COALESCE(vt.sku_ozon, dp.sku_wb, vt.product_id)
                END as display_sku,
                :marketplace_param as marketplace_filter
            FROM v_product_turnover_30d vt
            LEFT JOIN dim_products dp ON vt.product_id = dp.id
            WHERE vt.days_of_stock IS NOT NULL
        ";
        
        $params = ['marketplace_param' => $marketplace];
        
        // Add marketplace filtering
        if ($marketplace !== null) {
            $marketplace = strtolower(trim($marketplace));
            switch ($marketplace) {
                case 'ozon':
                    $sql .= " AND vt.sku_ozon IS NOT NULL";
                    break;
                case 'wildberries':
                    $sql .= " AND dp.sku_wb IS NOT NULL";
                    break;
                default:
                    throw new InvalidArgumentException("Неподдерживаемый маркетплейс: {$marketplace}. Допустимые значения: ozon, wildberries");
            }
        }
        
        $sql .= " ORDER BY vt.days_of_stock {$order} LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':marketplace_param', $marketplace, PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Получить рекомендации по маркетплейсам в разделенном виде
     */
    public function getRecommendationsByMarketplace($status = null, $limit = 50, $offset = 0, $search = null) {
        try {
            $ozonRecommendations = $this->getRecommendations($status, $limit, $offset, $search, 'ozon');
        } catch (Exception $e) {
            $ozonRecommendations = [];
        }
        
        try {
            $wildberriesRecommendations = $this->getRecommendations($status, $limit, $offset, $search, 'wildberries');
        } catch (Exception $e) {
            $wildberriesRecommendations = [];
        }
        
        return [
            'view_mode' => 'separated',
            'marketplaces' => [
                'ozon' => [
                    'name' => 'Ozon',
                    'display_name' => '📦 Ozon',
                    'recommendations' => $ozonRecommendations,
                    'count' => count($ozonRecommendations)
                ],
                'wildberries' => [
                    'name' => 'Wildberries', 
                    'display_name' => '🛍️ Wildberries',
                    'recommendations' => $wildberriesRecommendations,
                    'count' => count($wildberriesRecommendations)
                ]
            ]
        ];
    }
}
