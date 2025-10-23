# Production Deployment Tasks - MI Core ETL

**Server**: 178.72.129.61  
**User**: vladimir  
**Date**: October 22, 2025

---

## Этап 1: Разведка и аудит сервера

### Задача 1.1: Подключение и изучение структуры проекта

-   [ ] Подключиться к серверу `ssh vladimir@178.72.129.61`
-   [ ] Изучить `/var/www/mi_core` (основной проект)
-   [ ] Изучить `/var/www/html` (возможные дашборды)
-   [ ] Найти и изучить `.env` файл с API ключами
-   [ ] Определить структуру проекта
-   [ ] Проверить какие службы запущены (nginx, php-fpm, mysql)

**Команды**:

```bash
ssh vladimir@178.72.129.61
cd /var/www/mi_core
ls -la
cat .env
cd /var/www/html
ls -la
systemctl status nginx mysql php*-fpm
```

### Задача 1.2: Изучение MySQL баз данных

-   [ ] Подключиться к MySQL
-   [ ] Проверить базу `mi_core_db`
-   [ ] Проверить базу `mi_core`
-   [ ] Определить какая база содержит актуальные данные
-   [ ] Изучить структуру таблиц
-   [ ] Найти как отличить клиентов (поле client_name, client_id и т.д.)
-   [ ] Определить таблицы с данными ТД Манхэттен
-   [ ] Найти данные клиента ZUZ для удаления

**Команды**:

```bash
mysql -u root -p
SHOW DATABASES;
USE mi_core_db;
SHOW TABLES;
DESCRIBE products;
DESCRIBE inventory_data;
SELECT DISTINCT client_name FROM products LIMIT 10;
SELECT COUNT(*) FROM products WHERE client_name LIKE '%Манхэттен%';
SELECT COUNT(*) FROM products WHERE client_name LIKE '%ZUZ%';

USE mi_core;
SHOW TABLES;
# Повторить те же проверки
```

### Задача 1.3: Анализ данных

-   [ ] Определить объем данных ТД Манхэттен
-   [ ] Проверить даты данных (последние 2 месяца)
-   [ ] Определить структуру данных Ozon
-   [ ] Определить структуру данных Wildberries
-   [ ] Составить список таблиц для миграции

**Команды**:

```bash
# В MySQL
SELECT MIN(created_at), MAX(created_at), COUNT(*)
FROM products
WHERE client_name LIKE '%Манхэттен%';

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = 'mi_core_db';
```

### Задача 1.4: Создание полного бэкапа

-   [ ] Создать директорию для бэкапов
-   [ ] Сделать дамп базы `mi_core_db`
-   [ ] Сделать дамп базы `mi_core`
-   [ ] Сохранить .env файл
-   [ ] Создать архив старого проекта

**Команды**:

```bash
sudo mkdir -p /backup/migration_$(date +%Y%m%d)
cd /backup/migration_$(date +%Y%m%d)

# Бэкап баз данных
sudo mysqldump -u root -p mi_core_db > mi_core_db_backup.sql
sudo mysqldump -u root -p mi_core > mi_core_backup.sql

# Бэкап проекта
sudo cp /var/www/mi_core/.env ./mi_core_env_backup
sudo tar -czf mi_core_project_backup.tar.gz /var/www/mi_core
sudo tar -czf html_backup.tar.gz /var/www/html

ls -lh
```

---

## Этап 2: Подготовка PostgreSQL

### Задача 2.1: Проверка и установка PostgreSQL

-   [ ] Проверить установлен ли PostgreSQL
-   [ ] Если нет - установить PostgreSQL 15+
-   [ ] Проверить версию
-   [ ] Запустить службу PostgreSQL

**Команды**:

```bash
psql --version

# Если не установлен:
sudo apt update
sudo apt install -y postgresql-15 postgresql-contrib-15

# Проверка
sudo systemctl status postgresql
sudo systemctl start postgresql
sudo systemctl enable postgresql
```

