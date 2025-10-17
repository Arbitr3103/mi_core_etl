#!/bin/bash

# ============================================================================
# БЫСТРОЕ ЛОКАЛЬНОЕ РАЗВЕРТЫВАНИЕ СИСТЕМЫ ПОПОЛНЕНИЯ
# ============================================================================
# Описание: Автоматизированное развертывание для локального тестирования
# Версия: 1.0.0
# Дата: 2025-10-17
# Использование: ./quick_local_setup.sh
# ============================================================================

set -e

# Конфигурация
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DB_USER="replenishment_user"
DB_PASSWORD="secure_password_123"
DB_NAME="mi_core"
WEB_PORT="8080"

# Цвета
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m'

# ============================================================================
# ФУНКЦИИ ЛОГИРОВАНИЯ
# ============================================================================

log() {
    echo -e "${GREEN}[$(date +'%H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date +'%H:%M:%S')] ОШИБКА:${NC} $1"
}

warning() {
    echo -e "${YELLOW}[$(date +'%H:%M:%S')] ВНИМАНИЕ:${NC} $1"
}

info() {
    echo -e "${BLUE}[$(date +'%H:%M:%S')] ИНФО:${NC} $1"
}

success() {
    echo -e "${PURPLE}[$(date +'%H:%M:%S')] УСПЕХ:${NC} $1"
}

print_header() {
    echo -e "${PURPLE}"
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║                                                                ║"
    echo "║        🚀 БЫСТРОЕ ЛОКАЛЬНОЕ РАЗВЕРТЫВАНИЕ 🚀                  ║"
    echo "║           Система рекомендаций пополнения                     ║"
    echo "║                                                                ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# ============================================================================
# ПРОВЕРКА СИСТЕМНЫХ ТРЕБОВАНИЙ
# ============================================================================

check_requirements() {
    log "Проверка системных требований..."
    
    # Проверка PHP
    if ! command -v php &> /dev/null; then
        error "PHP не установлен. Установите PHP 7.4 или выше."
        exit 1
    fi
    
    local php_version=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
    log "✓ PHP версия: $php_version"
    
    # Проверка MySQL
    if ! command -v mysql &> /dev/null; then
        error "MySQL не установлен. Установите MySQL 5.7 или выше."
        exit 1
    fi
    
    log "✓ MySQL доступен"
    
    # Проверка PHP расширений
    local required_extensions=("pdo" "pdo_mysql" "json" "mbstring")
    for ext in "${required_extensions[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            error "PHP расширение $ext не установлено"
            exit 1
        fi
        log "✓ PHP расширение: $ext"
    done
    
    # Проверка веб-сервера
    if command -v nginx &> /dev/null; then
        log "✓ Nginx доступен"
        WEB_SERVER="nginx"
    elif command -v apache2 &> /dev/null; then
        log "✓ Apache доступен"
        WEB_SERVER="apache"
    else
        warning "Веб-сервер не найден. Будет использоваться встроенный PHP сервер."
        WEB_SERVER="php"
    fi
}

# ============================================================================
# НАСТРОЙКА БАЗЫ ДАННЫХ
# ============================================================================

setup_database() {
    log "Настройка базы данных..."
    
    # Проверка подключения к MySQL
    if ! mysql -u root -e "SELECT 1;" &> /dev/null; then
        error "Не удается подключиться к MySQL как root. Проверьте настройки."
        info "Попробуйте: mysql -u root -p"
        exit 1
    fi
    
    # Проверка существования базы данных
    if ! mysql -u root -e "USE $DB_NAME;" &> /dev/null; then
        error "База данных $DB_NAME не существует. Создайте её сначала."
        info "Выполните: CREATE DATABASE $DB_NAME;"
        exit 1
    fi
    
    log "✓ База данных $DB_NAME существует"
    
    # Создание пользователя
    log "Создание пользователя базы данных..."
    
    mysql -u root -e "
        CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
        GRANT SELECT, INSERT, UPDATE, DELETE ON $DB_NAME.* TO '$DB_USER'@'localhost';
        GRANT CREATE, DROP, ALTER ON $DB_NAME.replenishment_* TO '$DB_USER'@'localhost';
        GRANT EXECUTE ON $DB_NAME.* TO '$DB_USER'@'localhost';
        FLUSH PRIVILEGES;
    " 2>/dev/null || {
        warning "Пользователь уже существует или нет прав для создания"
    }
    
    # Проверка подключения нового пользователя
    if mysql -u "$DB_USER" -p"$DB_PASSWORD" -e "USE $DB_NAME;" &> /dev/null; then
        log "✓ Пользователь $DB_USER создан и может подключаться"
    else
        error "Не удается подключиться как $DB_USER"
        exit 1
    fi
    
    # Выполнение миграции
    log "Выполнение миграции базы данных..."
    
    local migration_file="$SCRIPT_DIR/migrate_replenishment_system.sql"
    if [ ! -f "$migration_file" ]; then
        error "Файл миграции не найден: $migration_file"
        exit 1
    fi
    
    if mysql -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$migration_file"; then
        log "✓ Миграция выполнена успешно"
    else
        error "Ошибка при выполнении миграции"
        exit 1
    fi
    
    # Проверка созданных таблиц
    local table_count=$(mysql -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "SHOW TABLES LIKE 'replenishment_%';" | tail -n +2 | wc -l)
    log "✓ Создано таблиц: $table_count"
}

