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
}
