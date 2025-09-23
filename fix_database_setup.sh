#!/bin/bash

# Скрипт для исправления проблемы с правами доступа MySQL
# и создания чистой схемы для системы пополнения склада

set -e  # Остановка при ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_info "🔧 Исправление настройки базы данных для системы пополнения склада"
echo "=" * 70

# Проверяем наличие файлов
if [ ! -f "create_replenishment_schema_clean.sql" ]; then
    print_error "Файл create_replenishment_schema_clean.sql не найден!"
    exit 1
fi

print_info "1. Проверка подключения к MySQL..."

# Проверяем подключение к MySQL
if ! mysql --version > /dev/null 2>&1; then
    print_error "MySQL не установлен или недоступен"
    exit 1
fi

print_success "MySQL доступен"

print_info "2. Создание базы данных и пользователя..."

# Запрашиваем пароль root для MySQL
echo -n "Введите пароль root для MySQL: "
read -s MYSQL_ROOT_PASSWORD
echo

# Генерируем случайный пароль для пользователя
REPLENISHMENT_PASSWORD=$(openssl rand -base64 12 | tr -d "=+/" | cut -c1-12)

print_info "Создание базы данных replenishment_db..."

# Создаем базу данных и пользователя
mysql -u root -p"$MYSQL_ROOT_PASSWORD" << EOF
-- Удаляем существующую базу если есть (осторожно!)
-- DROP DATABASE IF EXISTS replenishment_db;

-- Создаем базу данных
CREATE DATABASE IF NOT EXISTS replenishment_db 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

-- Удаляем пользователя если существует
DROP USER IF EXISTS 'replenishment_user'@'localhost';

-- Создаем нового пользователя
CREATE USER 'replenishment_user'@'localhost' 
    IDENTIFIED BY '$REPLENISHMENT_PASSWORD';

-- Даем полные права на базу replenishment_db
GRANT ALL PRIVILEGES ON replenishment_db.* 
    TO 'replenishment_user'@'localhost';

-- Применяем изменения
FLUSH PRIVILEGES;

-- Показываем созданную базу
SHOW DATABASES LIKE 'replenishment_db';
EOF

if [ $? -eq 0 ]; then
    print_success "База данных и пользователь созданы успешно"
else
    print_error "Ошибка создания базы данных"
    exit 1
fi

print_info "3. Применение схемы базы данных..."

# Применяем чистую схему
mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db < create_replenishment_schema_clean.sql

if [ $? -eq 0 ]; then
    print_success "Схема базы данных применена успешно"
else
    print_error "Ошибка применения схемы"
    exit 1
fi

print_info "4. Проверка созданных таблиц..."

# Проверяем созданные таблицы
mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db -e "
SELECT 
    TABLE_NAME as 'Таблица',
    TABLE_ROWS as 'Строк',
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as 'Размер (MB)'
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'replenishment_db'
ORDER BY TABLE_NAME;
"

print_info "5. Создание конфигурационного файла..."

# Создаем конфигурационный файл
mkdir -p importers

cat > importers/config.py << EOF
# Конфигурация базы данных для системы пополнения склада
# Автоматически сгенерировано $(date)

DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': '$REPLENISHMENT_PASSWORD',
    'database': 'replenishment_db',
    'charset': 'utf8mb4',
    'autocommit': True,
    'raise_on_warnings': True
}

# Настройки системы
SYSTEM_CONFIG = {
    'critical_stockout_threshold': 3,
    'high_priority_threshold': 7,
    'max_recommended_order_multiplier': 3.0,
    'enable_email_alerts': False,
    'alert_email_recipients': [],
    'max_analysis_products': 10000,
    'analysis_batch_size': 1000,
    'data_retention_days': 90
}

# Настройки логирования
LOGGING_CONFIG = {
    'level': 'INFO',
    'format': '%(asctime)s - %(levelname)s - %(message)s',
    'file': 'replenishment.log'
}
EOF