# ============================================================================
# НАСТРОЙКА КОНФИГУРАЦИИ
# ============================================================================

setup_configuration() {
    log "Настройка конфигурации..."
    
    # Создание локальной конфигурации
    local config_file="$PROJECT_ROOT/config_replenishment.php"
    
    cat > "$config_file" << EOF
<?php
/**
 * Локальная конфигурация для тестирования системы пополнения
 * Автоматически сгенерировано: $(date)
 */

// ============================================================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ
// ============================================================================

define('DB_HOST', 'localhost');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASSWORD', '$DB_PASSWORD');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]);

// ============================================================================
// НАСТРОЙКИ СИСТЕМЫ ПОПОЛНЕНИЯ
// ============================================================================

define('REPLENISHMENT_ENABLED', true);
define('REPLENISHMENT_DEBUG', true);
define('REPLENISHMENT_LOG_LEVEL', 'debug');

define('REPLENISHMENT_MEMORY_LIMIT', '256M');
define('REPLENISHMENT_MAX_EXECUTION_TIME', 300);
define('REPLENISHMENT_BATCH_SIZE', 50);

// ============================================================================
// НАСТРОЙКИ EMAIL (ОТКЛЮЧЕНЫ ДЛЯ ЛОКАЛЬНОГО ТЕСТИРОВАНИЯ)
// ============================================================================

define('EMAIL_REPORTS_ENABLED', false);
define('SMTP_ENABLED', false);

// ============================================================================
// НАСТРОЙКИ API (УПРОЩЕНЫ ДЛЯ ЛОКАЛЬНОГО ТЕСТИРОВАНИЯ)
// ============================================================================

define('API_ENABLED', true);
define('API_DEBUG', true);
define('API_KEY_REQUIRED', false);
define('API_RATE_LIMIT', 1000);

// ============================================================================
// НАСТРОЙКИ ЛОГИРОВАНИЯ
// ============================================================================

define('LOG_DIR', __DIR__ . '/logs/replenishment');
define('LOG_FILE_CALCULATION', LOG_DIR . '/calculation.log');
define('LOG_FILE_ERROR', LOG_DIR . '/error.log');
define('LOG_FILE_API', LOG_DIR . '/api.log');

// ============================================================================
// НАСТРОЙКИ ОКРУЖЕНИЯ
// ============================================================================

define('ENVIRONMENT', 'development');
define('DEBUG_MODE', true);

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

ini_set('memory_limit', REPLENISHMENT_MEMORY_LIMIT);
ini_set('max_execution_time', REPLENISHMENT_MAX_EXECUTION_TIME);

date_default_timezone_set('Europe/Moscow');

// ============================================================================
// ПАРАМЕТРЫ РАСЧЕТА ПО УМОЛЧАНИЮ
// ============================================================================

define('DEFAULT_REPLENISHMENT_DAYS', 14);
define('DEFAULT_SAFETY_DAYS', 7);
define('DEFAULT_ANALYSIS_DAYS', 30);
define('DEFAULT_MIN_ADS_THRESHOLD', 0.1);
define('DEFAULT_MAX_RECOMMENDATION_QUANTITY', 10000);

// ============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================================

function getDbConnection() {
    static \$pdo = null;
    
    if (\$pdo === null) {
        \$dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASSWORD, DB_OPTIONS);
    }
    
    return \$pdo;
}

