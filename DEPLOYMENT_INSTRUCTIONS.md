# 🚀 Инструкции по развертыванию API исправлений

## Быстрое развертывание

### 1. Подключитесь к серверу

```bash
ssh vladimir@elysia
cd /var/www/mi_core_api
```

### 2. Создайте резервную копию

```bash
cp api/inventory-v4.php api/inventory-v4.php.backup.$(date +%Y%m%d_%H%M%S)
```

### 3. Скачайте исправленные файлы

```bash
# Скачайте с GitHub или скопируйте содержимое файлов:
wget https://raw.githubusercontent.com/Arbitr3103/mi_core_etl/main/api/inventory-v4.php -O api/inventory-v4.php
wget https://raw.githubusercontent.com/Arbitr3103/mi_core_etl/main/check-database-structure.php
wget https://raw.githubusercontent.com/Arbitr3103/mi_core_etl/main/fix-api-issues.php
```

### 4. Проверьте структуру БД

```bash
php check-database-structure.php
```

### 5. Протестируйте API

```bash
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=3"
```

## Ожидаемый результат

После развертывания API должен возвращать данные вместо ошибок 500.

## Если нужна помощь

Запустите: `php fix-api-issues.php` для исправления прав БД.
