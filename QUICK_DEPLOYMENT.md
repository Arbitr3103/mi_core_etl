# 🚀 Быстрое развертывание системы фильтрации по странам

## ⚡ Решение проблемы с правами доступа

**Проблема решена!** Все веб-файлы перемещены в папку `src/` для правильного развертывания в стандартной веб-директории `/var/www/html/`.

---

## 🎯 Развертывание на сервере (Ubuntu/Debian)

### 1. Подготовка сервера

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Установка необходимых пакетов
sudo apt install nginx php8.0-fpm php8.0-mysql mysql-server git -y

# Запуск сервисов
sudo systemctl start nginx php8.0-fpm mysql
sudo systemctl enable nginx php8.0-fpm mysql
```

### 2. Клонирование и развертывание

```bash
# Клонирование репозитория
cd /tmp
git clone https://github.com/Arbitr3103/mi_core_etl.git
cd mi_core_etl

# Копирование в веб-директорию
sudo cp -r src/ /var/www/html/
sudo chown -R www-data:www-data /var/www/html/src/
sudo chmod -R 755 /var/www/html/src/

# Создание символической ссылки для удобства
sudo ln -sf /var/www/html/src /var/www/html/country-filter
```

### 3. Настройка Nginx

```bash
# Создание конфигурации сайта
sudo cp /var/www/html/src/nginx.conf.example /etc/nginx/sites-available/country-filter

# Редактирование конфигурации (замените your-domain.com на ваш домен)
sudo nano /etc/nginx/sites-available/country-filter

# Активация сайта
sudo ln -s /etc/nginx/sites-available/country-filter /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 4. Настройка базы данных

```bash
# Подключение к MySQL
sudo mysql -u root -p

# Создание базы данных и пользователя (в MySQL консоли)
CREATE DATABASE country_filter_db;
CREATE USER 'country_filter'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON country_filter_db.* TO 'country_filter'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Создание индексов для оптимизации
mysql -u country_filter -p country_filter_db < /tmp/mi_core_etl/create_country_filter_indexes.sql
```

### 5. Настройка API

```bash
# Редактирование конфигурации API
sudo nano /var/www/html/src/CountryFilterAPI.php

# Измените параметры подключения к БД:
# private $host = 'localhost';
# private $dbname = 'country_filter_db';
# private $username = 'country_filter';
# private $password = 'secure_password';
```

### 6. Тестирование

```bash
# Тестирование API
cd /var/www/html/src
php test_country_filter_api.php

# Тестирование производительности
php test_country_filter_performance.php

# Проверка через браузер
curl http://your-domain.com/src/api/countries.php
```

---

## 🔧 Альтернативная настройка для Apache

### 1. Установка Apache

```bash
sudo apt install apache2 php8.0 libapache2-mod-php8.0 -y
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 2. Настройка виртуального хоста

```bash
# Создание конфигурации
sudo nano /etc/apache2/sites-available/country-filter.conf

# Содержимое файла:
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/html/src

    <Directory /var/www/html/src>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/country-filter-error.log
    CustomLog ${APACHE_LOG_DIR}/country-filter-access.log combined
</VirtualHost>

# Активация сайта
sudo a2ensite country-filter.conf
sudo systemctl reload apache2
```

---

## 🌐 Проверка работоспособности

### Демо страницы:

- **Основная демо**: `http://your-domain.com/src/demo/country-filter-demo.html`
- **Мобильная демо**: `http://your-domain.com/src/demo/mobile-country-filter-demo.html`

### API endpoints:

- **Все страны**: `http://your-domain.com/src/api/countries.php`
- **По марке**: `http://your-domain.com/src/api/countries-by-brand.php?brand_id=1`
- **По модели**: `http://your-domain.com/src/api/countries-by-model.php?model_id=1`
- **Фильтрация**: `http://your-domain.com/src/api/products-filter.php?country_id=1`

---

## 🔒 Безопасность и оптимизация

### SSL сертификат (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d your-domain.com
```

### Настройка файрвола

```bash
sudo ufw allow 'Nginx Full'
sudo ufw allow ssh
sudo ufw enable
```

### Мониторинг логов

```bash
# Nginx логи
sudo tail -f /var/log/nginx/country-filter-access.log
sudo tail -f /var/log/nginx/country-filter-error.log

# PHP логи
sudo tail -f /var/log/php8.0-fpm.log
```

---

## 🎉 Готово!

**Система фильтрации по стране изготовления успешно развернута!**

### ✅ Что работает:

- API endpoints для получения стран и фильтрации
- Демо страницы для тестирования
- Мобильная адаптация
- Оптимизация производительности
- Безопасность и защита

### 🔗 Следующие шаги:

1. Интегрируйте фильтр в основное приложение
2. Настройте мониторинг производительности
3. Добавьте резервное копирование
4. Протестируйте под нагрузкой

**🚀 Система готова к использованию в продакшене!**