function logMessage(\$message, \$level = 'info', \$file = null) {
    \$file = \$file ?: LOG_FILE_CALCULATION;
    \$timestamp = date('Y-m-d H:i:s');
    \$logEntry = "[\$timestamp] [\$level] \$message" . PHP_EOL;
    
    \$logDir = dirname(\$file);
    if (!is_dir(\$logDir)) {
        mkdir(\$logDir, 0755, true);
    }
    
    file_put_contents(\$file, \$logEntry, FILE_APPEND | LOCK_EX);
    
    if (DEBUG_MODE) {
        echo \$logEntry;
    }
}

function getConfigParameter(\$name, \$default = null) {
    static \$cache = [];
    
    if (isset(\$cache[\$name])) {
        return \$cache[\$name];
    }
    
    try {
        \$pdo = getDbConnection();
        \$stmt = \$pdo->prepare("
            SELECT parameter_value, parameter_type 
            FROM replenishment_config 
            WHERE parameter_name = ? AND is_active = 1
        ");
        \$stmt->execute([\$name]);
        \$result = \$stmt->fetch();
        
        if (\$result) {
            \$value = \$result['parameter_value'];
            
            switch (\$result['parameter_type']) {
                case 'int':
                    \$value = (int)\$value;
                    break;
                case 'float':
                    \$value = (float)\$value;
                    break;
                case 'boolean':
                    \$value = \$value === 'true';
                    break;
            }
            
            \$cache[\$name] = \$value;
            return \$value;
        }
    } catch (Exception \$e) {
        logMessage("Failed to get config parameter \$name: " . \$e->getMessage(), 'error');
    }
    
    return \$default;
}

// Создание директории для логов
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

logMessage("Локальная конфигурация загружена", 'debug');

?>
EOF

    log "✓ Конфигурация создана: $config_file"
    
    # Создание директории для логов
    mkdir -p "$PROJECT_ROOT/logs/replenishment"
    chmod 755 "$PROJECT_ROOT/logs/replenishment"
    
    log "✓ Директория логов создана"
}

# ============================================================================
# ПРОВЕРКА ДАННЫХ
# ============================================================================

