# 🔄 План перехода к реальным данным

**Дата:** 20 октября 2025  
**Статус:** 📋 ПЛАН ГОТОВ  
**Цель:** Безопасно заменить тестовые данные реальными данными из маркетплейсов

## 🎯 Текущее состояние

### База данных:

- ✅ Дашборд работает с тестовыми данными
- ✅ API возвращает корректные данные
- ✅ 5 тестовых записей в `inventory_data`
- ❌ 0 записей в `dim_products` (нет реальных товаров)
- ❌ 0 записей в `fact_orders` (нет реальных заказов)

### Тестовые данные:

```sql
TEST001 - Основной склад - 15 шт
TEST002 - Основной склад - 3 шт (критический)
TEST003 - Основной склад - 150 шт (избыток)
TEST004 - Дополнительный склад - 8 шт
TEST005 - Дополнительный склад - 0 шт (критический)
```

## 📋 Пошаговый план миграции

### Этап 1: Подготовка и резервное копирование (5 мин)

1. **Создать полную резервную копию БД**

   ```bash
   mysqldump -u v_admin -p mi_core > backup_before_real_data_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Создать резервную копию тестовых данных**

   ```sql
   CREATE TABLE inventory_data_test_backup AS SELECT * FROM inventory_data;
   ```

3. **Проверить работоспособность дашборда**
   - Убедиться, что все API работают
   - Сделать скриншот текущего состояния

### Этап 2: Настройка API ключей (10 мин)

1. **Проверить наличие API ключей**

   ```bash
   # Проверить .env файл на сервере
   grep -E "(OZON_|WB_)" /var/www/html/.env
   ```

2. **Настроить подключения к маркетплейсам**

   - Ozon API (client_id, api_key)
   - Wildberries API (api_key)

3. **Протестировать подключения**
   ```python
   python test_ozon_api.py
   python test_wb_api.py
   ```

### Этап 3: Загрузка справочника товаров (15 мин)

1. **Загрузить товары из Ozon**

   ```python
   python importers/ozon_importer.py --action=products
   ```

2. **Загрузить товары из WB**

   ```python
   python importers/wb_importer.py --action=products
   ```

3. **Проверить загруженные данные**
   ```sql
   SELECT COUNT(*) FROM dim_products;
   SELECT sku_ozon, sku_wb, product_name FROM dim_products LIMIT 10;
   ```

### Этап 4: Загрузка остатков (10 мин)

1. **Очистить тестовые данные**

   ```sql
   DELETE FROM inventory_data WHERE sku LIKE 'TEST%';
   ```

2. **Запустить синхронизацию остатков**

   ```python
   python inventory_sync_service.py --full-sync
   ```

3. **Проверить загруженные остатки**
   ```sql
   SELECT COUNT(*) FROM inventory_data;
   SELECT sku, warehouse_name, current_stock FROM inventory_data LIMIT 10;
   ```

### Этап 5: Проверка дашборда (5 мин)

1. **Проверить API**

   ```bash
   curl "https://www.market-mi.ru/api/inventory-analytics.php?action=dashboard"
   ```

2. **Проверить веб-интерфейс**

   - Открыть https://www.market-mi.ru
   - Нажать "Загрузить дашборд"
   - Проверить все кнопки

3. **Проверить рекомендации**
   ```bash
   curl "https://www.market-mi.ru/replenishment_adapter_fixed.php?action=recommendations"
   ```

### Этап 6: Настройка автоматической синхронизации (10 мин)

1. **Настроить cron для ежедневной синхронизации**

   ```bash
   # Добавить в crontab
   0 6 * * * cd /var/www/html && python inventory_sync_service.py >> logs/sync.log 2>&1
   ```

2. **Настроить еженедельное обновление**
   ```bash
   0 2 * * 1 cd /var/www/html && python ozon_weekly_update.py >> logs/weekly.log 2>&1
   ```

## 🔧 Скрипты для выполнения

### 1. Скрипт резервного копирования

```bash
#!/bin/bash
# backup_before_migration.sh
BACKUP_DIR="/var/www/html/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR

# Резервная копия БД
mysqldump -u v_admin -p'Arbitr09102022!' mi_core > $BACKUP_DIR/mi_core_backup_$TIMESTAMP.sql

