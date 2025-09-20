# Руководство по развертыванию системы управления складом

**Дата:** 20 сентября 2025  
**Версия:** 1.0  
**Проект:** mi_core_etl - Система управления складом для Ozon и Wildberries  

---

## 🎯 Обзор системы

Система управления складом автоматически собирает и анализирует данные по остаткам и движениям товаров с маркетплейсов Ozon и Wildberries для:

- ✅ **Отслеживания остатков** товаров на всех складах
- ✅ **Анализа движений** товаров (продажи, возвраты, списания)
- ✅ **Расчета оборачиваемости** и планирования поставок
- ✅ **Автоматических отчетов** по складским операциям

---

## 📋 Предварительные требования

### Системные требования:
- **ОС:** Linux (Ubuntu/CentOS) или macOS
- **Python:** 3.8+
- **MySQL:** 5.7+ или 8.0+
- **Память:** минимум 2GB RAM
- **Диск:** минимум 10GB свободного места

### Необходимые API ключи:
- **Ozon Seller API:** Client-Id и Api-Key
- **Wildberries API:** Токен с правами на чтение данных

---

## 🚀 Пошаговое развертывание

### Шаг 1: Подготовка проекта

```bash
# Переходим в директорию проекта
cd /home/vladimir/mi_core_etl

# Активируем виртуальное окружение
source venv/bin/activate

# Проверяем структуру проекта
ls -la
```

### Шаг 2: Создание таблиц базы данных

```bash
# Запускаем миграцию для создания новых таблиц
mysql -u your_username -p mi_core_db < create_inventory_tables.sql

# Проверяем создание таблиц
mysql -u your_username -p -e "
USE mi_core_db;
SHOW TABLES LIKE '%inventory%';
SHOW TABLES LIKE '%stock_movements%';
"
```

### Шаг 3: Настройка конфигурации

```bash
# Редактируем конфигурационный файл
nano config.py

# Заполняем реальные API ключи Ozon
OZON_CLIENT_ID=your_ozon_client_id_here
OZON_API_KEY=your_ozon_api_key_here

# API ключ Wildberries (JWT токен)
WB_API_KEY=your_wb_api_key_here

# Добавляем config.py в .gitignore (если еще не добавлен)
echo "config.py" >> .gitignore
```

### Шаг 4: Тестирование системы

```bash
# Запускаем тестовый скрипт
python3 test_inventory_system.py

# Ожидаемый результат: все тесты должны пройти успешно
```

### Шаг 5: Настройка прав доступа для скриптов

```bash
# Делаем shell скрипты исполняемыми
chmod +x run_inventory_update.sh
chmod +x run_movements_update.sh

# Проверяем права
ls -la run_*.sh
```

### Шаг 6: Первый запуск импорта

```bash
# Тестируем импорт остатков
python3 importers/stock_importer.py

# Тестируем импорт движений за последние 24 часа
python3 importers/movement_importer.py --hours 24

# Проверяем результаты в базе данных
mysql -u your_username -p -e "
USE mi_core_db;
SELECT COUNT(*) as inventory_records FROM inventory;
SELECT COUNT(*) as movement_records FROM stock_movements;
"
```

---

## ⚙️ Настройка автоматизации (Cron)

### Добавление задач в crontab:

```bash
# Открываем crontab для редактирования
crontab -e

# Добавляем следующие строки:

# Обновление остатков каждый день в 03:00
0 3 * * * /home/vladimir/mi_core_etl/run_inventory_update.sh

# Обновление движений каждые 2 часа
0 */2 * * * /home/vladimir/mi_core_etl/run_movements_update.sh

# Сохраняем и выходим
```

### Проверка cron задач:

```bash
# Просматриваем текущие задачи
crontab -l

# Проверяем логи cron
tail -f /var/log/cron

# Проверяем логи нашей системы
tail -f logs/inventory_update_$(date +%Y-%m-%d).log
tail -f logs/movements_update_$(date +%Y-%m-%d).log
```

---

## 📊 Проверка работы системы

### Просмотр остатков товаров:

```sql
-- Остатки по источникам
SELECT 
    source,
    COUNT(*) as records,
    COUNT(DISTINCT product_id) as unique_products,
    SUM(quantity_present) as total_present,
    SUM(quantity_reserved) as total_reserved
FROM inventory 
GROUP BY source;

-- Топ-10 товаров по остаткам
SELECT 
    dp.sku_ozon,
    dp.product_name,
    i.warehouse_name,
    i.quantity_present,
    i.source
FROM inventory i
JOIN dim_products dp ON i.product_id = dp.id
ORDER BY i.quantity_present DESC
LIMIT 10;
```