### Задача 2.2: Создание базы данных и пользователя

-   [ ] Создать пользователя PostgreSQL `mi_core_user`
-   [ ] Создать базу данных `mi_core_db`
-   [ ] Настроить права доступа
-   [ ] Проверить подключение

**Команды**:

```bash
sudo -u postgres psql

-- В PostgreSQL:
CREATE USER mi_core_user WITH PASSWORD 'secure_password_here';
CREATE DATABASE mi_core_db OWNER mi_core_user;
GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;
\q

# Проверка подключения
psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT version();"
```

### Задача 2.3: Создание схемы PostgreSQL

-   [ ] Загрузить файл схемы на сервер
-   [ ] Применить схему `postgresql_schema.sql`
-   [ ] Проверить созданные таблицы
-   [ ] Проверить индексы

**Команды**:

```bash
# Загрузить файлы миграции на сервер (с локальной машины)
scp migrations/postgresql_schema.sql vladimir@178.72.129.61:/tmp/

# На сервере
psql -h localhost -U mi_core_user -d mi_core_db -f /tmp/postgresql_schema.sql

# Проверка
psql -h localhost -U mi_core_user -d mi_core_db -c "\dt"
psql -h localhost -U mi_core_user -d mi_core_db -c "\di"
```

---

## Этап 3: Миграция данных MySQL → PostgreSQL

### Задача 3.1: Подготовка скрипта миграции

-   [ ] Загрузить скрипт миграции на сервер
-   [ ] Установить необходимые Python пакеты
-   [ ] Настроить параметры подключения
-   [ ] Добавить фильтр по клиенту "ТД Манхэттен"

**Команды**:

```bash
# Загрузить скрипт (с локальной машины)
scp migrations/migrate_mysql_to_postgresql.py vladimir@178.72.129.61:/tmp/

# На сервере
sudo apt install -y python3-pip python3-mysqldb python3-psycopg2
pip3 install pymysql psycopg2-binary

# Отредактировать скрипт для фильтрации по клиенту
nano /tmp/migrate_mysql_to_postgresql.py
```

### Задача 3.2: Экспорт данных из MySQL

-   [ ] Создать селективный дамп только данных ТД Манхэттен
-   [ ] Исключить данные клиента ZUZ
-   [ ] Проверить объем экспортированных данных

**Команды**:

```bash
# Определить правильную базу и экспортировать данные
# После изучения структуры на этапе 1.2
```

### Задача 3.3: Импорт данных в PostgreSQL

-   [ ] Запустить скрипт миграции
-   [ ] Мониторить процесс
-   [ ] Проверить логи миграции
-   [ ] Проверить количество записей

**Команды**:

```bash
python3 /tmp/migrate_mysql_to_postgresql.py

# Проверка данных
psql -h localhost -U mi_core_user -d mi_core_db << EOF
SELECT COUNT(*) FROM products;
SELECT COUNT(*) FROM inventory_data;
SELECT COUNT(*) FROM warehouses;
SELECT MIN(created_at), MAX(created_at) FROM products;
EOF
```

### Задача 3.4: Создание индексов и оптимизация

-   [ ] Загрузить файлы оптимизации
-   [ ] Применить индексы
-   [ ] Создать materialized views
-   [ ] Запустить ANALYZE

**Команды**:

```bash
# Загрузить файлы (с локальной машины)
scp migrations/postgresql_cleanup_optimization.sql vladimir@178.72.129.61:/tmp/
scp migrations/postgresql_materialized_views.sql vladimir@178.72.129.61:/tmp/

# Применить
psql -h localhost -U mi_core_user -d mi_core_db -f /tmp/postgresql_cleanup_optimization.sql
psql -h localhost -U mi_core_user -d mi_core_db -f /tmp/postgresql_materialized_views.sql

# Оптимизация
psql -h localhost -U mi_core_user -d mi_core_db -c "VACUUM ANALYZE;"
```