# Резервная копия файлов
tar -czf $BACKUP_DIR/website_backup_$TIMESTAMP.tar.gz /var/www/html/*.html /var/www/html/api/ /var/www/html/*.php

echo "✅ Резервные копии созданы в $BACKUP_DIR"
```

### 2. Скрипт проверки API ключей

```python
#!/usr/bin/env python3
# check_api_keys.py
import os
import requests

def check_ozon_api():
    client_id = os.getenv('OZON_CLIENT_ID')
    api_key = os.getenv('OZON_API_KEY')

    if not client_id or not api_key:
        return False, "API ключи не найдены"

    # Тестовый запрос к Ozon API
    headers = {
        'Client-Id': client_id,
        'Api-Key': api_key,
        'Content-Type': 'application/json'
    }

    try:
        response = requests.post(
            'https://api-seller.ozon.ru/v1/product/list',
            headers=headers,
            json={'limit': 1}
        )
        return response.status_code == 200, f"Status: {response.status_code}"
    except Exception as e:
        return False, str(e)

if __name__ == "__main__":
    success, message = check_ozon_api()
    print(f"Ozon API: {'✅' if success else '❌'} {message}")
```

### 3. Скрипт безопасной миграции

```python
#!/usr/bin/env python3
# safe_migration.py
import mysql.connector
import sys
from datetime import datetime

def safe_migrate_to_real_data():
    try:
        # Подключение к БД
        conn = mysql.connector.connect(
            host='localhost',
            user='v_admin',
            password='Arbitr09102022!',
            database='mi_core'
        )
        cursor = conn.cursor()

        print("🔄 Начинаем безопасную миграцию...")

        # 1. Создаем резервную копию тестовых данных
        cursor.execute("CREATE TABLE IF NOT EXISTS inventory_data_test_backup AS SELECT * FROM inventory_data WHERE sku LIKE 'TEST%'")
        print("✅ Резервная копия тестовых данных создана")

        # 2. Проверяем наличие реальных данных
        cursor.execute("SELECT COUNT(*) FROM dim_products")
        products_count = cursor.fetchone()[0]

        if products_count == 0:
            print("⚠️  Нет данных в dim_products. Сначала загрузите товары из маркетплейсов.")
            return False

        # 3. Удаляем тестовые данные
        cursor.execute("DELETE FROM inventory_data WHERE sku LIKE 'TEST%'")
        deleted_count = cursor.rowcount
        print(f"🗑️  Удалено {deleted_count} тестовых записей")

        # 4. Проверяем наличие реальных остатков
        cursor.execute("SELECT COUNT(*) FROM inventory_data")
        real_count = cursor.fetchone()[0]

        if real_count == 0:
            print("⚠️  Нет реальных данных об остатках. Запустите синхронизацию.")
            # Восстанавливаем тестовые данные
            cursor.execute("INSERT INTO inventory_data SELECT * FROM inventory_data_test_backup")
            print("🔄 Тестовые данные восстановлены")
            return False

        conn.commit()
        print(f"✅ Миграция завершена успешно. Реальных записей: {real_count}")
        return True

    except Exception as e:
        print(f"❌ Ошибка миграции: {e}")
        if conn:
            conn.rollback()
        return False
    finally:
        if conn:
            conn.close()

if __name__ == "__main__":
    success = safe_migrate_to_real_data()
    sys.exit(0 if success else 1)
```

## ⚠️ Критические моменты

### Что может пойти не так:

1. **Отсутствие API ключей** - дашборд останется пустым
2. **Ошибки синхронизации** - данные могут быть неполными
3. **Проблемы с подключением** - API маркетплейсов недоступны
4. **Неправильные SKU** - товары не будут связаны с остатками

### План отката:

1. **Быстрый откат** - восстановить тестовые данные из резервной копии
2. **Полный откат** - восстановить всю БД из дампа
3. **Частичный откат** - оставить реальные товары, вернуть тестовые остатки

## 🎯 Ожидаемый результат

После успешной миграции:

- ✅ Дашборд показывает реальные остатки товаров
- ✅ Рекомендации основаны на реальных данных
- ✅ Автоматическая синхронизация настроена
- ✅ Мониторинг работает корректно

## 📞 Контакты для поддержки

В случае проблем:

1. Проверить логи: `/var/www/html/logs/`
2. Проверить статус API: `curl https://www.market-mi.ru/api/inventory-analytics.php?action=dashboard`
3. Восстановить из резервной копии при необходимости

---

_План создан автоматически системой Kiro IDE_
