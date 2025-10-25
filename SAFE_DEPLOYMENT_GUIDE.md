# 🛡️ Пошаговое руководство по безопасному деплою

## 📋 Подготовка к деплою

### Шаг 1: Подключение к серверу

```bash
ssh vladimir@your-server.com
# или
ssh root@your-server.com
```

### Шаг 2: Переход в директорию проекта

```bash
cd /var/www/html/mi_core_etl
# Проверяем текущее состояние
pwd
ls -la
```

### Шаг 3: Проверка текущих локальных изменений

```bash
# Проверяем статус git
git status

# Если есть изменения, посмотрим что изменено
git diff
```

## 🚀 Процесс безопасного деплоя

### Вариант 1: Автоматический безопасный деплой

```bash
# Скачиваем и запускаем безопасный скрипт
sudo ./safe_deploy.sh
```

### Вариант 2: Ручной пошаговый деплой

#### Шаг 1: Сохранение локальных изменений

```bash
# Если есть локальные изменения, сохраняем их
sudo chown -R vladimir:vladimir /var/www/html/mi_core_etl
cd /var/www/html/mi_core_etl

# Проверяем что изменено
git status
git diff

# Сохраняем изменения в stash
git stash push -m "Local changes before deployment $(date)"

# Проверяем что stash создался
git stash list
```

#### Шаг 2: Обновление кода

```bash
# Получаем последние изменения
git fetch origin main

# Смотрим что будет обновлено
git log --oneline HEAD..origin/main

# Выполняем pull
git pull origin main
```

#### Шаг 3: Обработка конфликтов (если есть)

```bash
# Если возникли конфликты при pull
git status

# Применяем stash обратно (может вызвать конфликты)
git stash pop

# Если есть конфликты, решаем их
git status
# Редактируем файлы с конфликтами
# Добавляем решенные файлы
git add .
git commit -m "Resolve merge conflicts after deployment"
```

#### Шаг 4: Проверка и применение миграций

```bash
# Проверяем новые миграции
ls -la migrations/

# Если есть новые .sql файлы, применяем их
# (добавьте свою команду для миграций)
# php run_migrations.php
```

#### Шаг 5: Обновление зависимостей

```bash
# Проверяем изменения в composer.json
git diff HEAD~1 HEAD composer.json composer.lock

# Если есть изменения, обновляем
composer install --no-dev --optimize-autoloader
```

#### Шаг 6: Сборка frontend (если нужно)

```bash
# Проверяем изменения в frontend
git diff HEAD~1 HEAD --name-only | grep "^frontend/"

# Если есть изменения
cd frontend
npm ci  # если изменился package-lock.json
npm run build
cd ..
```

#### Шаг 7: Установка прав доступа

```bash
# Создаем необходимые директории
mkdir -p logs/analytics_etl cache/analytics_api storage/temp

# Устанавливаем права на исполнение
chmod +x warehouse_etl_analytics.php
chmod +x analytics_etl_smoke_tests.php
chmod +x monitor_analytics_etl.php
chmod +x run_alert_manager.php
chmod +x scripts/*.php
chmod +x migrations/*.sh

# Возвращаем владельца веб-серверу
sudo chown -R www-data:www-data /var/www/html/mi_core_etl

# Оставляем доступ vladimir к логам и кэшу
sudo chown -R vladimir:www-data logs/ cache/ storage/
sudo chmod -R g+w logs/ cache/ storage/
```

#### Шаг 8: Перезапуск сервисов

```bash
# Перезапускаем веб-сервер
sudo systemctl reload nginx
# или
sudo systemctl reload apache2

# Перезапускаем PHP-FPM
sudo systemctl reload php8.1-fpm
# или
sudo systemctl reload php8.0-fpm
```

#### Шаг 9: Проверка работоспособности

```bash
# Запускаем smoke tests
php analytics_etl_smoke_tests.php

# Проверяем веб-сайт
curl -I http://your-domain.com

# Проверяем API
curl http://your-domain.com/api/health

# Проверяем логи
tail -f logs/analytics_etl/etl_$(date +%Y%m%d).log
```

## 🔍 Проверка после деплоя

### 1. Проверка файлов и прав

