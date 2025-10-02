<?php
/**
 * Plugin Name: Manhattan Replenishment Dashboard
 * Description: Дашборд рекомендаций по пополнению остатков с шорткодом [manhattan_reco_dashboard]
 * Version: 1.0.0
 * Author: ETL Team
 */

if (!defined('ABSPATH')) { exit; }

// Автозагрузка простая
require_once __DIR__ . '/includes/class-recommendations-api.php';

class Manhattan_Reco_Dashboard_Plugin {
    const SLUG = 'manhattan-reco-dashboard';

    public function __construct() {
        add_shortcode('manhattan_reco_dashboard', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    // Подключение ассетов
    public function enqueue_assets() {
        if (!is_singular()) return;
        // Подключаем только на страницах, где есть шорткод
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'manhattan_reco_dashboard')) return;

        wp_enqueue_style('bootstrap-5', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css', [], '5.3.2');
        wp_enqueue_style('marketplace-separation', plugins_url('../../src/css/marketplace-separation.css', __FILE__), [], '1.0.0');
        wp_enqueue_script('bootstrap-5', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', [], '5.3.2', true);
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        wp_enqueue_script(self::SLUG, plugins_url('assets/js/reco-dashboard.js', __FILE__), ['jquery'], '1.0.0', true);
        wp_enqueue_script('marketplace-view-toggle', plugins_url('../../src/js/MarketplaceViewToggle.js', __FILE__), [], '1.0.0', true);
        wp_enqueue_script('marketplace-data-renderer', plugins_url('../../src/js/MarketplaceDataRenderer.js', __FILE__), [], '1.0.0', true);
        wp_enqueue_script('marketplace-dashboard-integration', plugins_url('../../src/js/MarketplaceDashboardIntegration.js', __FILE__), [], '1.0.0', true);

        // Возможность указать внешний API базовый URL через wp-config.php:
        // define('MANH_API_BASE', 'https://api.example.com');
        $api_base = defined('MANH_API_BASE') && MANH_API_BASE ? MANH_API_BASE : rest_url('manhattan/v1');
        wp_localize_script(self::SLUG, 'ManhattanReco', [
            'apiBase' => esc_url_raw($api_base),
            'nonce'   => wp_create_nonce('wp_rest')
        ]);
    }

    // Шорткод выводит шаблон
    public function render_shortcode($atts = []) {
        ob_start();
        include __DIR__ . '/templates/dashboard.php';
        return ob_get_clean();
    }

    // REST маршруты
    public function register_rest_routes() {
        register_rest_route('manhattan/v1', '/reco/summary', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_summary'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('manhattan/v1', '/reco/list', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_list'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('manhattan/v1', '/reco/export', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_export'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('manhattan/v1', '/turnover/top', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_turnover_top'],
            'permission_callback' => '__return_true'
        ]);
        
        // Marketplace-specific endpoints
        register_rest_route('manhattan/v1', '/margin/summary', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_margin_summary'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('manhattan/v1', '/margin/daily-chart', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_daily_chart'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('manhattan/v1', '/margin/top-products', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_top_products'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('manhattan/v1', '/margin/marketplace-comparison', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_marketplace_comparison'],
            'permission_callback' => '__return_true'
        ]);
    }

    private function get_api_instance() {
        // Используем стандартные константы WordPress для подключения к той же БД
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $name = defined('DB_NAME') ? DB_NAME : '';
        $user = defined('DB_USER') ? DB_USER : '';
        $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';

        // Возможность переопределения через константы плагина
        if (defined('MANH_DB_HOST')) $host = MANH_DB_HOST;
        if (defined('MANH_DB_NAME')) $name = MANH_DB_NAME;
        if (defined('MANH_DB_USER')) $user = MANH_DB_USER;
        if (defined('MANH_DB_PASS')) $pass = MANH_DB_PASS;

        return new Manhattan\Recommendations_API($host, $name, $user, $pass);
    }

    public function rest_summary($request) {
        try {
            $api = $this->get_api_instance();
            $data = $api->getSummary();
            return rest_ensure_response(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return new \WP_Error('reco_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_list($request) {
        try {
            $api = $this->get_api_instance();
            $status = $request->get_param('status');
            $limit  = intval($request->get_param('limit') ?: 50);
            $offset = intval($request->get_param('offset') ?: 0);
            $search = $request->get_param('search');
            $rows   = $api->getRecommendations($status, $limit, $offset, $search);
            return rest_ensure_response([
                'success' => true,
                'data' => $rows,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($rows)
                ]
            ]);
        } catch (\Throwable $e) {
            return new \WP_Error('reco_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_export($request) {
        try {
            $api = $this->get_api_instance();
            $status = $request->get_param('status');
            $csv = $api->exportCSV($status);
            return new \WP_REST_Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="stock_recommendations.csv"'
            ]);
        } catch (\Throwable $e) {
            return new \WP_Error('reco_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_turnover_top($request) {
        try {
            $api = $this->get_api_instance();
            $limit = intval($request->get_param('limit') ?: 10);
            $order = $request->get_param('order') ?: 'ASC'; // ASC: минимальные дни запаса первыми
            $rows  = $api->getTurnoverTop($limit, $order);
            return rest_ensure_response(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            return new \WP_Error('turnover_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_margin_summary($request) {
        try {
            $api = $this->get_api_instance();
            $marketplace = $request->get_param('marketplace');
            $start_date = $request->get_param('start_date') ?: date('Y-m-01');
            $end_date = $request->get_param('end_date') ?: date('Y-m-d');
            $client_id = $request->get_param('client_id');
            
            $data = $api->getMarginSummaryByMarketplace($start_date, $end_date, $marketplace, $client_id);
            return rest_ensure_response(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return new \WP_Error('margin_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_daily_chart($request) {
        try {
            $api = $this->get_api_instance();
            $marketplace = $request->get_param('marketplace');
            $start_date = $request->get_param('start_date') ?: date('Y-m-01');
            $end_date = $request->get_param('end_date') ?: date('Y-m-d');
            $client_id = $request->get_param('client_id');
            
            $data = $api->getDailyMarginChartByMarketplace($start_date, $end_date, $marketplace, $client_id);
            return rest_ensure_response(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return new \WP_Error('chart_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_top_products($request) {
        try {
            $api = $this->get_api_instance();
            $marketplace = $request->get_param('marketplace');
            $limit = intval($request->get_param('limit') ?: 10);
            $start_date = $request->get_param('start_date') ?: date('Y-m-01');
            $end_date = $request->get_param('end_date') ?: date('Y-m-d');
            
            $data = $api->getTopProductsByMarketplace($marketplace, $limit, $start_date, $end_date);
            return rest_ensure_response(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return new \WP_Error('products_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_marketplace_comparison($request) {
        try {
            $api = $this->get_api_instance();
            $start_date = $request->get_param('start_date') ?: date('Y-m-01');
            $end_date = $request->get_param('end_date') ?: date('Y-m-d');
            $client_id = $request->get_param('client_id');
            
            $data = $api->getMarketplaceComparison($start_date, $end_date, $client_id);
            return rest_ensure_response(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return new \WP_Error('comparison_error', $e->getMessage(), ['status' => 500]);
        }
    }
}

new Manhattan_Reco_Dashboard_Plugin();
