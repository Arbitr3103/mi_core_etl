#!/bin/bash

# Скрипт для применения оптимизаций фильтра по странам
# Выполняется в рамках задачи 6: Оптимизация производительности и кэширование

echo "=== Применение оптимизаций фильтра по странам ==="
echo

# Проверяем наличие файла с индексами
if [ ! -f "create_country_filter_indexes.sql" ]; then
    echo "❌ Файл create_country_filter_indexes.sql не найден!"
    exit 1
fi

# Загружаем переменные окружения
if [ -f ".env" ]; then
    source .env
    echo "✅ Загружены переменные окружения из .env"
else
    echo "⚠️ Файл .env не найден, используем значения по умолчанию"
    DB_HOST=${DB_HOST:-localhost}
    DB_NAME=${DB_NAME:-mi_core_db}
    DB_USER=${DB_USER:-your_username}
    DB_PASSWORD=${DB_PASSWORD:-your_password}
fi

echo "Подключение к базе данных: $DB_HOST/$DB_NAME"
echo

# Функция для выполнения SQL запроса
execute_sql() {
    local sql_file=$1
    local description=$2
    
    echo "Выполняется: $description"
    
    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$sql_file"; then
        echo "✅ $description - выполнено успешно"
    else
        echo "❌ $description - ошибка выполнения"
        return 1
    fi
    echo
}

# Создаем резервную копию структуры БД
echo "Создание резервной копии структуры БД..."
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --no-data "$DB_NAME" > "backup_structure_$(date +%Y%m%d_%H%M%S).sql"
if [ $? -eq 0 ]; then
    echo "✅ Резервная копия создана"
else
    echo "⚠️ Не удалось создать резервную копию"
fi
echo

# Применяем индексы
execute_sql "create_country_filter_indexes.sql" "Создание индексов для оптимизации"

# Проверяем созданные индексы
echo "Проверка созданных индексов..."
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as COLUMNS,
    INDEX_TYPE
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = '$DB_NAME' 
  AND TABLE_NAME IN ('brands', 'car_models', 'car_specifications', 'dim_products', 'regions')
  AND INDEX_NAME LIKE 'idx_%'
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE
ORDER BY TABLE_NAME, INDEX_NAME;
"

if [ $? -eq 0 ]; then
    echo "✅ Индексы проверены"
else
    echo "❌ Ошибка проверки индексов"
fi
echo

# Анализируем таблицы для обновления статистики
echo "Анализ таблиц для обновления статистики..."
tables=("brands" "car_models" "car_specifications" "dim_products" "regions")

for table in "${tables[@]}"; do
    echo "Анализ таблицы: $table"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "ANALYZE TABLE $table;"
done

echo "✅ Анализ таблиц завершен"
echo

# Запускаем тест производительности
if [ -f "test_country_filter_performance.php" ]; then
    echo "Запуск теста производительности..."
    php test_country_filter_performance.php
else
    echo "⚠️ Файл test_country_filter_performance.php не найден"
fi

echo
echo "=== ОПТИМИЗАЦИЯ ЗАВЕРШЕНА ==="
echo
echo "Рекомендации:"
echo "1. Мониторьте производительность запросов в продакшене"
echo "2. Рассмотрите настройку MySQL параметров:"
echo "   - innodb_buffer_pool_size"
echo "   - query_cache_size"
echo "   - key_buffer_size"
echo "3. Используйте EXPLAIN для анализа планов выполнения запросов"
echo "4. Рассмотрите возможность использования Redis для кэширования"
echo