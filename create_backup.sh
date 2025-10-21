#!/bin/bash
# Создание резервной копии перед развертыванием
# Безопасный скрипт для создания бэкапа

set -e

echo "💾 Создание резервной копии перед развертыванием..."

# Определяем директории
BACKUP_DIR="/tmp/regional_analytics_backup_$(date +%Y%m%d_%H%M%S)"
PROJECT_ROOT="$(pwd)"

# Создаем директорию для бэкапа
mkdir -p "$BACKUP_DIR"

echo "📁 Директория бэкапа: $BACKUP_DIR"

# Функция для безопасного копирования
safe_copy() {
    local source="$1"
    local dest="$2"
    local name="$3"
    
    if [ -e "$source" ]; then
        cp -r "$source" "$dest" 2>/dev/null || echo "⚠️  Не удалось скопировать $name"
        echo "✅ $name скопирован"
    else
        echo "ℹ️  $name не найден (пропускаем)"
    fi
}

# Создаем бэкап текущих файлов проекта
echo "📦 Создание бэкапа файлов проекта..."
safe_copy "api" "$BACKUP_DIR/" "API файлы"
safe_copy "html" "$BACKUP_DIR/" "HTML файлы"
safe_copy "migrations" "$BACKUP_DIR/" "Миграции"
safe_copy "dashboard_index.php" "$BACKUP_DIR/" "Главный дашборд"
safe_copy "config.php" "$BACKUP_DIR/" "Конфигурация"

# Создаем информационный файл
cat > "$BACKUP_DIR/backup_info.txt" << EOF
Резервная копия Regional Analytics
Дата создания: $(date)
Исходная директория: $PROJECT_ROOT
Пользователь: $(whoami)
Система: $(uname -a)

Содержимое бэкапа:
$(ls -la "$BACKUP_DIR")
EOF

# Создаем скрипт восстановления
cat > "$BACKUP_DIR/restore.sh" << 'EOF'
#!/bin/bash
# Скрипт восстановления из резервной копии

echo "🔄 Восстановление из резервной копии..."

BACKUP_DIR="$(dirname "$0")"
TARGET_DIR="${1:-$(pwd)}"

echo "Восстанавливаем в: $TARGET_DIR"

# Восстанавливаем файлы
if [ -d "$BACKUP_DIR/api" ]; then
    cp -r "$BACKUP_DIR/api" "$TARGET_DIR/"
    echo "✅ API файлы восстановлены"
fi

if [ -d "$BACKUP_DIR/html" ]; then
    cp -r "$BACKUP_DIR/html" "$TARGET_DIR/"
    echo "✅ HTML файлы восстановлены"
fi

if [ -d "$BACKUP_DIR/migrations" ]; then
    cp -r "$BACKUP_DIR/migrations" "$TARGET_DIR/"
    echo "✅ Миграции восстановлены"
fi

if [ -f "$BACKUP_DIR/dashboard_index.php" ]; then
    cp "$BACKUP_DIR/dashboard_index.php" "$TARGET_DIR/"
    echo "✅ Главный дашборд восстановлен"
fi

if [ -f "$BACKUP_DIR/config.php" ]; then
    cp "$BACKUP_DIR/config.php" "$TARGET_DIR/"
    echo "✅ Конфигурация восстановлена"
fi

echo "🎉 Восстановление завершено!"
EOF

chmod +x "$BACKUP_DIR/restore.sh"

# Создаем архив для удобства
echo "🗜️  Создание архива..."
tar -czf "${BACKUP_DIR}.tar.gz" -C "$(dirname "$BACKUP_DIR")" "$(basename "$BACKUP_DIR")"

echo ""
echo "✅ Резервная копия создана успешно!"
echo "📁 Директория: $BACKUP_DIR"
echo "📦 Архив: ${BACKUP_DIR}.tar.gz"
echo "🔄 Восстановление: bash $BACKUP_DIR/restore.sh"
echo ""
echo "💡 Для восстановления выполните:"
echo "   cd /path/to/project"
echo "   bash $BACKUP_DIR/restore.sh"
echo ""
echo "🎯 Теперь можно безопасно запускать развертывание!"