check_data() {
    log "Проверка исходных данных..."
    
    # Проверка наличия необходимых таблиц
    local required_tables=("fact_orders" "inventory_data" "dim_products")
    
    for table in "${required_tables[@]}"; do
        local count=$(mysql -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "SELECT COUNT(*) FROM $table;" 2>/dev/null | tail -n 1)
        if [ "$count" -gt 0 ]; then
            log "✓ Таблица $table: $count записей"
        else
            warning "Таблица $table пуста или не существует"
        fi
    done
    
    # Проверка данных за последние 30 дней
    local recent_orders=$(mysql -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
        SELECT COUNT(*) FROM fact_orders 
        WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    " 2>/dev/null | tail -n 1)
    
    if [ "$recent_orders" -gt 0 ]; then
        log "✓ Заказов за последние 30 дней: $recent_orders"
    else
        warning "Нет заказов за последние 30 дней. Рекомендации могут быть неточными."
        
        # Предложение создать тестовые данные
        echo -n "Создать тестовые данные? (y/N): "
        read -r create_test_data
        
        if [ "$create_test_data" = "y" ] || [ "$create_test_data" = "Y" ]; then
            create_test_data
        fi
    fi
}

# ============================================================================
# СОЗДАНИЕ ТЕСТОВЫХ ДАННЫХ
# ============================================================================

create_test_data() {
    log "Создание тестовых данных..."
    
    mysql -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" << 'EOF'
-- Создание тестовых товаров
INSERT IGNORE INTO dim_products (id, name, sku_ozon, is_active) VALUES
(1001, 'Тестовый товар 1', 'TEST-001', 1),
(1002, 'Тестовый товар 2', 'TEST-002', 1),
(1003, 'Тестовый товар 3', 'TEST-003', 1),
(1004, 'Тестовый товар 4', 'TEST-004', 1),
(1005, 'Тестовый товар 5', 'TEST-005', 1),
(1006, 'Тестовый товар 6', 'TEST-006', 1),
(1007, 'Тестовый товар 7', 'TEST-007', 1),
(1008, 'Тестовый товар 8', 'TEST-008', 1),
(1009, 'Тестовый товар 9', 'TEST-009', 1),
(1010, 'Тестовый товар 10', 'TEST-010', 1);

-- Создание тестовых продаж за последние 30 дней
SET @start_date = DATE_SUB(CURDATE(), INTERVAL 30 DAY);

INSERT IGNORE INTO fact_orders (product_id, order_date, qty, transaction_type)
SELECT 
    product_id,
    DATE_ADD(@start_date, INTERVAL day_offset DAY) as order_date,
    FLOOR(RAND() * 5) + 1 as qty,
    'sale' as transaction_type
FROM (
    SELECT 1001 as product_id UNION SELECT 1002 UNION SELECT 1003 UNION SELECT 1004 UNION SELECT 1005
    UNION SELECT 1006 UNION SELECT 1007 UNION SELECT 1008 UNION SELECT 1009 UNION SELECT 1010
) products
CROSS JOIN (
    SELECT 0 as day_offset UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11
    UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17
    UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23
    UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
) days
WHERE RAND() > 0.3; -- 70% вероятность продажи в день

-- Создание тестовых запасов
INSERT INTO inventory_data (product_id, current_stock, available_stock, warehouse_name, created_at)
SELECT 
    product_id,
    FLOOR(RAND() * 100) + 10 as current_stock,
    FLOOR(RAND() * 100) + 10 as available_stock,
    'Тестовый склад' as warehouse_name,
    NOW() as created_at
FROM (
    SELECT 1001 as product_id UNION SELECT 1002 UNION SELECT 1003 UNION SELECT 1004 UNION SELECT 1005
    UNION SELECT 1006 UNION SELECT 1007 UNION SELECT 1008 UNION SELECT 1009 UNION SELECT 1010
) products
ON DUPLICATE KEY UPDATE 
    current_stock = VALUES(current_stock),
    available_stock = VALUES(available_stock),
    created_at = VALUES(created_at);
EOF

    log "✓ Тестовые данные созданы"
}

# ============================================================================
# НАСТРОЙКА ВЕБ-СЕРВЕРА
# ============================================================================

setup_web_server() {
    log "Настройка веб-сервера..."
    
    case "$WEB_SERVER" in
        "nginx")
            setup_nginx
            ;;
        "apache")
            setup_apache
            ;;
        "php")
            setup_php_server
            ;;
    esac
}

