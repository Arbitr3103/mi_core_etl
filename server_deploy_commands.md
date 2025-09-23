# 🚀 Команды для развертывания на сервере

## 1. Подключение к серверу и подготовка

```bash
# Подключение к серверу
ssh your-user@your-server

# Переход в рабочую директорию
cd /var/www/html

# Клонирование репозитория
git clone https://github.com/Arbitr3103/mi_core_etl.git
cd mi_core_etl

# Проверка файлов
ls -la
```

## 2. Установка зависимостей

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Установка Python и зависимостей
sudo apt install python3 python3-pip python3-venv mysql-server -y

# Создание виртуального окружения
python3 -m venv venv
source venv/bin/activate

# Установка Python пакетов
pip install mysql-connector-python schedule psutil

# Проверка установки
python3 -c "import mysql.connector; print('MySQL connector OK')"
```

## 3. Настройка базы данных

```bash
# Запуск MySQL
sudo systemctl start mysql
sudo systemctl enable mysql

# Создание базы данных
sudo mysql -u root -p
```

```sql
-- В MySQL консоли:
CREATE DATABASE replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

```bash
# Применение схемы базы данных
mysql -u replenishment_user -p replenishment_db < create_replenishment_schema.sql
```

## 4. Настройка конфигурации

```bash
# Создание конфигурационного файла
cat > importers/config.py << 'EOF'
# Конфигурация базы данных
DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': 'strong_password_here',
    'database': 'replenishment_db',
    'charset': 'utf8mb4',
    'autocommit': True
}

# Настройки системы
SYSTEM_CONFIG = {
    'critical_stockout_threshold': 3,
    'high_priority_threshold': 7,
    'max_recommended_order_multiplier': 3.0,
    'enable_email_alerts': False,
    'alert_email_recipients': []
}
EOF

# Установка прав доступа
chmod 600 importers/config.py
```

## 5. Тестирование компонентов

```bash
# Тест подключения к БД
python3 -c "
from importers.ozon_importer import connect_to_db
try:
    conn = connect_to_db()
    print('✅ Подключение к БД успешно')
    conn.close()
except Exception as e:
    print(f'❌ Ошибка БД: {e}')
"

# Тест анализатора запасов
python3 -c "
from inventory_analyzer import InventoryAnalyzer
try:
    analyzer = InventoryAnalyzer()
    print('✅ Анализатор запасов инициализирован')
    analyzer.close()
except Exception as e:
    print(f'❌ Ошибка анализатора: {e}')
"

# Тест API сервера
python3 simple_api_server.py &
sleep 3
curl -s http://localhost:8000/api/health | head -5
pkill -f simple_api_server.py
```

## 6. Запуск системы

```bash
# Запуск API сервера в фоне
nohup python3 simple_api_server.py > api_server.log 2>&1 &

# Проверка запуска
ps aux | grep simple_api_server
curl http://localhost:8000/api/health

# Запуск планировщика в фоне
nohup python3 schedule_replenishment.py --mode schedule > scheduler.log 2>&1 &

# Проверка логов
tail -f api_server.log
tail -f scheduler.log
```

## 7. Настройка Nginx (опционально)

```bash
# Установка Nginx
sudo apt install nginx -y

# Создание конфигурации
sudo tee /etc/nginx/sites-available/replenishment << 'EOF'
server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF

# Активация сайта
sudo ln -s /etc/nginx/sites-available/replenishment /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## 8. Мониторинг и логи

```bash
# Просмотр логов
tail -f api_server.log
tail -f scheduler.log
tail -f replenishment_orchestrator.log

# Проверка процессов
ps aux | grep python3

# Проверка портов
netstat -tlnp | grep :8000

# Мониторинг ресурсов
htop
```

## 9. Тестирование на реальных данных

```bash
# Быстрая проверка
python3 replenishment_orchestrator.py --mode quick --no-save --no-alerts

# Полный анализ (осторожно - может занять время!)
python3 replenishment_orchestrator.py --mode full

# Экспорт результатов
python3 replenishment_orchestrator.py --mode export --export-file test_results.csv --export-format csv
```

## 10. Автозапуск при перезагрузке

```bash
# Создание systemd сервиса для API
sudo tee /etc/systemd/system/replenishment-api.service << 'EOF'
[Unit]
Description=Replenishment API Server
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/mi_core_etl
Environment=PATH=/var/www/html/mi_core_etl/venv/bin
ExecStart=/var/www/html/mi_core_etl/venv/bin/python simple_api_server.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Создание systemd сервиса для планировщика
sudo tee /etc/systemd/system/replenishment-scheduler.service << 'EOF'
[Unit]
Description=Replenishment Scheduler
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/mi_core_etl
Environment=PATH=/var/www/html/mi_core_etl/venv/bin
ExecStart=/var/www/html/mi_core_etl/venv/bin/python schedule_replenishment.py --mode schedule
Restart=always
RestartSec=30

[Install]
WantedBy=multi-user.target
EOF

# Активация сервисов
sudo systemctl daemon-reload
sudo systemctl enable replenishment-api
sudo systemctl enable replenishment-scheduler
sudo systemctl start replenishment-api
sudo systemctl start replenishment-scheduler

# Проверка статуса
sudo systemctl status replenishment-api
sudo systemctl status replenishment-scheduler
```

## 11. Проверка работоспособности

```bash
# Проверка API
curl http://localhost:8000/api/health
curl http://localhost:8000/api/recommendations?limit=5
curl http://localhost:8000/api/alerts

# Проверка веб-интерфейса
curl http://localhost:8000/ | head -20

# Запуск анализа через API
curl -X POST http://localhost:8000/api/analysis/run

# Проверка базы данных
mysql -u replenishment_user -p replenishment_db -e "
SELECT COUNT(*) as total_recommendations FROM replenishment_recommendations;
SELECT COUNT(*) as total_alerts FROM replenishment_alerts;
SELECT COUNT(*) as total_products FROM dim_products WHERE is_active = 1;
"
```

## 12. Устранение неполадок

```bash
# Если API не запускается
sudo systemctl status replenishment-api
sudo journalctl -u replenishment-api -f

# Если нет подключения к БД
mysql -u replenishment_user -p replenishment_db -e "SELECT 1;"

# Если нет данных
python3 -c "
from inventory_analyzer import InventoryAnalyzer
analyzer = InventoryAnalyzer()
items = analyzer.get_current_stock()
print(f'Товаров в системе: {len(items)}')
analyzer.close()
"

# Проверка логов ошибок
tail -100 api_server.log | grep ERROR
tail -100 scheduler.log | grep ERROR
```

## 13. Бэкап и восстановление

```bash
# Создание бэкапа БД
mysqldump -u replenishment_user -p replenishment_db > backup_$(date +%Y%m%d_%H%M%S).sql

# Бэкап конфигурации
tar -czf config_backup_$(date +%Y%m%d_%H%M%S).tar.gz importers/config.py *.log

# Восстановление из бэкапа
mysql -u replenishment_user -p replenishment_db < backup_file.sql
```
