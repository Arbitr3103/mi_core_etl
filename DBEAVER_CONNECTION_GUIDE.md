# 🔗 Подключение к базе данных через DBeaver

**Сервер:** market-mi.ru (178.72.129.61)  
**База данных:** mi_core  
**Пользователь:** v_admin

## 📋 Настройки подключения в DBeaver

### 1. Основные параметры подключения:

```
Тип подключения: MySQL
Хост: 178.72.129.61 (или market-mi.ru)
Порт: 3306
База данных: mi_core
Пользователь: v_admin
Пароль: Arbitr09102022!
```

### 2. Пошаговая инструкция:

1. **Откройте DBeaver**
2. **Создайте новое подключение:**

   - Нажмите "+" или File → New → Database Connection
   - Выберите "MySQL"

3. **Заполните параметры:**

   - **Server Host:** `178.72.129.61`
   - **Port:** `3306`
   - **Database:** `mi_core`
   - **Username:** `v_admin`
   - **Password:** `Arbitr09102022!`

4. **Дополнительные настройки (вкладка "Driver properties"):**

   - `allowPublicKeyRetrieval`: `true`
   - `useSSL`: `false`
   - `serverTimezone`: `UTC`

5. **Тест подключения:**
   - Нажмите "Test Connection"
   - Должно появиться сообщение "Connected"

## 🔧 Если подключение не работает

### Вариант 1: SSH туннель

Если прямое подключение не работает, используйте SSH туннель:

1. **В DBeaver перейдите на вкладку "SSH"**
2. **Включите "Use SSH tunnel"**
3. **Настройки SSH:**

   - **Host/IP:** `178.72.129.61`
   - **Port:** `22`
   - **User name:** `root`
   - **Authentication:** `Public Key` или `Password`
   - **Private key:** путь к вашему SSH ключу

4. **Настройки MySQL (вкладка "Main"):**
   - **Server Host:** `localhost` (через туннель)
   - **Port:** `3306`
   - **Database:** `mi_core`
   - **Username:** `v_admin`
   - **Password:** `Arbitr09102022!`

### Вариант 2: Альтернативный пользователь

Если v_admin не работает, попробуйте root:

```
Пользователь: root
Пароль: Root_MDM_2025_SecurePass!
```

## 📊 Структура базы данных

После успешного подключения вы увидите следующие таблицы:

### Основные таблицы:

- **`inventory_data`** - данные об остатках товаров
- **`dim_products`** - справочник товаров
- **`fact_orders`** - данные о заказах
- **`fact_transactions`** - транзакции
- **`replenishment_recommendations`** - рекомендации по пополнению

### Служебные таблицы:

- **`raw_events`** - сырые события
- **`replenishment_config`** - конфигурация пополнения
- **`v_latest_replenishment_recommendations`** - представление рекомендаций

## 🔍 Полезные запросы для проверки

### 1. Проверка остатков:

```sql
SELECT
    sku,
    warehouse_name,
    current_stock,
    available_stock,
    reserved_stock,
    last_sync_at
FROM inventory_data
ORDER BY current_stock ASC
LIMIT 10;
```

### 2. Статистика по складам:

```sql
SELECT
    warehouse_name,
    COUNT(*) as products_count,
    SUM(current_stock) as total_stock,
    AVG(current_stock) as avg_stock
FROM inventory_data
GROUP BY warehouse_name;
```

### 3. Критические остатки:

```sql
SELECT
    sku,
    warehouse_name,
    current_stock
FROM inventory_data
WHERE current_stock <= 5
ORDER BY current_stock ASC;
```

## 🚨 Устранение проблем

### Ошибка "Access denied"

- Проверьте правильность пароля
- Убедитесь, что используете пользователя `v_admin`
- Попробуйте подключиться через SSH туннель

### Ошибка "Connection timeout"

- Проверьте, что сервер доступен: `ping 178.72.129.61`
- Используйте SSH туннель
- Проверьте настройки firewall

### Ошибка "Unknown database"

- Убедитесь, что указали базу данных `mi_core`
- Проверьте, что база существует

### Пустые таблицы

- Это нормально, если данные еще не загружены
- Для загрузки тестовых данных используйте скрипты синхронизации

## 📞 Поддержка

Если проблемы остаются:

1. Проверьте логи MySQL: `sudo tail -f /var/log/mysql/error.log`
2. Проверьте подключение через командную строку:
   ```bash
   mysql -h 178.72.129.61 -u v_admin -p mi_core
   ```
3. Обратитесь к администратору сервера

---

_Инструкция создана автоматически системой Kiro IDE_
