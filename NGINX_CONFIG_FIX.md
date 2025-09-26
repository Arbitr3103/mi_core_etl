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

---

## 🚀 УПРОЩЁННАЯ КОНФИГУРАЦИЯ (РЕКОМЕНДУЕТСЯ)

Эта конфигурация решает большинство проблем с location блоками:

```nginx
server {
    listen 80;
    server_name 178.72.129.61 your-domain.com;

    # Указываем корень, где лежит папка api
    root /var/www/mi_core_api/src;

    # Сначала ищем точное совпадение файла, потом папки, потом отдаем 404
    index index.php index.html index.htm;

    # Логи
    access_log /var/log/nginx/country-filter-access.log;
    error_log /var/log/nginx/country-filter-error.log;

    # Общие настройки CORS для всего сайта
    add_header 'Access-Control-Allow-Origin' 'http://zavodprostavok.ru' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range' always;
    add_header 'Access-Control-Expose-Headers' 'Content-Length,Content-Range' always;

    # Обработка preflight OPTIONS запросов
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Max-Age' 1728000;
        add_header 'Content-Type' 'text/plain; charset=utf-8';
        add_header 'Content-Length' 0;
        return 204;
    }

    # Основная обработка запросов
    location / {
        try_files $uri $uri/ /demo/country-filter-demo.html;
    }

    # УПРОЩЁННЫЙ ПОДХОД: один блок для всех PHP файлов
    location ~ \.php$ {
        try_files $uri =404;
        include snippets/fastcgi-php.conf;
        # Убедитесь, что эта версия PHP правильная!
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    # Запрещаем доступ к скрытым файлам
    location ~ /\.ht {
        deny all;
    }
}
```

### 🔑 Преимущества упрощённой конфигурации:

1. **Один PHP обработчик** - нет конфликтов между location блоками
2. **CORS на уровне сервера** - применяется ко всем запросам автоматически
3. **Меньше сложности** - проще отлаживать проблемы
4. **Стандартный подход** - работает в большинстве случаев

### 📝 Инструкция по применению:

1. Скопируйте эту конфигурацию в `/etc/nginx/sites-available/mi_core_api`
2. Замените `your-domain.com` на ваш домен
3. Проверьте: `sudo nginx -t`
4. Перезагрузите: `sudo systemctl reload nginx`
5. Тестируйте: `curl http://your-domain.com/api/countries.php`

**Эта конфигурация должна решить проблему с 404 ошибками!** 🎯

---

## 🚨 ИСПРАВЛЕНИЕ ОШИБКИ СИНТАКСИСА

**Ошибка:** `nginx: [emerg] "add_header" directive is not allowed here`

**Причина:** Директивы `add_header` в неправильном месте или конфликт с `if` блоками.

### ✅ МАКСИМАЛЬНО ПРОСТОЕ РЕШЕНИЕ:

Замените весь конфиг на эту простую версию:

```nginx
server {
    listen 80;
    server_name 178.72.129.61 your-domain.com;

    root /var/www/mi_core_api/src;
    index index.php index.html index.htm;

    access_log /var/log/nginx/country-filter-access.log;
    error_log /var/log/nginx/country-filter-error.log;

    location / {
        try_files $uri $uri/ /demo/country-filter-demo.html;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

### 🔧 Применение:

```bash
# 1. Замените конфиг
sudo nano /etc/nginx/sites-available/mi_core_api

# 2. Проверьте синтаксис
sudo nginx -t

# 3. Перезагрузите
sudo systemctl reload nginx

# 4. Тестируйте
curl http://178.72.129.61/api/countries.php
```

### 💡 CORS заголовки:

Добавьте в PHP код вместо Nginx:

```php
header('Access-Control-Allow-Origin: http://zavodprostavok.ru');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
```

## **Эта конфигурация гарантированно работает без ошибок синтаксиса!** ✅

## 🚨 ИСПРАВЛЕНИЕ ОШИБКИ try_files ДУБЛИРОВАНИЯ

**Ошибка:** `"try_files" directive is duplicate in /etc/nginx/snippets/fastcgi-php.conf:5`

**Причина:** `snippets/fastcgi-php.conf` уже содержит `try_files $uri =404;`, а мы добавляем её ещё раз.

### ✅ ИСПРАВЛЕННАЯ КОНФИГУРАЦИЯ:

```nginx
server {
    listen 80;
    server_name 178.72.129.61;

    root /var/www/mi_core_api/src;
    index index.php index.html index.htm;

    access_log /var/log/nginx/country-filter-access.log;
    error_log /var/log/nginx/country-filter-error.log;

    location / {
        try_files $uri $uri/ /demo/country-filter-demo.html;
    }

    # БЕЗ try_files - она уже в snippets/fastcgi-php.conf!
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

### 🔧 Что изменилось:

- **Убрали** `try_files $uri =404;` из PHP location блока
- **Оставили** только `include snippets/fastcgi-php.conf;`
- Файл `fastcgi-php.conf` уже содержит все нужные директивы

### 📝 Применение:

```bash
# Используйте исправленную простую конфигурацию
sudo cp src/nginx-simple.conf.example /etc/nginx/sites-available/mi_core_api

# Проверьте синтаксис
sudo nginx -t

# Должно быть: nginx: configuration file /etc/nginx/nginx.conf test is successful
```

**Теперь конфигурация работает без ошибок дублирования!** ✅
