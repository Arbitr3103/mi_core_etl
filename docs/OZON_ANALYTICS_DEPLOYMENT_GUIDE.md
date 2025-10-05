# Руководство по развертыванию Ozon Analytics Integration

## Обзор

Данное руководство описывает пошаговый процесс развертывания интеграции аналитики Ozon в существующую систему Manhattan. Включает настройку базы данных, конфигурацию сервера, установку зависимостей и проверку работоспособности.

## Предварительные требования

### Системные требования

- **Операционная система**: Linux (Ubuntu 18.04+, CentOS 7+) или macOS
- **PHP**: версия 7.4 или выше
- **MySQL**: версия 5.7 или выше
- **Web Server**: Apache 2.4+ или Nginx 1.14+
- **Memory**: минимум 512MB RAM
- **Disk Space**: минимум 2GB свободного места

### PHP расширения

Убедитесь, что установлены следующие PHP расширения:

```bash
# Проверка установленных расширений
php -m | grep -E "(curl|json|pdo|pdo_mysql|mbstring|openssl)"

# Установка недостающих расширений (Ubuntu/Debian)
sudo apt-get install php-curl php-json php-pdo php-mysql php-mbstring php-openssl

# Установка недостающих расширений (CentOS/RHEL)
sudo yum install php-curl php-json php-pdo php-mysql php-mbstring php-openssl
```

### Доступы и учетные данные

Перед началом развертывания подготовьте:

1. **Ozon API credentials**:

   - Client ID
   - API Key
   - Доступ к Ozon Seller API

2. **Database credentials**:

   - Хост базы данных
   - Имя базы данных
   - Пользователь с правами CREATE, INSERT, UPDATE, DELETE, SELECT

3. **Server access**:
   - SSH доступ к серверу
   - Права sudo (при необходимости)

## Пошаговое развертывание

### Шаг 1: Подготовка окружения

#### 1.1 Создание резервной копии

```bash
# Создание резервной копии текущей системы
sudo cp -r /var/www/html /var/www/html_backup_$(date +%Y%m%d_%H%M%S)

# Создание резервной копии базы данных
mysqldump -u [username] -p [database_name] > backup_$(date +%Y%m%d_%H%M%S).sql
```

#### 1.2 Проверка текущей конфигурации

```bash
# Проверка версии PHP
php --version

# Проверка конфигурации PHP
php --ini

# Проверка подключения к MySQL
mysql -u [username] -p -e "SELECT VERSION();"
```

### Шаг 2: Установка файлов

#### 2.1 Загрузка файлов системы

Убедитесь, что все необходимые файлы находятся в правильных директориях:

```bash
# Структура файлов
src/
├── classes/
│   ├── OzonAnalyticsAPI.php
│   ├── OzonDataCache.php
│   ├── OzonSecurityManager.php
│   └── OzonSecurityMiddleware.php
├── api/
│   └── ozon-analytics.php
├── js/
│   ├── OzonFunnelChart.js
│   ├── OzonAnalyticsIntegration.js
│   ├── OzonDemographics.js
│   ├── OzonExportManager.js
│   └── OzonSettingsManager.js
└── css/
    └── ozon-performance.css

migrations/
├── add_ozon_analytics_tables.sql
├── add_ozon_token_fields.sql
└── add_ozon_performance_indexes.sql
```

#### 2.2 Установка прав доступа

```bash
# Установка правильных прав доступа
sudo chown -R www-data:www-data /var/www/html/src/
sudo chmod -R 755 /var/www/html/src/
sudo chmod -R 644 /var/www/html/src/classes/*.php
sudo chmod -R 644 /var/www/html/src/api/*.php
sudo chmod +x /var/www/html/apply_ozon_analytics_migration.sh
```

### Шаг 3: Настройка базы данных

#### 3.1 Применение миграций

```bash
# Переход в директорию проекта
cd /var/www/html

# Применение основной миграции
./apply_ozon_analytics_migration.sh

# Или ручное применение
mysql -u [username] -p [database_name] < migrations/add_ozon_analytics_tables.sql
mysql -u [username] -p [database_name] < migrations/add_ozon_token_fields.sql
mysql -u [username] -p [database_name] < migrations/add_ozon_performance_indexes.sql
```

#### 3.2 Проверка миграций

```bash
# Проверка созданных таблиц
python3 verify_ozon_tables.py

# Или ручная проверка
mysql -u [username] -p [database_name] -e "SHOW TABLES LIKE 'ozon_%';"
```

#### 3.3 Создание индексов для производительности

```sql
-- Дополнительные индексы для оптимизации
CREATE INDEX idx_ozon_funnel_date_product ON ozon_funnel_data(date_from, date_to, product_id);
CREATE INDEX idx_ozon_demographics_region_age ON ozon_demographics(region, age_group);
CREATE INDEX idx_ozon_campaigns_performance ON ozon_campaigns(campaign_id, date_from, ctr, roas);
```

