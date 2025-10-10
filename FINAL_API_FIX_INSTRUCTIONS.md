# 🔧 Финальное исправление API - Инструкция по развертыванию

## Проблема

- `action=products` возвращал HTTP 500 ошибку
- Проблема была в SQL функции `CAST(i.product_id AS CHAR)`
- Не все версии MySQL поддерживают такой синтаксис

## Решение

✅ Заменили все `CAST(i.product_id AS CHAR)` на `CONCAT('', i.product_id)`
✅ Исправлены все 3 проблемных места в API:

- products action (строка 120)
- low-stock action (строка 184)
- analytics action (строка 245)

## Развертывание на сервере

### Вариант 1: Автоматический скрипт

```bash
# Запустите скрипт на сервере
./update-server-api.sh
```

### Вариант 2: Ручное обновление

```bash
# 1. Скачать обновленный файл
wget https://raw.githubusercontent.com/Arbitr3103/mi_core_etl/main/api/inventory-v4.php -O /tmp/inventory-v4-updated.php

# 2. Создать резервную копию
sudo cp /var/www/html/api/inventory-v4.php /var/www/html/api/inventory-v4.php.backup

# 3. Обновить файл
sudo cp /tmp/inventory-v4-updated.php /var/www/html/api/inventory-v4.php

# 4. Установить права доступа
sudo chown www-data:www-data /var/www/html/api/inventory-v4.php
sudo chmod 644 /var/www/html/api/inventory-v4.php
```

## Тестирование после развертывания

```bash
# Тест overview (должен работать)
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview"

# Тест products (теперь должен работать!)
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=3"

# Тест low-stock
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=low-stock&threshold=10"

# Тест analytics
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=analytics"
```

## Ожидаемый результат

- ✅ Все endpoints должны возвращать HTTP 200
- ✅ `action=products` больше не должен давать HTTP 500
- ✅ Данные должны корректно отображаться с названиями товаров

## Коммиты

- `8b8b8b8` - Первое исправление CAST на CONCAT
- `e2413db` - Исправлены все оставшиеся CAST на CONCAT

## Техническая информация

- **Проблема**: `CAST(i.product_id AS CHAR)` не поддерживается в некоторых версиях MySQL
- **Решение**: `CONCAT('', i.product_id)` - универсальный способ преобразования числа в строку
- **Совместимость**: Работает во всех версиях MySQL и MariaDB
