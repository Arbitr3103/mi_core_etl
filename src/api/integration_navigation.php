<?php
/**
 * Navigation Integration for Regional Analytics
 * Provides seamless navigation between main dashboard and regional analytics
 * Requirements: 6.4
 */

class NavigationIntegration {
    
    /**
     * Get navigation menu items for main dashboard
     */
    public static function getMainDashboardMenuItems() {
        return [
            [
                'id' => 'classic-dashboard',
                'title' => 'Классический дашборд',
                'description' => 'Основной дашборд для мониторинга остатков товаров и статистики маркетплейсов',
                'icon' => '📊',
                'url' => 'dashboard_marketplace_enhanced.php?tab=warehouse_products',
                'features' => [
                    'Просмотр остатков товаров',
                    'Статистика по маркетплейсам',
                    'Анализ продаж',
                    'Отчеты по складам'
                ],
                'status' => 'stable',
                'badge' => null
            ],
            [
                'id' => 'ozon-v4-api',
                'title' => 'Ozon v4 API',
                'description' => 'Новый дашборд для управления синхронизацией остатков через улучшенный v4 API',
                'icon' => '🚀',
                'url' => 'dashboard_inventory_v4.php',
                'features' => [
                    'Синхронизация через v4 API',
                    'Улучшенная обработка ошибок',
                    'Аналитика и валидация данных',
                    'Мониторинг в реальном времени',
                    'Детальное логирование'
                ],
                'status' => 'new',
                'badge' => 'NEW'
            ],
            [
                'id' => 'regional-analytics',
                'title' => 'Региональная аналитика',
                'description' => 'Аналитика продаж бренда ЭТОНОВО по регионам России и маркетплейсам',
                'icon' => '🌍',
                'url' => 'html/regional-dashboard/',
                'features' => [
                    'Сравнение Ozon vs Wildberries',
                    'Топ товары по маркетплейсам',
                    'Динамика продаж по регионам',
                    'Интеграция с Ozon API',
                    'Детальная региональная разбивка'
                ],
                'status' => 'new',
                'badge' => 'NEW'
            ]
        ];
    }
    
    /**
     * Get breadcrumb navigation for regional analytics
     */
    public static function getRegionalAnalyticsBreadcrumb($currentPage = 'dashboard') {
        $breadcrumbs = [
            [
                'title' => 'Главная панель',
                'url' => '../../dashboard_index.php',
                'active' => false
            ],
            [
                'title' => 'Региональная аналитика',
                'url' => 'index.html',
                'active' => $currentPage === 'dashboard'
            ]
        ];
        
        // Add specific page breadcrumbs
        switch ($currentPage) {
            case 'marketplace-comparison':
                $breadcrumbs[] = [
                    'title' => 'Сравнение маркетплейсов',
                    'url' => null,
                    'active' => true
                ];
                break;
            case 'regional-breakdown':
                $breadcrumbs[] = [
                    'title' => 'Региональная разбивка',
                    'url' => null,
                    'active' => true
                ];
                break;
            case 'product-analysis':
                $breadcrumbs[] = [
                    'title' => 'Анализ товаров',
                    'url' => null,
                    'active' => true
                ];
                break;
        }
        
        return $breadcrumbs;
    }
    
    /**
     * Generate breadcrumb HTML
     */
    public static function renderBreadcrumb($breadcrumbs) {
        $html = '<nav aria-label="breadcrumb">';
        $html .= '<ol class="breadcrumb">';
        
        foreach ($breadcrumbs as $crumb) {
            if ($crumb['active']) {
                $html .= '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($crumb['title']) . '</li>';
            } else {
                $html .= '<li class="breadcrumb-item">';
                $html .= '<a href="' . htmlspecialchars($crumb['url']) . '">' . htmlspecialchars($crumb['title']) . '</a>';
                $html .= '</li>';
            }
        }
        
        $html .= '</ol>';
        $html .= '</nav>';
        
        return $html;
    }
    
