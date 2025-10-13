# Решение проблемы с API inventory-analytics.php

## 🔍 Диагностика проблемы

Проблема заключается в том, что файл `inventory-analytics.php` отсутствует на сервере по пути `/var/www/mi_core_api/api/inventory-analytics.php`.

## ✅ Решение

### 1. Загрузите файл на сервер

Скопируйте файл `api/inventory-analytics.php` из локального проекта на сервер:

```bash
# На локальной машине
scp api/inventory-analytics.php user@your-server:/var/www/mi_core_api/api/

# Или используйте rsync
rsync -av api/ user@your-server:/var/www/mi_core_api/api/
```

### 2. Проверьте структуру каталогов на сервере

```bash
# Подключитесь к серверу
ssh user@your-server

# Проверьте структуру
ls -la /var/www/mi_core_api/
ls -la /var/www/mi_core_api/api/

# Создайте каталог api если его нет
sudo mkdir -p /var/www/mi_core_api/api
```

### 3. Установите правильные права доступа

```bash
# Установите владельца
sudo chown -R www-data:www-data /var/www/mi_core_api/api/

# Установите права
sudo chmod -R 644 /var/www/mi_core_api/api/*.php
sudo chmod 755 /var/www/mi_core_api/api/
```

### 4. Проверьте конфигурацию Nginx

Убедитесь, что в конфигурации Nginx правильно настроен путь к файлам:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/mi_core_api;
    index index.php index.html;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Проверьте файл config.php

Убедитесь, что на сервере есть файл `config.php` с правильными настройками базы данных:

```bash
# Проверьте наличие файла
ls -la /var/www/mi_core_api/config.php

# Проверьте содержимое (без паролей)
grep -v "PASSWORD" /var/www/mi_core_api/config.php
```

### 6. Проверьте .env файл

```bash
# Проверьте наличие .env файла
ls -la /var/www/mi_core_api/.env

# Убедитесь, что в нем есть настройки БД
grep "DB_" /var/www/mi_core_api/.env
```

### 7. Тестирование API

После загрузки файлов протестируйте API:

```bash
# Тест подключения к базе данных
php /var/www/mi_core_api/config.php

# Тест API напрямую
cd /var/www/mi_core_api
php -r "
\$_GET['action'] = 'dashboard';
include 'api/inventory-analytics.php';
"

# Тест через curl
curl "http://127.0.0.1/api/inventory-analytics.php?action=dashboard"
```

## 🚀 Локальное тестирование

Для тестирования на локальной машине:

1. **Запустите тестовый сервер:**

   ```bash
   php start_test_server.php
   ```

2. **Откройте в браузере:**

   - Дашборд: http://127.0.0.1:8080/test_dashboard.html
   - API: http://127.0.0.1:8080/api/inventory-analytics.php?action=dashboard

3. **Или запустите консольный тест:**
   ```bash
   php test_inventory_api_clean.php
   ```

## 📋 Проверочный список

- [ ] Файл `api/inventory-analytics.php` существует на сервере
- [ ] Файл `config.php` существует и содержит правильные настройки
- [ ] Файл `.env` содержит настройки базы данных
- [ ] Права доступа установлены корректно (644 для файлов, 755 для папок)
- [ ] Владелец файлов - www-data
- [ ] Nginx/Apache правильно настроен для обработки PHP
- [ ] PHP-FPM запущен и работает
- [ ] База данных доступна и содержит данные в таблице `inventory_data`

## 🔧 Дополнительные файлы для загрузки

Если на сервере отсутствуют другие необходимые файлы, загрузите также:

- `api/inventory_cache_manager.php` (если используется кэширование)
- Все файлы из папки `src/` (если API их использует)
- Файлы миграций базы данных

## 📞 Поддержка

Если проблема не решается:

1. Проверьте логи ошибок:

   ```bash
   sudo tail -f /var/log/nginx/error.log
   sudo tail -f /var/log/php8.1-fpm.log
   ```

2. Включите отображение ошибок PHP временно:

   ```php
   <?php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);

   $_GET['action'] = 'dashboard';
   include 'api/inventory-analytics.php';
   ?>
   ```

3. Проверьте подключение к базе данных отдельно:
   ```php
   <?php
   require_once 'config.php';
   try {
       $pdo = getDatabaseConnection();
       echo "✅ Подключение к БД успешно!\n";
   } catch (Exception $e) {
       echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
   }
   ?>
   ```
