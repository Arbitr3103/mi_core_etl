# ⚡ Быстрое развертывание на продакшене

## 🚀 Автоматическое развертывание (рекомендуется)

### На продакшен сервере:

```bash
# 1. Подключитесь к серверу
ssh user@your-production-server.com

# 2. Перейдите в директорию проекта
cd /path/to/mi_core_etl

# 3. Запустите автоматическое развертывание
./scripts/deploy_to_production.sh

# Или с дополнительными опциями:
./scripts/deploy_to_production.sh --url=https://your-domain.com --force
```

## 📋 Ручное развертывание (если нужен контроль)

### Шаг 1: Обновление кода

```bash
git pull origin main
```

### Шаг 2: Применение индексов БД

```bash
php -r "
require_once 'config.php';
\$pdo = getDatabaseConnection();
\$sql = file_get_contents('sql/create_inventory_dashboard_indexes.sql');
\$pdo->exec(\$sql);
echo 'Индексы созданы\n';
"
```

### Шаг 3: Настройка кэша

```bash
mkdir -p cache/inventory
chmod 755 cache/inventory
php scripts/manage_inventory_cache.php warmup
```

### Шаг 4: Перезапуск веб-сервера

```bash
sudo systemctl reload apache2  # или nginx
```

### Шаг 5: Проверка

```bash
curl "http://your-domain.com/api/inventory-analytics.php?action=dashboard"
```

## ✅ Проверка результата

После развертывания проверьте:

1. **Дашборд работает:** `http://your-domain.com/html/inventory_marketing_dashboard.php`
2. **API быстро отвечает:** `< 100ms` для всех endpoints
3. **Кэш активен:** `php scripts/manage_inventory_cache.php status`
4. **Нет ошибок в логах:** `tail -f logs/inventory_api_errors.log`

## 🔧 Мониторинг

```bash
# Статус кэша
php scripts/manage_inventory_cache.php status

# Производительность
php scripts/monitor_inventory_performance.php

# Очистка кэша (при необходимости)
php scripts/manage_inventory_cache.php clean
```

## 🚨 Откат (если что-то пошло не так)

```bash
# Восстановите из резервной копии
cp -r backups/production_deployment_*/api/* api/
sudo systemctl reload apache2
```

---

**Время развертывания:** ~5 минут  
**Ожидаемое улучшение:** 200x ускорение для кэшированных запросов
