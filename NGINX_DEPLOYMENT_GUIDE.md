# Nginx Configuration Deployment Guide

## Проблема

Nginx имел два конфига (`default` и `mi_core_api`), оба слушали на порту 80 с одинаковым `server_name 178.72.129.61`. Конфиг `mi_core_api` перехватывал все запросы и смотрел в `/var/www/mi_core_api/src/` вместо `/var/www/html/`, из-за чего все дашборды и API возвращали 404.

## Решение

Разделили конфигурации по доменам:

### 1. `default` - Основной сервер

- **Домены:** `178.72.129.61`, `api.zavodprostavok.ru`, `zavodprostavok.ru`
- **Root:** `/var/www/html/`
- **Назначение:** Все дашборды, API endpoints, мониторинг

### 2. `mi_core_api` - Country Filter API

- **Домен:** `filter.zavodprostavok.ru`
- **Root:** `/var/www/mi_core_api/src/`
- **Назначение:** Отдельный API для фильтрации по странам

## Деплой

### Шаг 1: Подготовка (на локальной машине)

```bash
# Убедись что файлы созданы
ls -la nginx/
ls -la deploy_nginx_config.sh

# Сделай скрипт исполняемым
chmod +x deploy_nginx_config.sh

# Закоммить изменения
git add nginx/ deploy_nginx_config.sh NGINX_DEPLOYMENT_GUIDE.md
git commit -m "fix: nginx configuration - separate domains for default and mi_core_api"
git push
```

### Шаг 2: Деплой на сервер

```bash
# На сервере
cd /var/www/mi_core_api

# Получи последние изменения
git pull

# Запусти деплой скрипт
sudo ./deploy_nginx_config.sh
```

### Шаг 3: Проверка

```bash
# Проверь что основной сайт работает
curl http://178.72.129.61/test.php

# Проверь дашборд
curl -I http://178.72.129.61/quality_dashboard.php

# Проверь API
curl http://178.72.129.61/api/quality-metrics.php

# Проверь в браузере
# http://178.72.129.61/quality_dashboard.php
```

## Настройка DNS (опционально)

Если хочешь использовать домены вместо IP:

1. В панели управления DNS добавь A-записи:

   ```
   api.zavodprostavok.ru      → 178.72.129.61
   filter.zavodprostavok.ru   → 178.72.129.61
   ```

2. После настройки DNS проверь:
   ```bash
   curl http://api.zavodprostavok.ru/quality_dashboard.php
   curl http://filter.zavodprostavok.ru/
   ```

## Откат (если что-то пошло не так)

Скрипт автоматически создаёт бэкапы в `/etc/nginx/backups/`. Для отката:

```bash
# Найди последний бэкап
ls -la /etc/nginx/backups/

# Восстанови конфиги
sudo cp /etc/nginx/backups/YYYYMMDD_HHMMSS/default /etc/nginx/sites-available/default
sudo cp /etc/nginx/backups/YYYYMMDD_HHMMSS/mi_core_api /etc/nginx/sites-available/mi_core_api

# Проверь и перезагрузи
sudo nginx -t
sudo systemctl reload nginx
```

## Проверка конфигурации

```bash
# Проверь синтаксис
sudo nginx -t

# Посмотри какие конфиги активны
ls -la /etc/nginx/sites-enabled/

# Посмотри логи
sudo tail -f /var/log/nginx/mdm-access.log
sudo tail -f /var/log/nginx/mdm-error.log
```

## Что изменилось

### До:

- Оба конфига слушали `178.72.129.61:80`
- `mi_core_api` перехватывал все запросы
- Root был `/var/www/mi_core_api/src/`
- Все файлы из `/var/www/html/` были недоступны

### После:

- `default` слушает `178.72.129.61` (и домены)
- `mi_core_api` слушает только `filter.zavodprostavok.ru`
- Root для основного сайта: `/var/www/html/`
- Root для filter API: `/var/www/mi_core_api/src/`
- Каждый конфиг обслуживает свои файлы

## Результат

✅ Дашборды доступны: `http://178.72.129.61/quality_dashboard.php`
✅ API работает: `http://178.72.129.61/api/quality-metrics.php`
✅ Country Filter API на отдельном домене (когда настроишь DNS)