---

## Этап 4: Развертывание нового кода

### Задача 4.1: Создание новой директории проекта

-   [ ] Создать `/var/www/mi_core_etl_new`
-   [ ] Установить права доступа
-   [ ] Создать необходимые поддиректории

**Команды**:

```bash
sudo mkdir -p /var/www/mi_core_etl_new
sudo chown vladimir:vladimir /var/www/mi_core_etl_new
cd /var/www/mi_core_etl_new
mkdir -p storage/{logs,cache,backups}
```

### Задача 4.2: Загрузка кода на сервер

-   [ ] Загрузить код через rsync или git
-   [ ] Проверить все файлы на месте
-   [ ] Проверить структуру директорий

**Команды (с локальной машины)**:

```bash
# Вариант 1: rsync
rsync -avz --progress \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude '.git' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/cache/*' \
  ./ vladimir@178.72.129.61:/var/www/mi_core_etl_new/

# Вариант 2: tar + scp
tar -czf mi_core_new.tar.gz \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='.git' \
  .
scp mi_core_new.tar.gz vladimir@178.72.129.61:/tmp/

# На сервере:
cd /var/www/mi_core_etl_new
tar -xzf /tmp/mi_core_new.tar.gz
```

### Задача 4.3: Установка PHP зависимостей

-   [ ] Проверить версию PHP (нужна 8.1+)
-   [ ] Проверить наличие Composer
-   [ ] Установить зависимости
-   [ ] Проверить установку

**Команды**:

```bash
php -v

# Если нужна установка composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Установка зависимостей
cd /var/www/mi_core_etl_new
composer install --no-dev --optimize-autoloader --no-interaction

# Проверка
composer show
```

### Задача 4.4: Сборка React frontend

-   [ ] Проверить версию Node.js (нужна 18+)
-   [ ] Установить зависимости npm
-   [ ] Собрать production build
-   [ ] Проверить размер bundle

**Команды**:

```bash
node -v
npm -v

# Если нужна установка Node.js 18+
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Сборка frontend
cd /var/www/mi_core_etl_new/frontend
npm ci
npm run build

# Проверка
ls -lh dist/
du -sh dist/

# Копирование в public
mkdir -p ../public/build
cp -r dist/* ../public/build/
```

### Задача 4.5: Настройка .env файла

-   [ ] Скопировать .env.example в .env
-   [ ] Скопировать API ключи из старого .env
-   [ ] Настроить PostgreSQL подключение
-   [ ] Настроить параметры для клиента ТД Манхэттен
-   [ ] Установить безопасные права

**Команды**:

```bash
cd /var/www/mi_core_etl_new
cp .env.example .env

# Скопировать ключи из старого проекта
grep "OZON_" /var/www/mi_core/.env >> .env
grep "WB_" /var/www/mi_core/.env >> .env

# Отредактировать .env
nano .env

# Установить права
chmod 600 .env
```

**Содержимое .env**:

```env
# PostgreSQL Database
PG_HOST=localhost
PG_PORT=5432
PG_USER=mi_core_user
PG_PASSWORD=secure_password_here
PG_NAME=mi_core_db

# API Keys (скопировать из старого .env)
OZON_CLIENT_ID=...
OZON_API_KEY=...
WB_API_KEY=...

# Client Configuration
CLIENT_NAME="ТД Манхэттен"

# Application
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=INFO
```

### Задача 4.6: Установка прав доступа

-   [ ] Установить владельца www-data
-   [ ] Установить права на директории
-   [ ] Установить права на файлы
-   [ ] Защитить .env файл
-   [ ] Сделать скрипты исполняемыми

**Команды**:

```bash
cd /var/www/mi_core_etl_new

# Владелец
sudo chown -R www-data:www-data .

# Права на директории и файлы
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

# Writable директории
sudo chmod -R 775 storage/
sudo chmod -R 775 public/

# Защита .env
sudo chmod 600 .env

# Исполняемые скрипты
sudo chmod +x deployment/scripts/*.sh
sudo chmod +x scripts/*.sh
sudo chmod +x tests/*.sh
```