setup_nginx() {
    log "Настройка Nginx..."
    
    local config_file="/tmp/replenishment-local-$$.conf"
    
    cat > "$config_file" << EOF
server {
    listen $WEB_PORT;
    server_name localhost;
    root $PROJECT_ROOT;
    index index.php;
    
    # API endpoint
    location ~ ^/api/replenishment\.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }
    
    # Dashboard
    location ~ ^/html/.*\.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Test scripts
    location ~ ^/test_.*\.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # General PHP processing
    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Static files
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1h;
        add_header Cache-Control "public";
    }
}
EOF

    info "Конфигурация Nginx создана: $config_file"
    info "Для активации выполните:"
    info "  sudo cp $config_file /etc/nginx/sites-available/replenishment-local"
    info "  sudo ln -s /etc/nginx/sites-available/replenishment-local /etc/nginx/sites-enabled/"
    info "  sudo nginx -t && sudo systemctl reload nginx"
    
    WEB_URL="http://localhost:$WEB_PORT"
}

setup_php_server() {
    log "Будет использован встроенный PHP сервер..."
    
    WEB_URL="http://localhost:$WEB_PORT"
    
    info "Для запуска веб-сервера выполните:"
    info "  cd $PROJECT_ROOT"
    info "  php -S localhost:$WEB_PORT"
}

# ============================================================================
# СОЗДАНИЕ ТЕСТОВЫХ СКРИПТОВ
# ============================================================================

create_test_scripts() {
    log "Создание тестовых скриптов..."
    
    # Тест подключения
    cat > "$PROJECT_ROOT/test_connection.php" << 'EOF'
<?php
require_once 'config_replenishment.php';

echo "=== ТЕСТ ПОДКЛЮЧЕНИЯ ===\n";

try {
    $pdo = getDbConnection();
    echo "✓ Подключение к базе данных успешно\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM replenishment_config");
    $config_count = $stmt->fetchColumn();
    echo "✓ Параметров конфигурации: $config_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM fact_orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $orders_count = $stmt->fetchColumn();
    echo "✓ Заказов за 30 дней: $orders_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory_data");
    $inventory_count = $stmt->fetchColumn();
    echo "✓ Записей запасов: $inventory_count\n";
    
    echo "\n🎉 Система готова к работе!\n";
    
} catch (Exception $e) {
    echo "✗ Ошибка: " . $e->getMessage() . "\n";
}
?>
EOF

    # Быстрый тест расчета
    cat > "$PROJECT_ROOT/test_quick_calculation.php" << 'EOF'
<?php
require_once 'config_replenishment.php';
require_once 'src/Replenishment/ReplenishmentRecommender.php';

echo "=== БЫСТРЫЙ ТЕСТ РАСЧЕТА ===\n";

try {
    $recommender = new ReplenishmentRecommender();
    
    echo "Запуск расчета рекомендаций...\n";
    $start_time = microtime(true);
    
    $result = $recommender->calculateRecommendations();
    
    $execution_time = microtime(true) - $start_time;
    
    echo "Расчет завершен за " . round($execution_time, 2) . " секунд\n";
    echo "Результат: " . ($result ? "✓ Успешно" : "✗ Ошибка") . "\n\n";
    
    if ($result) {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN recommended_quantity > 0 THEN 1 ELSE 0 END) as actionable
            FROM replenishment_recommendations 
            WHERE calculation_date = CURDATE()
        ");
        $stats = $stmt->fetch();
        
        echo "Всего рекомендаций: " . $stats['total'] . "\n";
        echo "Требуют пополнения: " . $stats['actionable'] . "\n";
        
        if ($stats['actionable'] > 0) {
            echo "\nТоп-5 рекомендаций:\n";
            $stmt = $pdo->query("
                SELECT product_name, recommended_quantity, ads
                FROM replenishment_recommendations 
                WHERE calculation_date = CURDATE() AND recommended_quantity > 0
                ORDER BY recommended_quantity DESC 
                LIMIT 5
            ");
            
            while ($row = $stmt->fetch()) {
                echo sprintf("  %s: %d шт. (ADS: %.2f)\n", 
                    $row['product_name'], 
                    $row['recommended_quantity'], 
                    $row['ads']
                );
            }
        }
    }
    
} catch (Exception $e) {
    echo "✗ Ошибка: " . $e->getMessage() . "\n";
}
?>
EOF

    chmod +x "$PROJECT_ROOT/test_connection.php"
    chmod +x "$PROJECT_ROOT/test_quick_calculation.php"
    
    log "✓ Тестовые скрипты созданы"
}

