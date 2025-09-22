<?php
/**
 * API endpoint для получения данных маржинальности через AJAX
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'MarginDashboardAPI.php';

try {
    // Настройки подключения к БД (замените на свои)
    $api = new MarginDashboardAPI('localhost', 'mi_core_db', 'username', 'password');
    
    $action = $_GET['action'] ?? '';
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    
    switch ($action) {
        case 'summary':
            $data = $api->getMarginSummary($startDate, $endDate, $clientId);
            break;
            
        case 'chart':
            $data = $api->getDailyMarginChart($startDate, $endDate, $clientId);
            break;
            
        case 'breakdown':
            $data = $api->getCostBreakdown($startDate, $endDate, $clientId);
            break;
            
        case 'kpi':
            $data = $api->getKPIMetrics($startDate, $endDate, $clientId);
            break;
            
        case 'top_days':
            $limit = (int)($_GET['limit'] ?? 10);
            $data = $api->getTopMarginDays($startDate, $endDate, $limit, $clientId);
            break;
            
        case 'clients':
            $data = $api->getClients();
            break;
            
        case 'compare':
            $previousStart = $_GET['previous_start'] ?? '';
            $previousEnd = $_GET['previous_end'] ?? '';
            
            if (empty($previousStart) || empty($previousEnd)) {
                throw new Exception('Не указаны даты для сравнения');
            }
            
            $data = $api->comparePeriods($startDate, $endDate, $previousStart, $previousEnd, $clientId);
            break;
            
        case 'table':
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $data = $api->getDailyDetailsTable($startDate, $endDate, $clientId, $page, $limit);
            break;
            
        case 'trend':
            $data = $api->getMarginTrend($startDate, $endDate, $clientId);
            break;
            
        case 'weekday_stats':
            $data = $api->getWeekdayStats($startDate, $endDate, $clientId);
            break;
            
        case 'export':
            // Экспорт данных в CSV
            $exportData = $api->getDailyDetailsTable($startDate, $endDate, $clientId, 1, 1000);
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="margin_data_' . $startDate . '_' . $endDate . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Заголовки CSV
            fputcsv($output, [
                'Дата',
                'Клиент',
                'Заказов',
                'Выручка',
                'Себестоимость',
                'Комиссии',
                'Логистика',
                'Прочие расходы',
                'Прибыль',
                'Маржинальность (%)'
            ]);
            
            // Данные
            foreach ($exportData as $row) {
                fputcsv($output, [
                    $row['metric_date'],
                    $row['client_name'],
                    $row['orders_cnt'],
                    $row['revenue'],
                    $row['cogs'],
                    $row['commission'],
                    $row['shipping'],
                    $row['other_expenses'],
                    $row['profit'],
                    $row['margin_percent']
                ]);
            }
            
            fclose($output);
            exit;
            
        default:
            throw new Exception('Неизвестное действие: ' . $action);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'meta' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'client_id' => $clientId,
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'meta' => [
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>