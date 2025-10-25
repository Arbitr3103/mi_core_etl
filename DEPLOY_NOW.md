# 🚀 ВЫПОЛНИТЕ ЭТИ КОМАНДЫ НА СЕРВЕРЕ

## Шаг 1: Подключение к серверу

```bash
ssh vladimir@your-server.com
# или используйте ваш способ подключения
```

## Шаг 2: Переход в директорию проекта

```bash
cd /var/www/html/mi_core_etl
# или ваш путь к проекту
```

## Шаг 3: Получение последних изменений (включая скрипты деплоя)

```bash
# Временно меняем владельца для git операций
sudo chown -R vladimir:vladimir .

# Получаем последние изменения с новыми скриптами
git pull origin main

# Делаем скрипты исполняемыми
chmod +x *.sh

# Возвращаем владельца
sudo chown -R www-data:www-data .
sudo chown -R vladimir:www-data logs/ cache/ storage/ 2>/dev/null || true
```

## Шаг 4: Настройка скриптов под ваш сервер

```bash
# Запускаем конфигуратор
./configure_deployment.sh

# Введите ваши настройки:
# - Project directory: /var/www/html/mi_core_etl (или ваш путь)
# - Maintenance user: vladimir (или ваш пользователь)
# - Web server user: www-data (или nginx/apache)
# - Web server group: www-data (или nginx/apache)
```

## Шаг 5: Запуск безопасного деплоя

```bash
# Запускаем автоматический безопасный деплой
sudo ./safe_deploy.sh
```

## Что произойдет автоматически:

1. ✅ **Проверка локальных изменений** - если есть, они будут сохранены в git stash
2. ✅ **Git pull** - получение последних изменений из репозитория
3. ✅ **Миграции БД** - автоматическое применение новых миграций
4. ✅ **Composer** - обновление PHP зависимостей при необходимости
5. ✅ **Frontend build** - сборка React/TypeScript при изменениях
6. ✅ **Права доступа** - корректная настройка владельцев файлов
7. ✅ **Перезапуск сервисов** - nginx/apache и PHP-FPM
8. ✅ **Smoke tests** - проверка работоспособности системы

## После деплоя проверьте:

```bash
# Проверка сайта
curl -I http://your-domain.com

# Проверка API
curl http://your-domain.com/api/health

# Проверка ETL системы
php monitor_analytics_etl.php

# Проверка логов
tail -f logs/analytics_etl/etl_$(date +%Y%m%d).log
```

## Если были локальные изменения:

Скрипт автоматически сохранит их в git stash. После деплоя вы можете:

```bash
# Посмотреть сохраненные изменения
sudo -u vladimir git stash show -p

# Применить их обратно (если нужно)
sudo -u vladimir git stash pop

# Или удалить, если не нужны
sudo -u vladimir git stash drop
```

## В случае проблем:

```bash
# Быстрый откат к предыдущему коммиту
sudo chown -R vladimir:vladimir .
sudo -u vladimir git log --oneline -5
sudo -u vladimir git reset --hard PREVIOUS_COMMIT_HASH
sudo chown -R www-data:www-data .
sudo systemctl reload nginx
```

---

## 🎯 Готовы? Выполните команды выше на сервере!

**Важно**: Убедитесь, что у вас есть доступ к серверу под пользователем vladimir (или вашим maintenance пользователем) и права sudo.