---

## Этап 5: Настройка Nginx

### Задача 5.1: Создание конфигурации Nginx

-   [ ] Создать новый конфиг для порта 8080
-   [ ] Настроить пути к проекту
-   [ ] Настроить PHP-FPM
-   [ ] Проверить конфигурацию

**Команды**:

```bash
sudo nano /etc/nginx/sites-available/mi_core_new
```

**Содержимое конфига**:

```nginx
server {
    listen 8080;
    server_name 178.72.129.61;

    root /var/www/mi_core_etl_new/public;
    index index.php index.html;

    # Логи
    access_log /var/log/nginx/mi_core_new_access.log;
    error_log /var/log/nginx/mi_core_new_error.log;

    # Frontend (React)
    location / {
        try_files $uri $uri/ /build/index.html;
    }

    # API
    location /api {
        try_files $uri $uri/ /api/index.php?$query_string;

        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Запретить доступ к .env
    location ~ /\.env {
        deny all;
    }

    # Статические файлы
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

### Задача 5.2: Активация конфигурации

-   [ ] Создать символическую ссылку
-   [ ] Проверить конфигурацию nginx
-   [ ] Перезагрузить nginx
-   [ ] Проверить статус

**Команды**:

```bash
sudo ln -s /etc/nginx/sites-available/mi_core_new /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl status nginx
```

---

## Этап 6: Тестирование

### Задача 6.1: Запуск smoke tests

-   [ ] Запустить тесты
-   [ ] Проверить результаты
-   [ ] Исправить ошибки если есть

**Команды**:

```bash
cd /var/www/mi_core_etl_new
bash tests/smoke_tests.sh
```

### Задача 6.2: Проверка API

-   [ ] Проверить health endpoint
-   [ ] Проверить inventory endpoint
-   [ ] Проверить данные в ответе

**Команды**:

```bash
curl http://178.72.129.61:8080/api/health
curl http://178.72.129.61:8080/api/inventory-v4.php?limit=10 | jq
```

### Задача 6.3: Проверка дашборда

-   [ ] Открыть в браузере http://178.72.129.61:8080
-   [ ] Проверить загрузку дашборда
-   [ ] Проверить отображение данных
-   [ ] Проверить все три категории
-   [ ] Проверить переключение режимов
-   [ ] Убедиться что только данные ТД Манхэттен

### Задача 6.4: Проверка логов

-   [ ] Проверить логи приложения
-   [ ] Проверить логи Nginx
-   [ ] Проверить логи PHP-FPM
-   [ ] Убедиться что нет критических ошибок

**Команды**:

```bash
tail -50 /var/www/mi_core_etl_new/storage/logs/error_$(date +%Y-%m-%d).log
tail -50 /var/log/nginx/mi_core_new_error.log
tail -50 /var/log/php8.1-fpm.log
```

---

## Этап 7: Настройка мониторинга

### Задача 7.1: Установка системы мониторинга

-   [ ] Запустить скрипт setup_monitoring.sh
-   [ ] Проверить созданные скрипты
-   [ ] Проверить cron jobs

**Команды**:

```bash
cd /var/www/mi_core_etl_new
bash scripts/setup_monitoring.sh
crontab -l | grep mi_core
```

### Задача 7.2: Проверка мониторинга

-   [ ] Запустить мониторинг вручную
-   [ ] Проверить отчеты
-   [ ] Проверить дашборд мониторинга

**Команды**:

```bash
bash scripts/monitor_system.sh
bash scripts/view_monitoring.sh
ls -la storage/monitoring/
```

---

## Этап 8: Переключение на production

### Задача 8.1: Остановка старых cron jobs

-   [ ] Посмотреть текущие cron jobs
-   [ ] Закомментировать старые задачи
-   [ ] Сохранить изменения

**Команды**:

```bash
crontab -l > /tmp/old_crontab_backup
crontab -e
# Закомментировать все задачи связанные со старым проектом
```

### Задача 8.2: Переключение Nginx на порт 80

-   [ ] Отредактировать конфиг
-   [ ] Изменить порт с 8080 на 80
-   [ ] Отключить старый конфиг (если есть)
-   [ ] Проверить и перезагрузить nginx

**Команды**:

```bash
sudo nano /etc/nginx/sites-available/mi_core_new
# Изменить listen 8080 на listen 80

