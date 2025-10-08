# Руководство по развертыванию системы MDM

## Содержание

1. [Системные требования](#системные-требования)
2. [Подготовка окружения](#подготовка-окружения)
3. [Установка зависимостей](#установка-зависимостей)
4. [Настройка базы данных](#настройка-базы-данных)
5. [Конфигурация приложения](#конфигурация-приложения)
6. [Развертывание в продакшн](#развертывание-в-продакшн)
7. [Мониторинг и логирование](#мониторинг-и-логирование)

## Системные требования

### Минимальные требования

**Сервер приложений:**

- CPU: 4 ядра, 2.4 GHz
- RAM: 8 GB
- Диск: 100 GB SSD
- ОС: Ubuntu 20.04 LTS или CentOS 8

**База данных:**

- CPU: 4 ядра, 2.4 GHz
- RAM: 16 GB
- Диск: 500 GB SSD
- MySQL 8.0 или MariaDB 10.5

**Веб-сервер:**

- Nginx 1.18+ или Apache 2.4+
- PHP 7.4+ с расширениями
- SSL-сертификат

### Рекомендуемые требования

**Сервер приложений:**

- CPU: 8 ядер, 3.0 GHz
- RAM: 16 GB
- Диск: 200 GB NVMe SSD
- ОС: Ubuntu 22.04 LTS

**База данных:**

- CPU: 8 ядер, 3.0 GHz
- RAM: 32 GB
- Диск: 1 TB NVMe SSD в RAID 10
- MySQL 8.0 с репликацией

## Подготовка окружения

### Обновление системы

```bash
# Ubuntu/Debian
sudo apt update && sudo apt upgrade -y

# CentOS/RHEL
sudo yum update -y
```

### Установка базового ПО

```bash
# Ubuntu/Debian
sudo apt install -y curl wget git unzip software-properties-common

# CentOS/RHEL
sudo yum install -y curl wget git unzip epel-release
```

### Настройка файрвола

```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# Firewalld (CentOS)
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

## Установка зависимостей

### PHP и расширения

```bash
# Ubuntu 22.04
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.1 php8.1-fpm php8.1-mysql php8.1-json \
    php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip php8.1-gd \
    php8.1-intl php8.1-bcmath php8.1-redis

# CentOS 8
sudo dnf install -y php php-fpm php-mysqlnd php-json php-curl \
    php-mbstring php-xml php-zip php-gd php-intl php-bcmath
```

### Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

### MySQL

```bash
# Ubuntu
sudo apt install -y mysql-server mysql-client

# CentOS
sudo dnf install -y mysql-server mysql
sudo systemctl enable mysqld
sudo systemctl start mysqld
```

### Nginx

```bash
# Ubuntu
sudo apt install -y nginx

# CentOS
sudo dnf install -y nginx
sudo systemctl enable nginx
sudo systemctl start nginx
```

### Redis (для кэширования)

```bash
# Ubuntu
sudo apt install -y redis-server

# CentOS
sudo dnf install -y redis
sudo systemctl enable redis
sudo systemctl start redis
```

## Настройка базы данных

### Создание пользователя и базы данных

```sql
-- Подключение к MySQL как root
mysql -u root -p

-- Создание базы данных
CREATE DATABASE mdm_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Создание пользователя
CREATE USER 'mdm_user'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON mdm_production.* TO 'mdm_user'@'localhost';
FLUSH PRIVILEGES;
```

### Настройка MySQL для производительности

Отредактируйте `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
# Основные настройки
max_connections = 200
innodb_buffer_pool_size = 12G  # 75% от RAM
innodb_log_file_size = 1G
innodb_flush_log_at_trx_commit = 2

# Настройки для MDM
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO

# Кэширование запросов
query_cache_type = 1
query_cache_size = 256M

# Временные таблицы
tmp_table_size = 256M
max_heap_table_size = 256M

# Логирование медленных запросов
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

### Создание схемы базы данных

```bash
# Выполнение миграций
cd /var/www/mdm
php bin/migrate.php --env=production
```

## Конфигурация приложения

### Клонирование репозитория

```bash
cd /var/www
sudo git clone https://github.com/your-company/mdm-system.git mdm
sudo chown -R www-data:www-data mdm
cd mdm
```

### Установка зависимостей

```bash
composer install --no-dev --optimize-autoloader
```

### Настройка конфигурации

Создайте файл `.env`:

```bash
cp .env.example .env
```

Отредактируйте `.env`:

```env
# Окружение
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mdm.your-company.com

# База данных
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mdm_production
DB_USER=mdm_user
DB_PASS=secure_password_here

# Redis
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=

# API ключи внешних сервисов
OZON_API_KEY=your_ozon_api_key
OZON_CLIENT_ID=your_ozon_client_id
WB_API_KEY=your_wildberries_api_key

# Настройки безопасности
JWT_SECRET=generate_secure_jwt_secret_here
ENCRYPTION_KEY=generate_32_char_encryption_key

# Настройки ETL
ETL_BATCH_SIZE=1000
ETL_TIMEOUT=3600
ETL_MAX_RETRIES=3

# Email настройки
MAIL_HOST=smtp.your-company.com
MAIL_PORT=587
MAIL_USERNAME=mdm@your-company.com
MAIL_PASSWORD=mail_password
MAIL_FROM_ADDRESS=mdm@your-company.com
MAIL_FROM_NAME="MDM System"

# Логирование
LOG_LEVEL=info
LOG_PATH=/var/log/mdm/
```

### Настройка прав доступа

```bash
sudo chown -R www-data:www-data /var/www/mdm
sudo chmod -R 755 /var/www/mdm
sudo chmod -R 775 /var/www/mdm/storage
sudo chmod -R 775 /var/www/mdm/cache
sudo chmod 600 /var/www/mdm/.env
```

### Создание директорий для логов

```bash
sudo mkdir -p /var/log/mdm
sudo chown www-data:www-data /var/log/mdm
sudo chmod 755 /var/log/mdm
```

## Развертывание в продакшн

### Настройка Nginx

Создайте файл `/etc/nginx/sites-available/mdm`:

```nginx
server {
    listen 80;
    server_name mdm.your-company.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name mdm.your-company.com;
    root /var/www/mdm/public;
    index index.php;

    # SSL настройки
    ssl_certificate /path/to/ssl/certificate.crt;
    ssl_certificate_key /path/to/ssl/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    ssl_prefer_server_ciphers off;

    # Безопасность
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;

    # Основная конфигурация
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # API endpoints
    location /api/ {
        try_files $uri $uri/ /api/index.php?$query_string;
    }

    # Статические файлы
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Запрет доступа к служебным файлам
    location ~ /\. {
        deny all;
    }

    location ~ /(vendor|storage|cache|config)/ {
        deny all;
    }
}
```

Активируйте конфигурацию:

```bash
sudo ln -s /etc/nginx/sites-available/mdm /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```
