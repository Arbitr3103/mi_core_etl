# 🔧 Руководство по устранению неполадок ETL системы

## 🚨 Быстрая диагностика

### 1. Проверка SSH подключения
```bash
# Базовая доступность
ping -c 3 178.72.129.61

# SSH порт
nc -zv 178.72.129.61 22

# Подключение с диагностикой
ssh -v vladimir@178.72.129.61
```

### 2. Проверка состояния системы
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python test_inventory_system.py"
```

### 3. Проверка API подключений
```bash
# Wildberries
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python main.py --source wb --products-only"

# Ozon (основной)
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python main.py --source ozon --products-only"
```

## 🔍 Частые проблемы и решения

### Проблема: SSH зависает
**Симптомы:** Команды ssh не выполняются или зависают  
**Решение:**
1. Проверить интернет-соединение
2. Проверить доступность сервера: `ping 178.72.129.61`
3. Проверить SSH порт: `nc -zv 178.72.129.61 22`

### Проблема: Ошибки импорта модулей
**Симптомы:** `No module named 'ozon_importer'`  
**Решение:**
```bash
# Активировать виртуальное окружение
cd /home/vladimir/mi_core_etl
source venv/bin/activate

# Проверить структуру проекта
ls -la importers/
```

### Проблема: Ошибки API 404/400
**Симптомы:** `404 Client Error` или `400 Bad Request`  
**Решение:**
1. Проверить API ключи в .env файле
2. Проверить правильность эндпоинтов
3. Проверить сетевую доступность API

### Проблема: Ошибки базы данных
**Симптомы:** `Access denied` или `Unknown column`  
**Решение:**
```bash
# Проверить подключение к БД
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python -c 'from importers.ozon_importer import connect_to_db; conn = connect_to_db(); print(\"OK\")'"

# Проверить структуру таблиц
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python -c 'from importers.ozon_importer import connect_to_db; conn = connect_to_db(); cursor = conn.cursor(); cursor.execute(\"SHOW TABLES\"); print(cursor.fetchall())'"
```

## 📊 Мониторинг системы

### Проверка логов
```bash
# Последние ETL логи
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && tail -20 logs/etl_run_*.log"

# Логи импорта себестоимости
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && tail -20 logs/cost_import_*.log"

# Системные логи cron
ssh vladimir@178.72.129.61 "sudo tail -20 /var/log/cron"
```

### Проверка cron задач
```bash
# Список задач
ssh vladimir@178.72.129.61 "crontab -l"

# Статус cron службы
ssh vladimir@178.72.129.61 "sudo systemctl status cron"
```

### Проверка дискового пространства
```bash
ssh vladimir@178.72.129.61 "df -h"
```

## 🔄 Восстановление после сбоев

### Перезапуск ETL процесса
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python main.py --last-7-days"
```

### Ручная агрегация метрик
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python run_aggregation.py"
```

### Очистка lock файлов
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && rm -f *.lock"
```

## 📞 Контакты для экстренных случаев

### Критические проблемы
- Полная недоступность сервера
- Потеря данных
- Критические ошибки в продакшене

### Плановые работы
- Обновление API ключей
- Добавление новых источников данных
- Изменение расписания cron

## 🎯 Превентивные меры

### Ежедневные проверки
1. Проверить выполнение cron задач
2. Проверить размер логов
3. Проверить актуальность данных

### Еженедельные проверки  
1. Проверить состояние БД
2. Проверить свободное место на диске
3. Проверить работоспособность всех API

### Ежемесячные проверки
1. Обновить зависимости Python
2. Проверить безопасность системы
3. Создать резервную копию БД

## 🚀 Полезные команды

### Быстрый статус системы
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python -c 'from importers.ozon_importer import connect_to_db; conn = connect_to_db(); cursor = conn.cursor(); cursor.execute(\"SELECT COUNT(*) FROM fact_orders WHERE order_date = CURDATE()\"); print(f\"Заказов сегодня: {cursor.fetchone()[0]}\")'"
```

### Проверка последних данных
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python -c 'from importers.ozon_importer import connect_to_db; conn = connect_to_db(); cursor = conn.cursor(); cursor.execute(\"SELECT MAX(order_date) FROM fact_orders\"); print(f\"Последний заказ: {cursor.fetchone()[0]}\")'"
```

### Тест API подключений
```bash
ssh vladimir@178.72.129.61 "cd /home/vladimir/mi_core_etl && source venv/bin/activate && python -c 'import requests, config; print(\"WB API:\", requests.get(\"https://statistics-api.wildberries.ru\", timeout=5).status_code); print(\"Ozon API:\", requests.get(\"https://api-seller.ozon.ru\", timeout=5).status_code)'"
```
