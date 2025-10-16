<?php
/**
 * Инициализация конфигурации для фильтрации активных товаров
 * 
 * Этот скрипт устанавливает настройки по умолчанию для системы фильтрации
 * активных товаров в ETL процессе.
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/ETLConfigManager.php';

use MDM\ETL\Config\ETLConfigManager;

try {
    $pdo = getDatabaseConnection();
    $configManager = new ETLConfigManager($pdo);
    
    echo "🔧 Инициализация конфигурации активных товаров...\n";
    
    // Настройки фильтрации активных товаров для Ozon
    $ozonActiveProductConfig = [
        'filter_active_only' => [
            'value' => 'true',
            'description' => 'Включить фильтрацию только активных товаров по умолчанию'
        ],
        'activity_check_interval' => [
            'value' => '3600',
            'description' => 'Интервал проверки активности товаров в секундах (1 час)'
        ],
        'stock_threshold' => [
            'value' => '0',
            'description' => 'Минимальное количество остатков для считания товара активным'
        ],
        'required_states' => [
            'value' => 'processed',
            'description' => 'Требуемые состояния товара для активности (через запятую)'
        ],
        'required_visibility' => [
            'value' => 'VISIBLE',
            'description' => 'Требуемая видимость товара для активности'
        ],
        'check_pricing' => [
            'value' => 'true',
            'description' => 'Проверять наличие цены для определения активности'
        ],
        'activity_batch_size' => [
            'value' => '100',
            'description' => 'Размер батча для проверки активности товаров'
        ]
    ];
    
    echo "📝 Установка настроек Ozon...\n";
    foreach ($ozonActiveProductConfig as $key => $config) {
        $configManager->setConfigValue('ozon', $key, $config['value'], $config['description']);
        echo "  ✅ ozon.$key = {$config['value']}\n";
    }
    
    // Настройки планировщика для активных товаров
    $schedulerActiveProductConfig = [
        'activity_monitoring_enabled' => [
            'value' => 'true',
            'description' => 'Включить мониторинг изменений активности товаров'
        ],
        'activity_change_threshold' => [
            'value' => '10',
            'description' => 'Процент изменения активных товаров для отправки уведомления'
        ],
        'activity_notification_email' => [
            'value' => '',
            'description' => 'Email для уведомлений об изменениях активности товаров'
        ],
        'daily_activity_report' => [
            'value' => 'true',
            'description' => 'Создавать ежедневные отчеты об активности товаров'
        ],
        'activity_log_retention_days' => [
            'value' => '90',
            'description' => 'Количество дней хранения логов активности товаров'
        ]
    ];
    
    echo "📝 Установка настроек планировщика...\n";
    foreach ($schedulerActiveProductConfig as $key => $config) {
        $configManager->setConfigValue('scheduler', $key, $config['value'], $config['description']);
        echo "  ✅ scheduler.$key = {$config['value']}\n";
    }
    
    // Настройки для системы уведомлений
    $notificationConfig = [
        'notification_enabled' => [
            'value' => 'true',
            'description' => 'Включить систему уведомлений'
        ],
        'log_level' => [
            'value' => 'INFO',
            'description' => 'Уровень логирования для уведомлений (DEBUG, INFO, WARNING, ERROR)'
        ],
        'max_notification_frequency' => [
            'value' => '3600',
            'description' => 'Максимальная частота отправки уведомлений в секундах'
        ]
    ];
    
    echo "📝 Установка настроек уведомлений...\n";
    foreach ($notificationConfig as $key => $config) {
        $configManager->setConfigValue('notifications', $key, $config['value'], $config['description']);
        echo "  ✅ notifications.$key = {$config['value']}\n";
    }
    
    echo "\n✅ Конфигурация активных товаров успешно инициализирована!\n";
    echo "\n📋 Рекомендации по настройке:\n";
    echo "1. Установите email для уведомлений:\n";
    echo "   php etl_cli.php config scheduler activity_notification_email your@email.com\n";
    echo "\n2. При необходимости отключите фильтрацию активных товаров:\n";
    echo "   php etl_cli.php config ozon filter_active_only false\n";
    echo "\n3. Настройте интервал проверки активности (в секундах):\n";
    echo "   php etl_cli.php config ozon activity_check_interval 7200\n";
    echo "\n4. Проверьте статус конфигурации:\n";
    echo "   php etl_cli.php config ozon\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка инициализации конфигурации: " . $e->getMessage() . "\n";
    exit(1);
}