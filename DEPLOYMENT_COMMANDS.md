# Команды для деплоя обновления дашборда

## Автоматический деплой (рекомендуется)

```bash
# На сервере выполните:
cd /var/www/mi_core_api
./deploy_dashboard_update.sh
```

## Ручной деплой (пошагово)

Если нужно выполнить деплой вручную:

```bash
# 1. Переходим в рабочую директорию
cd /var/www/mi_core_api

# 2. Создаем резервную копию
cp dashboard_inventory_v4.php dashboard_inventory_v4.php.backup.$(date +%Y%m%d_%H%M%S)

# 3. Временно передаем права vladimir
sudo chown -R vladimir:vladimir .

# 4. Получаем изменения
git pull origin main

# 5. Возвращаем права www-data
sudo chown -R www-data:www-data .

# 6. Устанавливаем правильные права
sudo chmod 644 dashboard_inventory_v4.php
sudo chmod 644 DASHBOARD_ACTIVE_PRODUCTS_UPDATE.md

# 7. Проверяем синтаксис
php -l dashboard_inventory_v4.php
```

## Проверка результата

После деплоя проверьте:

1. **Откройте дашборд в браузере**: `http://your-server/dashboard_inventory_v4.php`
2. **Проверьте информационную панель** о фильтрации активных товаров
3. **Нажмите "Обновить статус"** - должны показаться данные по ~51 товару
4. **Проверьте "Критические остатки"** - количество должно быть значительно меньше

## Откат изменений (если нужно)

```bash
# Восстановить из резервной копии
cd /var/www/mi_core_api
sudo cp dashboard_inventory_v4.php.backup.YYYYMMDD_HHMMSS dashboard_inventory_v4.php
sudo chown www-data:www-data dashboard_inventory_v4.php
```

## Ожидаемые изменения

- **Заголовок**: "Ozon v4 API - Активные товары"
- **Информационная панель**: Синяя панель с объяснением фильтрации
- **Статистика**: Показывает ~51 активный товар вместо 446
- **Производительность**: Улучшение в 8.7 раз
