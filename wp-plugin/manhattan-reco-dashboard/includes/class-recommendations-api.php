<?php
namespace Manhattan;

use PDO; 
use PDOException; 
use Exception;

/**
 * Класс доступа к рекомендациям (используется внутри WP плагина)
 */
class Recommendations_API {
    private $pdo;
    private $logFile = __DIR__ . '/../reco_api.log';

    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception('Ошибка подключения к БД: ' . $e->getMessage());
        }
    }

    private function log($message) {
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[{$ts}] {$message}\n", FILE_APPEND);
    }

    public function getSummary() {
        $sql = "
            SELECT 
                COUNT(*) as total_recommendations,
                SUM(CASE WHEN status = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
                SUM(CASE WHEN status = 'normal' THEN 1 ELSE 0 END) as normal_count,
                SUM(CASE WHEN status = 'low_priority' THEN 1 ELSE 0 END) as low_priority_count,
                SUM(recommended_order_qty) as total_recommended_qty
            FROM stock_recommendations
        ";
        $stmt = $this->pdo->query($sql);
        $summary = $stmt->fetch();
        return $summary ?: [];
    }

    public function getRecommendations($status = null, $limit = 50, $offset = 0, $search = null) {
        $sql = "
            SELECT 
                id,
                product_id,
                product_name,
                current_stock,
                recommended_order_qty,
                status,
                reason,
                created_at,
                updated_at
            FROM stock_recommendations
            WHERE 1=1
        ";
        $params = [];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $sql .= " AND (product_id LIKE :search OR product_name LIKE :search)";
            $params['search'] = "%" . $search . "%";
        }

        $sql .= " ORDER BY 
            FIELD(status, 'urgent','normal','low_priority'), 
            recommended_order_qty DESC, 
            updated_at DESC 
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":".$k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function exportCSV($status = null) {
        $rows = $this->getRecommendations($status, 10000, 0, null);
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['ID','SKU','Product Name','Current Stock','Recommended Qty','Status','Reason','Updated']);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['id'], $r['product_id'], $r['product_name'], $r['current_stock'],
                $r['recommended_order_qty'], $r['status'], $r['reason'], $r['updated_at']
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Получить топ по оборачиваемости из представления v_product_turnover_30d
     * По умолчанию выводим товары с наименьшим количеством дней запаса (риск скорого out-of-stock)
     */
    public function getTurnoverTop($limit = 10, $order = 'ASC') {
        // Безопасная нормализация параметров
        $limit = max(1, min(100, (int)$limit));
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        // Ожидаемые поля во вьюхе: product_id, sku_ozon, product_name, total_sold_30d, current_stock, days_of_stock
        $sql = "
            SELECT 
                product_id,
                sku_ozon,
                product_name,
                total_sold_30d,
                current_stock,
                days_of_stock
            FROM v_product_turnover_30d
            WHERE days_of_stock IS NOT NULL
            ORDER BY days_of_stock {$order}
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get margin summary by marketplace
     */
    public function getMarginSummaryByMarketplace($startDate, $endDate, $marketplace = null, $clientId = null) {
        $whereClause = "WHERE metric_date >= :start_date AND metric_date <= :end_date";
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        // Add marketplace filtering
        if ($marketplace) {
            if ($marketplace === 'ozon') {
                $whereClause .= " AND (source LIKE '%ozon%' OR source LIKE '%озон%')";
            } elseif ($marketplace === 'wildberries') {
                $whereClause .= " AND (source LIKE '%wildberries%' OR source LIKE '%wb%' OR source LIKE '%вб%')";
            }
        }

        if ($clientId) {
            $whereClause .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }

        $sql = "
            SELECT 
                SUM(revenue_sum) as revenue,
                SUM(cogs_sum) as cogs,
                SUM(commission_sum + shipping_sum + other_expenses_sum) as expenses,
                SUM(revenue_sum - COALESCE(cogs_sum,0) - commission_sum - shipping_sum - other_expenses_sum) as profit,
                ROUND(
                    (SUM(revenue_sum - COALESCE(cogs_sum,0) - commission_sum - shipping_sum - other_expenses_sum) / SUM(revenue_sum)) * 100, 2
                ) as margin_percent,
                COUNT(DISTINCT metric_date) as days_count,
                COUNT(*) as orders
            FROM metrics_daily 
            {$whereClause}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    /**
     * Get daily margin chart data by marketplace
     */
    public function getDailyMarginChartByMarketplace($startDate, $endDate, $marketplace = null, $clientId = null) {
        $whereClause = "WHERE fo.order_date >= :start_date AND fo.order_date <= :end_date";
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        // Add marketplace filtering
        if ($marketplace) {
            if ($marketplace === 'ozon') {
                $whereClause .= " AND (fo.source LIKE '%ozon%' OR fo.source LIKE '%озон%' OR dp.sku_ozon IS NOT NULL)";
            } elseif ($marketplace === 'wildberries') {
                $whereClause .= " AND (fo.source LIKE '%wildberries%' OR fo.source LIKE '%wb%' OR fo.source LIKE '%вб%' OR dp.sku_wb IS NOT NULL)";
            }
        }

        if ($clientId) {
            $whereClause .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }

        $sql = "
            SELECT 
                fo.order_date as metric_date,
                SUM(fo.price * fo.qty) as revenue,
                SUM((fo.price - COALESCE(fo.cost_price, fo.price * 0.7)) * fo.qty) as profit,
                ROUND(
                    (SUM((fo.price - COALESCE(fo.cost_price, fo.price * 0.7)) * fo.qty) / SUM(fo.price * fo.qty)) * 100, 2
                ) as margin_percent
            FROM fact_orders fo
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            {$whereClause}
            GROUP BY fo.order_date
            ORDER BY fo.order_date ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get top products by marketplace
     */
    public function getTopProductsByMarketplace($marketplace, $limit = 10, $startDate = null, $endDate = null, $minRevenue = 0) {
        $whereClause = "WHERE 1=1";
        $params = [];

        if ($startDate && $endDate) {
            $whereClause .= " AND fo.order_date >= :start_date AND fo.order_date <= :end_date";
            $params['start_date'] = $startDate;
            $params['end_date'] = $endDate;
        }

        // Add marketplace filtering
        if ($marketplace === 'ozon') {
            $whereClause .= " AND (fo.source LIKE '%ozon%' OR fo.source LIKE '%озон%' OR dp.sku_ozon IS NOT NULL)";
        } elseif ($marketplace === 'wildberries') {
            $whereClause .= " AND (fo.source LIKE '%wildberries%' OR fo.source LIKE '%wb%' OR fo.source LIKE '%вб%' OR dp.sku_wb IS NOT NULL)";
        }

        if ($minRevenue > 0) {
            $whereClause .= " HAVING revenue >= :min_revenue";
            $params['min_revenue'] = $minRevenue;
        }

        $skuField = "fo.sku";
        if ($marketplace === 'ozon') {
            $skuField = "COALESCE(dp.sku_ozon, fo.sku)";
        } elseif ($marketplace === 'wildberries') {
            $skuField = "COALESCE(dp.sku_wb, fo.sku)";
        }

        $sql = "
            SELECT 
                {$skuField} as sku,
                dp.product_name,
                SUM(fo.price * fo.qty) as revenue,
                SUM(fo.qty) as total_qty,
                COUNT(DISTINCT fo.order_id) as orders,
                ROUND(
                    (SUM((fo.price - COALESCE(fo.cost_price, fo.price * 0.7)) * fo.qty) / SUM(fo.price * fo.qty)) * 100, 2
                ) as margin_percent
            FROM fact_orders fo
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            {$whereClause}
            GROUP BY {$skuField}, dp.product_name
            ORDER BY revenue DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get marketplace comparison data
     */
    public function getMarketplaceComparison($startDate, $endDate, $clientId = null) {
        $ozonData = $this->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon', $clientId);
        $wildberriesData = $this->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries', $clientId);

        return [
            'ozon' => array_merge(['marketplace' => 'ozon'], $ozonData),
            'wildberries' => array_merge(['marketplace' => 'wildberries'], $wildberriesData)
        ];
    }
}
