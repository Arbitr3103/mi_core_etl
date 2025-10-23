# ⚡ Быстрые команды для развертывания

## 🚀 Автоматическое развертывание (одна команда)

```bash
./deployment/upload_frontend.sh && \
scp deployment/final_deployment.sh vladimir@178.72.129.61:/tmp/ && \
ssh vladimir@178.72.129.61 "chmod +x /tmp/final_deployment.sh && sudo /tmp/final_deployment.sh"
```

---

## 📦 Пошаговое развертывание

### Шаг 1: Загрузить frontend

```bash
./deployment/upload_frontend.sh
```

### Шаг 2: Настроить Nginx

```bash
ssh vladimir@178.72.129.61

# Создать конфиг
sudo tee /etc/nginx/sites-available/mi_core_new > /dev/null <<'EOF'
server {
    listen 8080;
    server_name 178.72.129.61;
    root /var/www/mi_core_etl_new/public;
    index index.php index.html;

    access_log /var/log/nginx/mi_core_new_access.log;
    error_log /var/log/nginx/mi_core_new_error.log;

    location / {
        try_files $uri $uri/ /build/index.html;
    }

    location /api {
        try_files $uri $uri/ /api/index.php?$query_string;
        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    location ~ /\.env { deny all; }
    location ~ /\.git { deny all; }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
EOF

# Активировать
sudo ln -s /etc/nginx/sites-available/mi_core_new /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Шаг 3: Тестировать

```bash
# На сервере
curl http://localhost:8080/api/health
curl -I http://localhost:8080/

# В браузере
# http://178.72.129.61:8080
```

### Шаг 4: Переключить на production

```bash
# После успешного тестирования
sudo sed -i 's/listen 8080;/listen 80;/' /etc/nginx/sites-available/mi_core_new
sudo nginx -t
sudo systemctl reload nginx
```

---

## 🔍 Проверка и отладка

### Проверить статус

```bash
# Nginx
sudo systemctl status nginx
sudo nginx -t

# PHP-FPM
sudo systemctl status php8.1-fpm

# PostgreSQL
sudo systemctl status postgresql
```

### Проверить логи

```bash
# Nginx ошибки
sudo tail -f /var/log/nginx/mi_core_new_error.log

# Nginx доступ
sudo tail -f /var/log/nginx/mi_core_new_access.log

# PHP-FPM
sudo tail -f /var/log/php8.1-fpm.log

# PostgreSQL
sudo tail -f /var/log/postgresql/postgresql-14-main.log
```

### Проверить файлы

```bash
# Frontend файлы
ls -la /var/www/mi_core_etl_new/public/build/

# Права доступа
ls -la /var/www/mi_core_etl_new/

# .env файл
cat /var/www/mi_core_etl_new/.env | grep -v PASSWORD
```

### Проверить подключение к БД

```bash
cd /var/www/mi_core_etl_new
php -r "require 'vendor/autoload.php'; echo 'OK';"
```

---

## 🛠 Исправление проблем

### Frontend не загружается

```bash
# Проверить файлы
ls -la /var/www/mi_core_etl_new/public/build/

# Установить права
cd /var/www/mi_core_etl_new
sudo chown -R www-data:www-data public/build/
sudo chmod -R 755 public/build/

# Перезагрузить Nginx
sudo systemctl reload nginx
```

### API не отвечает

```bash
# Проверить PHP-FPM
sudo systemctl restart php8.1-fpm

# Проверить права
sudo chown -R www-data:www-data /var/www/mi_core_etl_new
sudo chmod -R 775 /var/www/mi_core_etl_new/storage/

# Проверить .env
cat /var/www/mi_core_etl_new/.env
```

### Nginx ошибки

```bash
# Проверить конфигурацию
sudo nginx -t

# Проверить синтаксис
cat /etc/nginx/sites-available/mi_core_new

# Перезапустить
sudo systemctl restart nginx
```

---

## 📊 Мониторинг

### Проверить производительность

```bash
# Использование CPU и памяти
top

# Использование диска
df -h

# Процессы Nginx
ps aux | grep nginx

# Процессы PHP-FPM
ps aux | grep php-fpm

# Подключения к PostgreSQL
sudo -u postgres psql -c "SELECT count(*) FROM pg_stat_activity;"
```

### Проверить доступность

```bash
# API health
curl http://localhost:8080/api/health

# Frontend
curl -I http://localhost:8080/

# Время ответа
time curl -s http://localhost:8080/api/health > /dev/null
```

---

## 🔄 Обновление frontend

Если нужно обновить frontend после изменений:

```bash
# На локальном компьютере
cd frontend
npm run build
./deployment/upload_frontend.sh

# Или вручную
cd frontend
tar -czf ../frontend-build.tar.gz dist/
scp ../frontend-build.tar.gz vladimir@178.72.129.61:/tmp/

# На сервере
ssh vladimir@178.72.129.61
cd /var/www/mi_core_etl_new/public/build
sudo rm -rf *
sudo tar -xzf /tmp/frontend-build.tar.gz --strip-components=1
sudo chown -R www-data:www-data .
```

---

## 🗄 Бэкапы

### Создать бэкап БД

```bash
# На сервере
sudo -u postgres pg_dump mi_core_db > /backup/mi_core_db_$(date +%Y%m%d_%H%M%S).sql
```

### Создать бэкап кода

```bash
# На сервере
cd /var/www
sudo tar -czf /backup/mi_core_etl_new_$(date +%Y%m%d_%H%M%S).tar.gz mi_core_etl_new/
```

### Восстановить из бэкапа

```bash
# БД
sudo -u postgres psql mi_core_db < /backup/mi_core_db_YYYYMMDD_HHMMSS.sql

# Код
cd /var/www
sudo tar -xzf /backup/mi_core_etl_new_YYYYMMDD_HHMMSS.tar.gz
```

---

## 🔐 Безопасность

### Проверить права доступа

```bash
# Проверить владельца
ls -la /var/www/mi_core_etl_new/

# Проверить .env
ls -la /var/www/mi_core_etl_new/.env

# Должно быть:
# drwxr-xr-x www-data www-data /var/www/mi_core_etl_new/
# -rw------- www-data www-data .env
```

### Обновить пароли

```bash
# PostgreSQL
sudo -u postgres psql
ALTER USER mi_core_user WITH PASSWORD 'новый_пароль';

# Обновить .env
sudo nano /var/www/mi_core_etl_new/.env
```

---

## 📞 Полезные команды

### SSH подключение

```bash
ssh vladimir@178.72.129.61
```

### Копирование файлов

```bash
# На сервер
scp local_file vladimir@178.72.129.61:/remote/path/

# С сервера
scp vladimir@178.72.129.61:/remote/path/file local_path/
```

### Просмотр процессов

```bash
# Все процессы
ps aux

# Nginx
ps aux | grep nginx

# PHP-FPM
ps aux | grep php-fpm

# PostgreSQL
ps aux | grep postgres
```

---

**Быстрая справка всегда под рукой!** 📋