# Отключить старый конфиг
sudo rm /etc/nginx/sites-enabled/old_config

sudo nginx -t
sudo systemctl reload nginx
```

### Задача 8.3: Настройка автоматических бэкапов

-   [ ] Запустить скрипт setup_backup_cron.sh
-   [ ] Проверить настройки бэкапа
-   [ ] Проверить cron job

**Команды**:

```bash
cd /var/www/mi_core_etl_new
bash scripts/setup_backup_cron.sh
crontab -l | grep backup
```

### Задача 8.4: Финальная проверка

-   [ ] Открыть http://178.72.129.61 (порт 80)
-   [ ] Проверить все функции
-   [ ] Мониторить логи 30 минут
-   [ ] Проверить производительность

**Команды**:

```bash
# Мониторинг в реальном времени
tail -f /var/www/mi_core_etl_new/storage/logs/info_$(date +%Y-%m-%d).log
tail -f /var/log/nginx/mi_core_new_access.log
```

---

## Этап 9: Очистка (через неделю)

### Задача 9.1: Удаление старого проекта

-   [ ] Убедиться что новая система работает стабильно
-   [ ] Создать финальный бэкап старого проекта
-   [ ] Удалить старую директорию
-   [ ] Удалить старые конфиги nginx

**Команды**:

```bash
# Финальный бэкап
sudo tar -czf /backup/final_old_project_$(date +%Y%m%d).tar.gz /var/www/mi_core

# Удаление
sudo rm -rf /var/www/mi_core
sudo rm /etc/nginx/sites-enabled/old_mi_core_config
```

### Задача 9.2: Очистка MySQL

-   [ ] Удалить данные клиента ZUZ
-   [ ] Удалить неиспользуемую базу данных
-   [ ] Или оставить как архив

**Команды**:

```bash
mysql -u root -p

-- Удалить данные ZUZ
DELETE FROM products WHERE client_name LIKE '%ZUZ%';
DELETE FROM inventory_data WHERE client_name LIKE '%ZUZ%';

-- Или удалить всю старую базу (если не нужна)
-- DROP DATABASE mi_core;
-- DROP DATABASE mi_core_db;
```

---

## Чеклист завершения

### Технические проверки

-   [ ] PostgreSQL работает и содержит все данные
-   [ ] Дашборд доступен и отображает данные
-   [ ] API отвечает корректно
-   [ ] Все smoke tests проходят
-   [ ] Мониторинг настроен и работает
-   [ ] Автоматические бэкапы настроены
-   [ ] Логи пишутся корректно
-   [ ] Нет критических ошибок

### Функциональные проверки

-   [ ] Отображаются только данные ТД Манхэттен
-   [ ] Данные за последние 2 месяца на месте
-   [ ] Три категории работают корректно
-   [ ] Переключение режимов работает
-   [ ] Производительность приемлемая (< 2 сек)

### Безопасность

-   [ ] .env файл защищен (права 600)
-   [ ] API ключи скопированы корректно
-   [ ] Права доступа установлены правильно
-   [ ] Бэкапы создаются автоматически

### Документация

-   [ ] Обновлен README с новыми путями
-   [ ] Задокументированы изменения
-   [ ] Сохранены все пароли и ключи

---

**Статус**: Готов к выполнению  
**Ожидаемое время**: 3-4 часа  
**Риски**: Низкие (есть полные бэкапы)
