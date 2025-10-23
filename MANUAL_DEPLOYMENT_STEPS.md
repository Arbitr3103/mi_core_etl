# Ручные шаги развертывания на сервере

## ✅ Что уже сделано:

1. ✅ Frontend собран локально (840 KB)
2. ✅ Frontend загружен на сервер в `/var/www/mi_core_etl_new/public/build/`
3. ✅ Nginx конфиг создан и загружен в `/tmp/nginx_mi_core_new.conf`

## 🔧 Что нужно сделать на сервере:

### Шаг 1: Подключитесь к серверу

```bash
ssh vladimir@178.72.129.61
```

### Шаг 2: Настройте Nginx

```bash
# Скопируйте конфиг
sudo cp /tmp/nginx_mi_core_new.conf /etc/nginx/sites-available/mi_core_new

# Создайте symlink
sudo ln -sf /etc/nginx/sites-available/mi_core_new /etc/nginx/sites-enabled/mi_core_new

# Проверьте конфигурацию
sudo nginx -t

# Если проверка успешна, перезагрузите Nginx
sudo systemctl reload nginx

# Проверьте статус
sudo systemctl status nginx
```

### Шаг 3: Установите права доступа (опционально, если нужно)

```bash
cd /var/www/mi_core_etl_new

# Установите владельца (если требуется)
sudo chown -R www-data:www-data public/build/

# Установите права
sudo chmod -R 755 public/build/
```

### Шаг 4: Проверьте API

```bash
# Health endpoint
curl http://localhost:8080/api/health

# Inventory endpoint
curl http://localhost:8080/api/inventory-v4.php?limit=5
```

### Шаг 5: Проверьте Frontend

```bash
# Проверьте что index.html доступен
curl -I http://localhost:8080/

# Проверьте в браузере
# http://178.72.129.61:8080
```

### Шаг 6: Проверьте логи

```bash
# Nginx error log
sudo tail -50 /var/log/nginx/mi_core_new_error.log

# Nginx access log
sudo tail -50 /var/log/nginx/mi_core_new_access.log

# PHP-FPM log (если есть ошибки)
sudo tail -50 /var/log/php8.1-fpm.log
```

---

## 🔍 Troubleshooting

### Если Frontend не загружается:

```bash
# Проверьте файлы
ls -la /var/www/mi_core_etl_new/public/build/

# Проверьте права
ls -la /var/www/mi_core_etl_new/public/

# Проверьте Nginx конфиг
cat /etc/nginx/sites-available/mi_core_new

# Проверьте логи
sudo tail -100 /var/log/nginx/mi_core_new_error.log
```

### Если API не отвечает:

```bash
# Проверьте PHP-FPM
sudo systemctl status php8.1-fpm

# Проверьте что API файлы существуют
ls -la /var/www/mi_core_etl_new/public/api/

# Проверьте .env
cat /var/www/mi_core_etl_new/.env | grep -v PASSWORD

# Проверьте подключение к БД
cd /var/www/mi_core_etl_new
php -r "require 'vendor/autoload.php'; echo 'OK';"
```

### Если Nginx не запускается:

```bash
# Проверьте синтаксис конфига
sudo nginx -t

# Проверьте что порт 8080 свободен
sudo netstat -tulpn | grep 8080

# Проверьте логи Nginx
sudo tail -100 /var/log/nginx/error.log

# Перезапустите Nginx
sudo systemctl restart nginx
```

---

## 📊 После успешного развертывания

### Проверьте что все работает:

1. **Frontend**: http://178.72.129.61:8080

    - Должен загрузиться React дашборд
    - Должны отображаться данные

2. **API Health**: http://178.72.129.61:8080/api/health

    - Должен вернуть JSON с статусом

3. **API Inventory**: http://178.72.129.61:8080/api/inventory-v4.php?limit=5
    - Должен вернуть JSON с данными продуктов

### Если все работает на порту 8080:

```bash
# Переключите на порт 80 (production)
sudo sed -i 's/listen 8080;/listen 80;/' /etc/nginx/sites-available/mi_core_new
sudo nginx -t
sudo systemctl reload nginx
```

Теперь приложение доступно на: http://178.72.129.61

---

## ✅ Чеклист

-   [ ] Nginx конфиг скопирован
-   [ ] Symlink создан
-   [ ] Nginx перезагружен
-   [ ] Frontend доступен на :8080
-   [ ] API health отвечает
-   [ ] API inventory возвращает данные
-   [ ] Нет ошибок в логах
-   [ ] Переключено на порт 80 (опционально)

---

**Следующий шаг**: Выполните команды выше на сервере и сообщите о результатах!