### Анализ движений товаров:

```sql
-- Движения за последние 24 часа
SELECT 
    source,
    movement_type,
    COUNT(*) as operations,
    SUM(ABS(quantity)) as total_quantity
FROM stock_movements 
WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY source, movement_type;

-- Оборачиваемость товаров
SELECT 
    product_id,
    sku_ozon,
    product_name,
    total_sold_30d,
    current_stock,
    days_of_stock
FROM v_product_turnover_30d
WHERE days_of_stock IS NOT NULL
ORDER BY days_of_stock ASC
LIMIT 10;
```

---

## 🔧 Мониторинг и обслуживание

### Ежедневные проверки:

```bash
# Проверка логов на ошибки
grep -i "error\|failed\|критическая" logs/inventory_update_*.log
grep -i "error\|failed\|критическая" logs/movements_update_*.log

# Проверка размера логов
du -sh logs/

# Проверка свободного места
df -h

# Проверка процессов
ps aux | grep -E "(stock_importer|movement_importer)"
```

### Еженедельные задачи:

```bash
# Архивирование старых логов
find logs/ -name "*.log" -mtime +30 -exec gzip {} \;

# Проверка производительности БД
mysql -u your_username -p -e "
USE mi_core_db;
SELECT 
    table_name,
    table_rows,
    ROUND(data_length/1024/1024, 2) as data_mb,
    ROUND(index_length/1024/1024, 2) as index_mb
FROM information_schema.tables 
WHERE table_schema = 'mi_core_db' 
AND table_name IN ('inventory', 'stock_movements');"

# Оптимизация таблиц
mysql -u your_username -p -e "
USE mi_core_db;
OPTIMIZE TABLE inventory;
OPTIMIZE TABLE stock_movements;"
```

---

## 🚨 Устранение неполадок

### Частые проблемы и решения:

#### 1. Ошибки API подключения

```bash
# Проверка доступности API
curl -s "https://api-seller.ozon.ru" && echo "Ozon API доступен"
curl -s "https://suppliers-api.wildberries.ru" && echo "WB API доступен"

# Проверка API ключей
python3 -c "
import config
print('Ozon Client ID:', len(config.OZON_CLIENT_ID))
print('Ozon API Key:', len(config.OZON_API_KEY))
print('WB Token:', len(config.WB_API_TOKEN))
"
```

#### 2. Ошибки базы данных

```bash
# Проверка подключения к БД
mysql -u your_username -p -e "SELECT 1;"

# Проверка структуры таблиц
mysql -u your_username -p -e "
USE mi_core_db;
DESCRIBE inventory;
DESCRIBE stock_movements;
"

# Проверка индексов
mysql -u your_username -p -e "
USE mi_core_db;
SHOW INDEX FROM inventory;
SHOW INDEX FROM stock_movements;
"
```

#### 3. Проблемы с правами доступа

```bash
# Проверка прав на файлы
ls -la run_*.sh
ls -la importers/*.py

# Исправление прав
chmod +x run_*.sh
chmod 644 importers/*.py
chmod 644 config.py
```

#### 4. Проблемы с памятью

```bash
# Проверка использования памяти
free -h
top -p $(pgrep -f "stock_importer\|movement_importer")

# Проверка логов на ошибки памяти
dmesg | grep -i "killed\|memory"
```

---

## 📈 Расширение функционала

### Добавление новых типов движений:

1. Обновите маппинг в `movement_importer.py`:
```python
def _map_ozon_status_to_movement_type(self, status: str):
    status_mapping = {
        # ... существующие маппинги
        'new_status': 'new_movement_type'
    }
```

2. Обновите представления в БД для учета новых типов.

### Добавление уведомлений:

1. Настройте SMTP в `config.py`
2. Создайте модуль уведомлений
3. Интегрируйте с основными скриптами

### Добавление веб-интерфейса:

1. Используйте готовые PHP классы из системы маржинальности
2. Создайте API эндпоинты для складских данных
3. Разработайте дашборд для мониторинга

---

## 📞 Поддержка

### При возникновении проблем:

1. **Проверьте логи** в директории `logs/`
2. **Запустите тестовый скрипт** `test_inventory_system.py`
3. **Проверьте конфигурацию** в `config.py`
4. **Убедитесь в доступности API** маркетплейсов
5. **Проверьте состояние БД** и наличие свободного места

### Контакты технической поддержки:
- **Документация:** Этот файл и комментарии в коде
- **Логи системы:** `logs/` директория
- **Тестирование:** `test_inventory_system.py`

---

**Система управления складом готова к продуктивному использованию!** 🚀

*Последнее обновление: 20 сентября 2025 г.*
