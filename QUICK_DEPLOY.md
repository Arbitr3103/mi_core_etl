# 🚀 Быстрое развертывание исправлений API

## Что исправлено:

- ✅ Добавлен поддержка `action=overview`
- ✅ Исправлено имя таблицы с `inventory` на `inventory_data`
- ✅ Улучшена обработка ошибок и структура ответов

## Команды для развертывания:

### 1. Подключитесь к серверу

```bash
ssh vladimir@elysia
cd /var/www/mi_core_api
```

### 2. Создайте резервную копию

```bash
cp api/inventory-v4.php api/inventory-v4.php.backup
```

### 3. Скачайте исправленный файл

```bash
wget https://raw.githubusercontent.com/Arbitr3103/mi_core_etl/main/api/inventory-v4.php -O api/inventory-v4.php
```

### 4. Протестируйте

```bash
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview"
```

## Ожидаемый результат:

```json
{
  "success": true,
  "data": {
    "overview": {
      "total_products": 202,
      "products_in_stock": 176,
      "total_stock": 12543
    }
  }
}
```

**Время развертывания:** 2 минуты  
**Статус:** Готово к использованию ✅
