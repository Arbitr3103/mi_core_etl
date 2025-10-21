#!/bin/bash
# Скрипт резервного копирования перед миграцией к реальным данным

BACKUP_DIR="/var/www/html/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "🔄 Создание резервных копий перед миграцией..."

# Создаем директорию для резервных копий
mkdir -p $BACKUP_DIR

# Резервная копия базы данных
echo "📊 Создание резервной копии базы данных..."
mysqldump -u v_admin -p'Arbitr09102022!' mi_core > $BACKUP_DIR/mi_core_backup_$TIMESTAMP.sql

if [ $? -eq 0 ]; then
    echo "✅ Резервная копия БД создана: mi_core_backup_$TIMESTAMP.sql"
else
    echo "❌ Ошибка создания резервной копии БД"
    exit 1
fi

# Резервная копия важных файлов
echo "📁 Создание резервной копии файлов..."
tar -czf $BACKUP_DIR/website_backup_$TIMESTAMP.tar.gz \
    /var/www/html/index.html \
    /var/www/html/api/ \
    /var/www/html/replenishment_adapter_fixed.php \
    /var/www/html/config.php \
    /var/www/html/.env

if [ $? -eq 0 ]; then
    echo "✅ Резервная копия файлов создана: website_backup_$TIMESTAMP.tar.gz"
else
    echo "❌ Ошибка создания резервной копии файлов"
    exit 1
fi

# Проверяем размер резервных копий
DB_SIZE=$(du -h $BACKUP_DIR/mi_core_backup_$TIMESTAMP.sql | cut -f1)
FILES_SIZE=$(du -h $BACKUP_DIR/website_backup_$TIMESTAMP.tar.gz | cut -f1)

echo ""
echo "📋 Резервные копии созданы:"
echo "   📊 База данных: $DB_SIZE ($BACKUP_DIR/mi_core_backup_$TIMESTAMP.sql)"
echo "   📁 Файлы: $FILES_SIZE ($BACKUP_DIR/website_backup_$TIMESTAMP.tar.gz)"
echo ""
echo "✅ Резервное копирование завершено успешно!"
echo "🔄 Теперь можно безопасно переходить к миграции реальных данных"