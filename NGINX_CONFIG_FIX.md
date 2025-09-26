# 🔧 Исправление конфигурации Nginx

## Проблема

Конфигурация Nginx на сервере не соответствует реальной структуре файлов в репозитории.

## Симптомы

- Ошибка 500 при обращении к API endpoints
- Файлы существуют, но Nginx их не находит
- В логах PHP-FPM нет ошибок

## Причина

Конфигурация Nginx содержит неправильные пути, которые не соответствуют реальной структуре проекта.

---

## 🔍 Диагностика

### Проверьте реальную структуру файлов:

```bash
ls -la /var/www/html/src/api/
# Должно показать:
# countries.php
# countries-by-brand.php
# countries-by-model.php
# products-filter.php
```

### Проверьте текущую конфигурацию Nginx:

```bash
sudo nano /etc/nginx/sites-available/mi_core_api
# или
sudo nano /etc/nginx/sites-available/country-filter
```

---

## ✅ Решение

### 1. Найдите секцию location для PHP

Ищите строки похожие на:

```nginx
location ~ ^/country_filter_module/api/.*\.php$ {
```

### 2. Исправьте путь

**❌ НЕПРАВИЛЬНО:**

```nginx
location ~ ^/country_filter_module/api/.*\.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

**✅ ПРАВИЛЬНО:**

```nginx
location ~ ^/api/.*\.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;

    # CORS headers
    add_header Access-Control-Allow-Origin "*" always;
    add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
    add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;

    if ($request_method = 'OPTIONS') {
        return 200;
    }
}
```

### 3. Проверьте root директорию

Убедитесь что указана правильная корневая директория:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html/src;  # ← Важно: должно быть /src
    # ...
}
```

### 4. Примените изменения

```bash
# Проверьте конфигурацию
sudo nginx -t

# Если OK, перезагрузите Nginx
sudo systemctl reload nginx
```

---

## 🧪 Тестирование

### Проверьте API endpoints:

```bash
# Тест 1: Все страны
curl http://your-domain.com/api/countries.php

# Тест 2: Страны по марке
curl "http://your-domain.com/api/countries-by-brand.php?brand_id=1"

# Тест 3: Фильтрация товаров
curl "http://your-domain.com/api/products-filter.php?country_id=1"
```

### Ожидаемый результат:

```json
{
    "success": true,
    "data": [...],
    "message": "Countries retrieved successfully"
}
```

---

## 📋 Полная рабочая конфигурация

Используйте эту конфигурацию как эталон:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html/src;
    index index.php index.html demo/country-filter-demo.html;

    # Логи
    access_log /var/log/nginx/country-filter-access.log;
    error_log /var/log/nginx/country-filter-error.log;

    # Основная обработка запросов
    location / {
        try_files $uri $uri/ /demo/country-filter-demo.html;
    }

    # API endpoints - ПРАВИЛЬНЫЕ ПУТИ!
    location ~ ^/api/.*\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # CORS headers
        add_header Access-Control-Allow-Origin "*" always;
        add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
        add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;

        if ($request_method = 'OPTIONS') {
            return 200;
        }
    }

    # Обработка остальных PHP файлов
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Кэширование статических файлов
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1M;
        add_header Cache-Control "public, immutable";
    }

    # Безопасность
    location ~ /\. {
        deny all;
    }

    location ~* \.(log|sql|bak)$ {
        deny all;
    }

    # Сжатие
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/xml+rss application/json;
}
```

---

## 🎯 Результат

После исправления:

- ✅ API endpoints работают корректно
- ✅ Нет ошибок 500
- ✅ Конфигурация соответствует структуре файлов
- ✅ Демо страницы загружаются

**Проблема решена!** 🎉