```bash
# Проверяем владельцев файлов
ls -la /var/www/html/mi_core_etl/
ls -la /var/www/html/mi_core_etl/logs/
ls -la /var/www/html/mi_core_etl/cache/

# Проверяем git статус
cd /var/www/html/mi_core_etl
sudo -u vladimir git status
sudo -u vladimir git log --oneline -5
```

### 2. Проверка сервисов

```bash
# Проверяем статус веб-сервера
sudo systemctl status nginx
# или
sudo systemctl status apache2

# Проверяем PHP-FPM
sudo systemctl status php8.1-fpm

# Проверяем логи ошибок
sudo tail -f /var/log/nginx/error.log
# или
sudo tail -f /var/log/apache2/error.log
```

### 3. Проверка приложения

```bash
# Тестируем подключение к БД
php -r "
require_once 'config/database.php';
try {
    \$pdo = new PDO(\$dsn, \$username, \$password);
    echo 'Database connection: OK\n';
} catch (Exception \$e) {
    echo 'Database error: ' . \$e->getMessage() . '\n';
}
"

# Проверяем ETL систему
php monitor_analytics_etl.php

# Проверяем cron задачи
crontab -l
```

## 🚨 Устранение проблем

### Проблема: Конфликты при git pull

```bash
# Отменяем pull
git merge --abort

# Смотрим что конфликтует
git stash show -p

# Решаем конфликты вручную или сбрасываем локальные изменения
git reset --hard HEAD
git clean -fd

# Повторяем pull
git pull origin main
```

### Проблема: Права доступа

```bash
# Сбрасываем все права
sudo chown -R www-data:www-data /var/www/html/mi_core_etl/
sudo chown -R vladimir:www-data /var/www/html/mi_core_etl/logs/
sudo chown -R vladimir:www-data /var/www/html/mi_core_etl/cache/
sudo chown -R vladimir:www-data /var/www/html/mi_core_etl/storage/
sudo chmod -R g+w /var/www/html/mi_core_etl/logs/
sudo chmod -R g+w /var/www/html/mi_core_etl/cache/
sudo chmod -R g+w /var/www/html/mi_core_etl/storage/
```

### Проблема: Веб-сервер не работает

```bash
# Проверяем конфигурацию
sudo nginx -t
# или
sudo apache2ctl configtest

# Перезапускаем сервис
sudo systemctl restart nginx
# или
sudo systemctl restart apache2

# Проверяем логи
sudo journalctl -u nginx -f
# или
sudo journalctl -u apache2 -f
```

## 📝 Работа с stash (сохраненными изменениями)

### Просмотр сохраненных изменений

```bash
# Список всех stash
sudo -u vladimir git stash list

# Просмотр содержимого последнего stash
sudo -u vladimir git stash show -p

# Просмотр конкретного stash
sudo -u vladimir git stash show -p stash@{0}
```

### Применение сохраненных изменений

```bash
# Применить и удалить последний stash
sudo -u vladimir git stash pop

# Применить без удаления
sudo -u vladimir git stash apply

# Применить конкретный stash
sudo -u vladimir git stash apply stash@{0}
```

### Удаление сохраненных изменений

```bash
# Удалить последний stash
sudo -u vladimir git stash drop

# Удалить конкретный stash
sudo -u vladimir git stash drop stash@{0}

# Удалить все stash
sudo -u vladimir git stash clear
```

## ✅ Чек-лист успешного деплоя

-   [ ] Локальные изменения сохранены в stash
-   [ ] Git pull выполнен успешно
-   [ ] Миграции БД применены (если есть)
-   [ ] Composer зависимости обновлены
-   [ ] Frontend собран (если изменялся)
-   [ ] Права доступа установлены корректно
-   [ ] Веб-сервер и PHP-FPM перезапущены
-   [ ] Smoke tests прошли успешно
-   [ ] Сайт доступен и работает
-   [ ] ETL система функционирует
-   [ ] Логи не содержат критических ошибок

## 🔄 Откат в случае проблем

```bash
# Быстрый откат к предыдущему коммиту
sudo chown -R vladimir:vladimir /var/www/html/mi_core_etl
cd /var/www/html/mi_core_etl
sudo -u vladimir git log --oneline -5
sudo -u vladimir git reset --hard PREVIOUS_COMMIT_HASH
sudo chown -R www-data:www-data /var/www/html/mi_core_etl
sudo systemctl reload nginx
```

---

**Помните**: Всегда тестируйте деплой на staging окружении перед продакшеном!