# Устанавливаем права доступа
chmod 600 importers/config.py

print_success "Конфигурационный файл создан: importers/config.py"

print_info "6. Тестирование подключения..."

# Тестируем подключение
python3 -c "
import sys
sys.path.append('importers')
try:
    from config import DB_CONFIG
    import mysql.connector
    
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    cursor.execute('SELECT COUNT(*) FROM replenishment_settings')
    result = cursor.fetchone()
    cursor.close()
    conn.close()
    
    print('✅ Подключение к базе данных успешно!')
    print(f'✅ Найдено {result[0]} настроек в системе')
    
except Exception as e:
    print(f'❌ Ошибка подключения: {e}')
    sys.exit(1)
"

if [ $? -eq 0 ]; then
    print_success "Тестирование подключения прошло успешно"
else
    print_error "Ошибка тестирования подключения"
    exit 1
fi

print_info "7. Добавление тестовых данных..."

# Добавляем несколько тестовых товаров
mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db << EOF
-- Добавляем тестовые товары
INSERT IGNORE INTO dim_products (sku, product_name, source, cost_price, selling_price, min_stock_level, is_active) VALUES
('TEST-001', 'Тестовый товар 1', 'test', 100.00, 150.00, 10, TRUE),
('TEST-002', 'Тестовый товар 2', 'test', 200.00, 280.00, 15, TRUE),
('TEST-003', 'Тестовый товар 3', 'test', 50.00, 75.00, 20, TRUE);

-- Добавляем тестовые остатки
INSERT IGNORE INTO inventory_data (product_id, sku, source, snapshot_date, current_stock, available_stock) 
SELECT product_id, sku, source, CURDATE(), 5, 5 FROM dim_products WHERE sku LIKE 'TEST-%';

-- Добавляем тестовые продажи
INSERT IGNORE INTO sales_data (product_id, sku, source, sale_date, quantity_sold, sale_price) 
SELECT 
    product_id, 
    sku, 
    source, 
    DATE_SUB(CURDATE(), INTERVAL FLOOR(RAND() * 30) DAY),
    FLOOR(RAND() * 5) + 1,
    selling_price
FROM dim_products 
WHERE sku LIKE 'TEST-%';

SELECT 'Тестовые данные добавлены' as status;
EOF

print_success "Тестовые данные добавлены"

print_info "8. Финальная проверка системы..."

# Запускаем быстрый тест системы
python3 -c "
import sys
sys.path.append('.')
try:
    from inventory_analyzer import InventoryAnalyzer
    
    analyzer = InventoryAnalyzer()
    items = analyzer.get_current_stock()
    print(f'✅ Найдено {len(items)} товаров в системе')
    
    if len(items) > 0:
        print(f'✅ Пример товара: {items[0].sku} - {items[0].current_stock} шт')
    
    analyzer.close()
    
except Exception as e:
    print(f'❌ Ошибка тестирования системы: {e}')
    sys.exit(1)
"

print_success "🎉 НАСТРОЙКА БАЗЫ ДАННЫХ ЗАВЕРШЕНА УСПЕШНО!"
echo
print_info "📋 ИНФОРМАЦИЯ О ПОДКЛЮЧЕНИИ:"
echo "  База данных: replenishment_db"
echo "  Пользователь: replenishment_user"
echo "  Пароль: $REPLENISHMENT_PASSWORD"
echo "  Конфигурация: importers/config.py"
echo
print_info "🚀 СЛЕДУЮЩИЕ ШАГИ:"
echo "  1. Запустите API сервер: python3 simple_api_server.py"
echo "  2. Проверьте здоровье: curl http://localhost:8000/api/health"
echo "  3. Запустите анализ: python3 replenishment_orchestrator.py --mode quick"
echo "  4. Проверьте маржинальность: python3 margin_analyzer.py"
echo
print_warning "⚠️  ВАЖНО: Сохраните пароль базы данных в безопасном месте!"
echo "Пароль: $REPLENISHMENT_PASSWORD"