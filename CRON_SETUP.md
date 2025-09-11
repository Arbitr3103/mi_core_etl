# 🕐 НАСТРОЙКА АВТОМАТИЧЕСКОГО ЗАПУСКА ETL

## 📋 Инструкция по настройке cron job на сервере

### 1. Подготовка скрипта на сервере

```bash
# Скопируйте файлы на сервер
git pull origin main

# Сделайте скрипт исполняемым
chmod +x run_weekly_etl.sh

# Отредактируйте путь в скрипте
nano run_weekly_etl.sh
# Замените: PROJECT_DIR="/path/to/mi_core_etl"
# На реальный путь, например: PROJECT_DIR="/home/user/mi_core_etl"
```

### 2. Настройка cron job

```bash
# Откройте crontab для редактирования
crontab -e

# Добавьте строку для запуска каждый понедельник в 3:00
0 3 * * 1 /home/user/mi_core_etl/run_weekly_etl.sh

# Сохраните и выйдите (Ctrl+X, затем Y, затем Enter)
```

### 3. Проверка настройки

```bash
# Посмотрите текущие cron задачи
crontab -l

# Проверьте статус cron службы
sudo systemctl status cron
```

### 4. Тестирование

```bash
# Запустите скрипт вручную для проверки
./run_weekly_etl.sh

# Проверьте логи
ls -la logs/
tail -f logs/weekly_etl_*.log
```

## 🚀 Альтернативные команды для запуска

### Ручной запуск за последние 7 дней:
```bash
python3 main.py --last-7-days
```

### Запуск за конкретную дату:
```bash
python3 main.py --start-date 2025-09-03 --end-date 2025-09-03
```

### Только заказы за последние 7 дней:
```bash
python3 main.py --last-7-days --orders-only
```

## 📊 Мониторинг

- Логи сохраняются в папке `logs/`
- Каждый запуск создает отдельный лог файл с временной меткой
- В логах отображается статистика загруженных данных
- При ошибках можно настроить уведомления через webhook

---

# 🚗 НАСТРОЙКА АВТОМАТИЧЕСКОГО ОБНОВЛЕНИЯ АВТОМОБИЛЬНЫХ ДАННЫХ

## 📋 Инструкция по настройке ежедневного обновления BaseBuy

### 1. Подготовка скрипта на сервере

```bash
# Скопируйте новые файлы на сервер
git pull origin main

# Сделайте скрипт исполняемым
chmod +x run_daily_car_update.sh

# Установите зависимости
pip3 install -r requirements.txt

# Отредактируйте путь в скрипте (если нужно)
nano run_daily_car_update.sh
# Проверьте: PROJECT_DIR="$SCRIPT_DIR" (обычно не требует изменений)
```

### 2. Настройка cron job для автомобильных данных

```bash
# Откройте crontab для редактирования
crontab -e

# Добавьте строку для ежедневного обновления в 4:00
0 4 * * * /home/user/mi_core_etl/run_daily_car_update.sh

# Сохраните и выйдите (Ctrl+X, затем Y, затем Enter)
```

### 3. Тестирование автомобильного модуля

```bash
# Запустите скрипт вручную для проверки
./run_daily_car_update.sh

# Или напрямую Python модуль
python3 importers/car_data_updater.py

# Проверьте логи
ls -la logs/car_update_*.log
tail -f logs/car_update_*.log
```

### 4. Проверка API BaseBuy

```bash
# Убедитесь, что API ключ настроен в .env
grep BASEBUY_API_KEY .env

# Проверьте подключение к API
python3 -c "
from importers.car_data_updater import CarDataUpdater
updater = CarDataUpdater()
version = updater.get_latest_version_from_api()
print(f'Текущая версия API: {version}')
"
```

## ⏰ Полное расписание cron

```bash
# Формат: минута час день_месяца месяц день_недели команда

# Еженедельное обновление Ozon (понедельник в 3:00)
0 3 * * 1 /home/user/mi_core_etl/run_weekly_etl.sh

# Ежедневное обновление автомобильных данных (каждый день в 4:00)
0 4 * * * /home/user/mi_core_etl/run_daily_car_update.sh
```

## 📊 Мониторинг обновлений

### Логи Ozon ETL:
- `logs/weekly_etl_*.log` - еженедельные обновления заказов
- Статистика загруженных заказов и продуктов

### Логи автомобильных данных:
- `logs/car_update_*.log` - ежедневные проверки версий BaseBuy
- Информация о найденных обновлениях и их применении
- Автоматическая очистка логов старше 30 дней

## 🔧 Дополнительные команды

### Проверка версий:
```bash
# Текущая версия в БД
python3 -c "
from importers.car_data_updater import CarDataUpdater
updater = CarDataUpdater()
print(f'БД: {updater.get_current_db_version()}')
print(f'API: {updater.get_latest_version_from_api()}')
"
```

### Ручное обновление:
```bash
# Принудительное обновление версии в БД
python3 -c "
from importers.car_data_updater import CarDataUpdater
updater = CarDataUpdater()
updater.set_db_version('01.01.2025')
print('Версия обновлена')
"
```