### Шаг 4: Конфигурация приложения

#### 4.1 Настройка переменных окружения

Создайте или обновите файл `.env`:

```bash
# Database Configuration
DB_HOST=127.0.0.1
DB_NAME=mi_core_db
DB_USER=ingest_user
DB_PASSWORD=xK9#mQ7$vN2@pL!rT4wY

# Ozon API Configuration
OZON_CLIENT_ID=your_client_id_here
OZON_API_KEY=your_api_key_here

# Cache Configuration
CACHE_TTL=3600
CACHE_MAX_SIZE=1000
CACHE_CLEANUP_INTERVAL=86400

# Security Configuration
RATE_LIMIT_ENABLED=true
RATE_LIMIT_REQUESTS_PER_MINUTE=60
AUDIT_LOG_ENABLED=true
SECURITY_LOG_LEVEL=INFO

# Performance Configuration
MAX_EXPORT_RECORDS=10000
API_TIMEOUT=30
CONNECTION_TIMEOUT=10
```

#### 4.2 Настройка прав доступа к .env

```bash
# Установка безопасных прав доступа
sudo chmod 600 .env
sudo chown www-data:www-data .env
```

### Шаг 5: Настройка веб-сервера

#### 5.1 Конфигурация Apache

Добавьте в конфигурацию Apache:

```apache
# /etc/apache2/sites-available/manhattan.conf
<VirtualHost *:80>
    ServerName api.zavodprostavok.ru
    DocumentRoot /var/www/html

    # Ozon Analytics API
    <Directory "/var/www/html/src/api">
        AllowOverride All
        Require all granted

        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
    </Directory>

    # Enable mod_rewrite
    RewriteEngine On

    # API routing
    RewriteRule ^/api/ozon-analytics/(.*)$ /src/api/ozon-analytics.php?action=$1 [QSA,L]

    ErrorLog ${APACHE_LOG_DIR}/manhattan_error.log
    CustomLog ${APACHE_LOG_DIR}/manhattan_access.log combined
</VirtualHost>
```

#### 5.2 Конфигурация Nginx

Альтернативная конфигурация для Nginx:

```nginx
# /etc/nginx/sites-available/manhattan
server {
    listen 80;
    server_name api.zavodprostavok.ru;
    root /var/www/html;
    index index.php index.html;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API routing
    location /api/ozon-analytics/ {
        try_files $uri $uri/ /src/api/ozon-analytics.php?$query_string;
    }

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Security
    location ~ /\.env {
        deny all;
    }

    location ~ /migrations/ {
        deny all;
    }
}
```

### Шаг 6: Настройка автоматических задач

#### 6.1 Еженедельное обновление данных

Добавьте в crontab:

```bash
# Редактирование crontab
sudo crontab -e

# Добавьте следующие строки:
# Еженедельное обновление данных Ozon (каждое воскресенье в 02:00)
0 2 * * 0 /usr/bin/php /var/www/html/ozon_weekly_update.py >> /var/log/ozon_update.log 2>&1

# Очистка кэша (каждый день в 03:00)
0 3 * * * /usr/bin/php /var/www/html/cleanup_ozon_cache.php >> /var/log/ozon_cleanup.log 2>&1

# Очистка временных файлов экспорта (каждые 30 минут)
*/30 * * * * /usr/bin/curl -X POST 'http://localhost/src/api/ozon-analytics.php?action=cleanup-downloads' >> /var/log/ozon_cleanup.log 2>&1
```

#### 6.2 Мониторинг системы

Создайте скрипт мониторинга:

```bash
#!/bin/bash
# /var/www/html/monitor_ozon_health.sh

# Проверка API
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/src/api/ozon-analytics.php?action=health)

if [ "$API_STATUS" != "200" ]; then
    echo "$(date): Ozon Analytics API недоступен (HTTP $API_STATUS)" >> /var/log/ozon_monitor.log
    # Отправка уведомления (настройте по необходимости)
    # mail -s "Ozon Analytics Alert" admin@company.com < /dev/null
fi

# Проверка базы данных
DB_STATUS=$(mysql -u [username] -p[password] [database] -e "SELECT COUNT(*) FROM ozon_api_settings;" 2>/dev/null)

if [ -z "$DB_STATUS" ]; then
    echo "$(date): База данных Ozon Analytics недоступна" >> /var/log/ozon_monitor.log
fi
```

Добавьте в crontab:

```bash
# Мониторинг каждые 5 минут
*/5 * * * * /var/www/html/monitor_ozon_health.sh
```

### Шаг 7: Тестирование развертывания

#### 7.1 Базовые тесты