# ============================================================================
# ФИНАЛЬНАЯ ПРОВЕРКА
# ============================================================================

run_final_tests() {
    log "Выполнение финальных тестов..."
    
    # Тест подключения
    if php "$PROJECT_ROOT/test_connection.php" | grep -q "готова к работе"; then
        log "✓ Тест подключения пройден"
    else
        error "Тест подключения не пройден"
        return 1
    fi
    
    # Быстрый тест расчета
    if php "$PROJECT_ROOT/test_quick_calculation.php" | grep -q "Успешно"; then
        log "✓ Тест расчета пройден"
    else
        warning "Тест расчета не пройден (возможно, недостаточно данных)"
    fi
    
    return 0
}

# ============================================================================
# ГЛАВНАЯ ФУНКЦИЯ
# ============================================================================

main() {
    print_header
    
    log "Начинаем быстрое локальное развертывание..."
    log "Директория проекта: $PROJECT_ROOT"
    
    # Выполняем все этапы
    check_requirements
    setup_database
    setup_configuration
    check_data
    setup_web_server
    create_test_scripts
    
    if run_final_tests; then
        success "🎉 РАЗВЕРТЫВАНИЕ ЗАВЕРШЕНО УСПЕШНО!"
        
        echo ""
        echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
        echo -e "${GREEN}║                                                                ║${NC}"
        echo -e "${GREEN}║              ✅ СИСТЕМА ГОТОВА К РАБОТЕ! ✅                    ║${NC}"
        echo -e "${GREEN}║                                                                ║${NC}"
        echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
        echo ""
        
        echo -e "${BLUE}📋 Следующие шаги:${NC}"
        echo ""
        echo "1. Тестирование системы:"
        echo "   php test_connection.php"
        echo "   php test_quick_calculation.php"
        echo ""
        echo "2. Запуск веб-сервера (если нужно):"
        if [ "$WEB_SERVER" = "php" ]; then
            echo "   cd $PROJECT_ROOT"
            echo "   php -S localhost:$WEB_PORT"
        else
            echo "   Настройте $WEB_SERVER согласно инструкциям выше"
        fi
        echo ""
        echo "3. Доступ к системе:"
        echo "   API Health: $WEB_URL/api/replenishment.php?action=health"
        echo "   Дашборд: $WEB_URL/html/replenishment_dashboard.php"
        echo ""
        echo "4. Полный расчет:"
        echo "   php cron_replenishment_weekly.php"
        echo ""
        echo -e "${YELLOW}📚 Документация:${NC}"
        echo "   - Полная инструкция: deployment/replenishment/LOCAL_DEPLOYMENT_GUIDE.md"
        echo "   - Конфигурация: config_replenishment.php"
        echo "   - Логи: logs/replenishment/"
        echo ""
        
    else
        error "Развертывание завершилось с ошибками"
        echo ""
        echo "Проверьте логи и исправьте ошибки перед продолжением."
        exit 1
    fi
}

# ============================================================================
# ОБРАБОТКА ОШИБОК
# ============================================================================

cleanup() {
    if [ $? -ne 0 ]; then
        error "Развертывание прервано из-за ошибки"
        echo ""
        echo "Для диагностики проблем:"
        echo "1. Проверьте подключение к MySQL: mysql -u root -p"
        echo "2. Проверьте права доступа к файлам: ls -la $PROJECT_ROOT"
        echo "3. Проверьте логи PHP: tail -f /var/log/php*.log"
        echo ""
        echo "Для повторного запуска: $0"
    fi
}

trap cleanup EXIT

# Запуск основной функции
main "$@"