    /**
     * Get user context and permissions
     */
    public static function getUserContext() {
        // In a real implementation, this would check user session/authentication
        return [
            'user_id' => 1,
            'client_id' => 1, // ТД Манхэттен
            'permissions' => [
                'view_analytics' => true,
                'view_regional_data' => true,
                'export_data' => true,
                'manage_api_keys' => false // Only for admins
            ],
            'preferences' => [
                'default_date_range' => '30_days',
                'default_marketplace' => 'all',
                'timezone' => 'Europe/Moscow'
            ]
        ];
    }
    
    /**
     * Check if user has access to regional analytics
     */
    public static function hasRegionalAnalyticsAccess() {
        $context = self::getUserContext();
        return $context['permissions']['view_analytics'] && 
               $context['permissions']['view_regional_data'];
    }
    
    /**
     * Get navigation menu for regional analytics dashboard
     */
    public static function getRegionalAnalyticsMenu($currentPage = 'overview') {
        return [
            [
                'id' => 'overview',
                'title' => 'Обзор',
                'icon' => 'fas fa-tachometer-alt',
                'url' => 'index.html',
                'active' => $currentPage === 'overview'
            ],
            [
                'id' => 'marketplace-comparison',
                'title' => 'Сравнение маркетплейсов',
                'icon' => 'fas fa-balance-scale',
                'url' => 'marketplace-comparison.html',
                'active' => $currentPage === 'marketplace-comparison'
            ],
            [
                'id' => 'regional-breakdown',
                'title' => 'Региональная разбивка',
                'icon' => 'fas fa-map-marked-alt',
                'url' => 'regional-breakdown.html',
                'active' => $currentPage === 'regional-breakdown'
            ],
            [
                'id' => 'product-analysis',
                'title' => 'Анализ товаров',
                'icon' => 'fas fa-box-open',
                'url' => 'product-analysis.html',
                'active' => $currentPage === 'product-analysis'
            ],
            [
                'id' => 'api-status',
                'title' => 'Статус API',
                'icon' => 'fas fa-plug',
                'url' => 'api-status.html',
                'active' => $currentPage === 'api-status'
            ]
        ];
    }
    
    /**
     * Generate sidebar navigation HTML for regional analytics
     */
    public static function renderRegionalAnalyticsSidebar($currentPage = 'overview') {
        $menuItems = self::getRegionalAnalyticsMenu($currentPage);
        
        $html = '<div class="sidebar bg-light border-end" style="min-height: calc(100vh - 56px);">';
        $html .= '<div class="sidebar-sticky pt-3">';
        $html .= '<ul class="nav flex-column">';
        
        foreach ($menuItems as $item) {
            $activeClass = $item['active'] ? ' active' : '';
            $html .= '<li class="nav-item">';
            $html .= '<a class="nav-link' . $activeClass . '" href="' . htmlspecialchars($item['url']) . '">';
            $html .= '<i class="' . htmlspecialchars($item['icon']) . ' me-2"></i>';
            $html .= htmlspecialchars($item['title']);
            $html .= '</a>';
            $html .= '</li>';
        }
        
        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get quick access links for main dashboard
     */
    public static function getQuickAccessLinks() {
        return [
            [
                'title' => 'Региональная аналитика',
                'description' => 'Анализ продаж по регионам',
                'icon' => 'fas fa-map-marked-alt',
                'url' => 'html/regional-dashboard/',
                'color' => 'primary'
            ],
            [
                'title' => 'Ozon API Статус',
                'description' => 'Мониторинг интеграции',
                'icon' => 'fas fa-plug',
                'url' => 'api/analytics/health.php',
                'color' => 'success'
            ],
            [
                'title' => 'Экспорт данных',
                'description' => 'Выгрузка аналитики',
                'icon' => 'fas fa-download',
                'url' => 'api/analytics/export.php',
                'color' => 'info'
            ]
        ];
    }
}

// Usage example for including in dashboard pages
if (basename($_SERVER['PHP_SELF']) === 'dashboard_index.php') {
    // Main dashboard - show all menu items
    $menuItems = NavigationIntegration::getMainDashboardMenuItems();
    $quickLinks = NavigationIntegration::getQuickAccessLinks();
}
?>