```bash
# Тест подключения к базе данных
php test_db_connection.php

# Тест основной функциональности
php test_ozon_analytics_integration.php

# Тест API endpoints
php test_ozon_analytics_api.php

# Тест безопасности
php test_ozon_security_integration.php
```

#### 7.2 Интеграционные тесты

```bash
# Полный набор тестов
php run_all_tests.php

# Тест производительности
php test_ozon_performance.php

# Тест экспорта данных
php test_ozon_export_functionality.php
```

#### 7.3 Тест через веб-интерфейс

1. Откройте браузер и перейдите на `https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php`
2. Перейдите на вкладку "⚙️ Настройки Ozon"
3. Введите тестовые учетные данные
4. Нажмите "Тестировать подключение"
5. При успешном подключении перейдите на вкладку "📊 Аналитика Ozon"
6. Проверьте загрузку данных

### Шаг 8: Настройка безопасности

#### 8.1 Настройка SSL/TLS

```bash
# Установка Let's Encrypt (если используется)
sudo apt-get install certbot python3-certbot-apache

# Получение SSL сертификата
sudo certbot --apache -d api.zavodprostavok.ru

# Автоматическое обновление сертификата
sudo crontab -e
# Добавить: 0 12 * * * /usr/bin/certbot renew --quiet
```

#### 8.2 Настройка файрвола

```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# iptables (альтернатива)
sudo iptables -A INPUT -p tcp --dport 22 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -j ACCEPT
```

#### 8.3 Настройка логирования

```bash
# Создание директорий для логов
sudo mkdir -p /var/log/ozon-analytics
sudo chown www-data:www-data /var/log/ozon-analytics

# Настройка ротации логов
sudo tee /etc/logrotate.d/ozon-analytics << EOF
/var/log/ozon-analytics/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
}
EOF
```

### Шаг 9: Оптимизация производительности

#### 9.1 Настройка PHP

Обновите `/etc/php/7.4/apache2/php.ini`:

```ini
; Memory settings
memory_limit = 512M
max_execution_time = 300
max_input_time = 300

; Upload settings
upload_max_filesize = 50M
post_max_size = 50M

; OPcache settings
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
```

#### 9.2 Настройка MySQL

Обновите `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
# Performance settings
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
query_cache_size = 32M
query_cache_type = 1

# Connection settings
max_connections = 200
connect_timeout = 10
wait_timeout = 600
```

#### 9.3 Настройка кэширования

```bash
# Установка Redis (опционально)
sudo apt-get install redis-server

# Конфигурация Redis
sudo tee -a /etc/redis/redis.conf << EOF
maxmemory 256mb
maxmemory-policy allkeys-lru
EOF

sudo systemctl restart redis-server
```

### Шаг 10: Финальная проверка

#### 10.1 Чек-лист развертывания

- [ ] База данных настроена и миграции применены
- [ ] Все файлы загружены с правильными правами доступа
- [ ] Переменные окружения настроены
- [ ] Веб-сервер настроен и перезапущен
- [ ] SSL сертификат установлен (если требуется)
- [ ] Cron задачи настроены
- [ ] Все тесты пройдены успешно
- [ ] Мониторинг настроен
- [ ] Логирование работает
- [ ] Резервные копии созданы

#### 10.2 Проверка работоспособности

```bash
# Финальный тест всех компонентов
./run_deployment_verification.sh

# Проверка статуса сервисов
sudo systemctl status apache2  # или nginx
sudo systemctl status mysql
sudo systemctl status redis-server  # если установлен

# Проверка логов
tail -f /var/log/apache2/error.log
tail -f /var/log/ozon-analytics/application.log
```

## Откат развертывания

В случае проблем с развертыванием:

### Откат базы данных

```bash
# Откат миграций
./rollback_ozon_analytics_migration.sh

# Восстановление из резервной копии
mysql -u [username] -p [database_name] < backup_YYYYMMDD_HHMMSS.sql
```

### Откат файлов

```bash
# Восстановление файлов из резервной копии
sudo rm -rf /var/www/html
sudo mv /var/www/html_backup_YYYYMMDD_HHMMSS /var/www/html
sudo systemctl restart apache2
```

## Поддержка и мониторинг

### Ключевые метрики для мониторинга

- Время ответа API Ozon
- Процент успешных запросов
- Использование памяти и CPU
- Размер кэша и базы данных
- Количество активных пользователей

### Алерты

Настройте алерты для:

- API недоступен более 5 минут
- Процент ошибок превышает 10%
- Использование диска превышает 80%
- Время ответа превышает 5 секунд

### Контакты поддержки

- **Email**: support@manhattan-system.ru
- **Telegram**: @manhattan_support
- **Документация**: [GitHub Repository]
- **Рабочие часы**: Пн-Пт 9:00-18:00 МСК

---

_Последнее обновление: Январь 2025_
_Версия: 1.0_
