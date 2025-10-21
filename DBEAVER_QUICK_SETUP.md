# 🚀 Быстрая настройка DBeaver для подключения к базе данных

## ✅ Подключение настроено и работает!

### 📋 Параметры подключения:

```
Хост: 178.72.129.61 (или market-mi.ru)
Порт: 3306
База данных: mi_core
Пользователь: v_admin
Пароль: Arbitr09102022!
```

### 🔧 Настройки в DBeaver:

1. **Создайте новое подключение MySQL**
2. **Основные параметры:**

   - Server Host: `178.72.129.61`
   - Port: `3306`
   - Database: `mi_core`
   - Username: `v_admin`
   - Password: `Arbitr09102022!`

3. **Дополнительные настройки (Driver Properties):**

   - `allowPublicKeyRetrieval` = `true`
   - `useSSL` = `false`
   - `serverTimezone` = `UTC`

4. **Нажмите "Test Connection"** - должно показать "Connected"

## 📊 Таблицы в базе данных:

После подключения вы увидите:

### 🏪 Основные таблицы:

- **`inventory_data`** - остатки товаров (5 записей)
- **`dim_products`** - справочник товаров (пока пуст)
- **`fact_orders`** - заказы (пока пуст)
- **`fact_transactions`** - транзакции (пока пуст)

### ⚙️ Служебные таблицы:

- **`replenishment_recommendations`** - рекомендации
- **`replenishment_config`** - настройки
- **`raw_events`** - события
- **`v_latest_replenishment_recommendations`** - представление

## 🔍 Тестовые запросы:

### Проверить остатки:

```sql
SELECT * FROM inventory_data ORDER BY current_stock;
```

### Статистика по складам:

```sql
SELECT
    warehouse_name,
    COUNT(*) as products,
    SUM(current_stock) as total_stock
FROM inventory_data
GROUP BY warehouse_name;
```

### Критические остатки:

```sql
SELECT * FROM inventory_data WHERE current_stock <= 5;
```

## 🎯 Что вы увидите:

Сейчас в базе есть **5 тестовых записей**:

- TEST001: 15 шт (нормальный остаток)
- TEST002: 3 шт (критический)
- TEST003: 150 шт (избыток)
- TEST004: 8 шт (низкий)
- TEST005: 0 шт (критический)

## 🔄 Следующие шаги:

1. **Подключитесь к базе через DBeaver**
2. **Изучите структуру таблиц**
3. **Когда будете готовы к реальным данным** - запустите миграцию:
   ```bash
   python safe_migration.py
   ```

---

_Подключение протестировано и работает! ✅_
