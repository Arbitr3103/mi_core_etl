# 🚀 Быстрый старт - Warehouse Dashboard

## Текущий статус

✅ **Backend запущен:** http://localhost:8080  
✅ **Frontend запущен:** http://localhost:5173  
✅ **API работает:** Все endpoints отвечают корректно

## Открыть дашборд

### В браузере откройте:

```
http://localhost:5173
```

Или из терминала:

```bash
open http://localhost:5173
```

## Если видите ошибки

### 1. Обновите страницу

Нажмите `Cmd+Shift+R` (или `Ctrl+Shift+R`) для жёсткой перезагрузки

### 2. Очистите кэш браузера

1. Откройте DevTools (F12)
2. Правый клик на кнопке обновления
3. Выберите "Empty Cache and Hard Reload"

### 3. Проверьте что серверы запущены

**Backend (должен быть на порту 8080):**

```bash
lsof -i :8080
```

**Frontend (должен быть на порту 5173):**

```bash
lsof -i :5173
```

### 4. Проверьте API напрямую

```bash
# Должен вернуть JSON с данными
curl 'http://localhost:8080/api/inventory/detailed-stock?action=summary'
```

### 5. Проверьте через proxy

```bash
# Должен вернуть те же данные
curl 'http://localhost:5173/api/inventory/detailed-stock?action=summary'
```

## Текущие данные

В базе данных есть:

-   **34 продукта**
-   **7 складов:**
    -   АДЫГЕЙСК_РФЦ
    -   Екатеринбург_РФЦ
    -   Казань_РФЦ
    -   Москва_РФЦ
    -   Новосибирск_РФЦ
    -   Ростов-на-Дону_РФЦ
    -   Санкт-Петербург_РФЦ

> **Примечание:** Все продукты в статусе "out_of_stock" - это нормально для тестовых данных.

## Что должно работать

### ✅ Summary Cards

-   Общее количество продуктов
-   Критические позиции
-   Низкий запас
-   Требуется пополнение

### ✅ Фильтры

-   По складам (dropdown с 7 складами)
-   По статусу (critical, low, normal, excess, out_of_stock)
-   Поиск по названию/SKU

### ✅ Таблица

-   Список продуктов с метриками
-   Сортировка по колонкам
-   Пагинация

### ✅ Экспорт

-   Export to Excel
-   Export to CSV

## Если ничего не помогло

### Перезапустите серверы

**1. Остановите текущие процессы:**

```bash
# Найдите процессы
lsof -i :8080
lsof -i :5173

# Убейте их
kill -9 <PID>
```

**2. Запустите заново:**

Терминал 1 - Backend:

```bash
./local-backend.sh
```

Терминал 2 - Frontend:

```bash
./local-frontend.sh
```

**3. Откройте в браузере:**

```bash
open http://localhost:5173
```

## Логи для отладки

### Backend логи

Смотрите в терминале где запущен `./local-backend.sh`

### Frontend логи

Смотрите в терминале где запущен `./local-frontend.sh`

### Browser Console

Откройте DevTools (F12) → Console

## Полезные команды

```bash
# Проверить статус PostgreSQL
brew services list | grep postgresql

# Проверить подключение к БД
psql -d mi_core_db -c "SELECT COUNT(*) FROM v_detailed_inventory"

# Проверить API
curl 'http://localhost:8080/api/inventory/detailed-stock?action=summary'

# Проверить warehouses
curl 'http://localhost:8080/api/inventory/detailed-stock?action=warehouses'

# Проверить детальные данные
curl 'http://localhost:8080/api/inventory/detailed-stock?limit=5'
```

## Следующие шаги

1. ✅ Откройте http://localhost:5173
2. ✅ Проверьте что дашборд загружается
3. ✅ Попробуйте фильтры и сортировку
4. ✅ Начните разработку!

---

**Всё работает?** Отлично! Теперь можете начинать разработку.

**Есть проблемы?** Смотрите [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) для подробной информации.
