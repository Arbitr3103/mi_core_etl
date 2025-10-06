#!/bin/bash

# ===================================================================
# ТЕСТ СИНТАКСИСА ФАЙЛОВ МИГРАЦИИ
# ===================================================================

set -e

echo "Проверка синтаксиса SQL файлов миграции..."

# Функция для проверки SQL синтаксиса
check_sql_syntax() {
    local file=$1
    echo "Проверка файла: $file"
    
    if [ ! -f "$file" ]; then
        echo "❌ Файл не найден: $file"
        return 1
    fi
    
    # Базовая проверка SQL синтаксиса (поиск основных ошибок)
    if grep -q "CREATE TABLE\|ALTER TABLE\|DROP TABLE\|INSERT\|UPDATE\|DELETE\|SELECT" "$file"; then
        echo "✅ Файл содержит SQL команды"
    else
        echo "⚠️  Файл не содержит SQL команд"
    fi
    
    # Проверка на незакрытые скобки
    local open_parens=$(grep -o "(" "$file" | wc -l)
    local close_parens=$(grep -o ")" "$file" | wc -l)
    
    if [ "$open_parens" -eq "$close_parens" ]; then
        echo "✅ Скобки сбалансированы ($open_parens открывающих, $close_parens закрывающих)"
    else
        echo "❌ Несбалансированные скобки ($open_parens открывающих, $close_parens закрывающих)"
        return 1
    fi
    
    # Проверка на наличие точек с запятой в конце команд
    if grep -q ";" "$file"; then
        echo "✅ Найдены точки с запятой"
    else
        echo "⚠️  Не найдены точки с запятой"
    fi
    
    echo "✅ Базовая проверка синтаксиса пройдена"
    echo ""
}

# Проверка всех файлов миграции
echo "====================================================================="
echo "                    ПРОВЕРКА СИНТАКСИСА МИГРАЦИИ"
echo "====================================================================="

check_sql_syntax "migrations/inventory_sync_schema_update.sql"
check_sql_syntax "migrations/rollback_inventory_sync_schema_update.sql"
check_sql_syntax "migrations/validate_inventory_sync_migration.sql"

echo "====================================================================="
echo "Проверка синтаксиса завершена!"
echo "====================================================================="