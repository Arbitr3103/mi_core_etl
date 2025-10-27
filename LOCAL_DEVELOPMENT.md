# Локальная разработка - Warehouse Dashboard

## Быстрый старт

### 1. Первоначальная настройка

```bash
# Запустите скрипт настройки (только один раз)
./local-setup.sh
```

Этот скрипт:

-   ✅ Проверит все зависимости (Node.js, PostgreSQL, PHP)
-   ✅ Проверит/создаст базу данных
-   ✅ Создаст необходимые директории
-   ✅ Установит зависимости frontend

### 2. Запуск серверов

Откройте **два терминала**:

**Терминал 1 - Backend (PHP):**

```bash
./local-backend.sh
```

**Терминал 2 - Frontend (React):**

```bash
./local-frontend.sh
```

### 3. Откройте в браузере

```
http://localhost:5173
```

## Что запущено

### Backend Server

-   **URL:** http://localhost:8080
-   **API Endpoint:** http://localhost:8080/api/inventory/detailed-stock
-   **Технология:** PHP 8.4 встроенный сервер
-   **База данных:** PostgreSQL (localhost:5432)

### Frontend Server

-   **URL:** http://localhost:5173
-   **Технология:** Vite + React
-   **Proxy:** Автоматически проксирует `/api` на backend

## Тестирование API

### Проверка работы API

```bash
# Получить summary статистику
curl 'http://localhost:8080/api/inventory/detailed-stock?action=summary'

# Получить список складов
curl 'http://localhost:8080/api/inventory/detailed-stock?action=warehouses'

# Получить детальные данные
curl 'http://localhost:8080/api/inventory/detailed-stock?limit=10'

# Фильтр по статусу
curl 'http://localhost:8080/api/inventory/detailed-stock?statuses[]=critical&statuses[]=low'
```

## Структура проекта

```
mi_core_etl/
├── frontend/                    # React приложение
│   ├── src/
│   │   ├── components/         # React компоненты
│   │   ├── hooks/              # Custom hooks
│   │   ├── services/           # API сервисы
│   │   └── types/              # TypeScript типы
│   ├── vite.config.ts          # Конфигурация Vite
│   └── package.json
│
├── api/                         # Backend API
│   ├── inventory/
│   │   └── detailed-stock.php  # Главный API endpoint
│   └── classes/
│       ├── DetailedInventoryService.php
│       ├── DetailedInventoryController.php
│       └── EnhancedCacheService.php
│
├── sql/                         # SQL скрипты
│   └── optimize_detailed_inventory_view.sql
│
├── config/                      # Конфигурация
│   ├── local.php               # Локальная конфигурация
│   └── database_postgresql.php
│
├── .env.local                   # Локальные переменные окружения
├── local-setup.sh              # Скрипт настройки
├── local-backend.sh            # Запуск backend
└── local-frontend.sh           # Запуск frontend
```

## Конфигурация

### .env.local

Файл `.env.local` содержит настройки для локальной разработки:

```bash
# Database
PG_HOST=localhost
PG_PORT=5432
PG_NAME=mi_core_db
PG_USER=vladimirbragin
PG_PASSWORD=

# API
API_BASE_URL=http://localhost:8080/api
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000

# Cache
CACHE_DRIVER=file
CACHE_PATH=storage/cache
```

### frontend/.env.local

Автоматически создаётся при запуске `local-frontend.sh`:

```bash
VITE_API_BASE_URL=http://localhost:8080
VITE_APP_ENV=development
```

## Разработка

### Hot Reload

Оба сервера поддерживают hot reload:

-   **Frontend:** Vite автоматически перезагружает при изменении файлов
-   **Backend:** PHP встроенный сервер перезагружает при каждом запросе

### Логи

**Backend логи:**

```bash
# Логи выводятся в терминал где запущен backend
# Также можно смотреть в:
tail -f storage/logs/*.log
```

**Frontend логи:**

```bash
# Логи выводятся в терминал где запущен frontend
# Также в консоли браузера (F12)
```

### Отладка

**Backend (PHP):**

```php
// Добавьте в код для отладки
error_log("Debug: " . print_r($variable, true));
```

**Frontend (React):**

```typescript
// Используйте console.log
console.log("Debug:", variable);

// Или React DevTools в браузере
```

## Остановка серверов

Нажмите `Ctrl+C` в каждом терминале, где запущены серверы.

## Troubleshooting

### Порт уже занят

**Backend (8080):**

```bash
# Найти процесс
lsof -i :8080

# Убить процесс
kill -9 <PID>
```

**Frontend (5173):**

```bash
# Найти процесс
lsof -i :5173

# Убить процесс
kill -9 <PID>
```

### База данных не подключается

```bash
# Проверить статус PostgreSQL
brew services list | grep postgresql

# Запустить PostgreSQL
brew services start postgresql@14

# Проверить подключение
psql -d mi_core_db -c "SELECT 1"
```

### API возвращает ошибку

```bash
# Проверить логи backend
tail -f storage/logs/*.log

# Проверить что view существует
psql -d mi_core_db -c "SELECT COUNT(*) FROM v_detailed_inventory"
```

### Frontend не загружается

```bash
# Очистить кэш и node_modules
cd frontend
rm -rf node_modules .vite dist
npm install

# Перезапустить
cd ..
./local-frontend.sh
```

### CORS ошибки

Убедитесь что:

1. Backend запущен на порту 8080
2. Frontend запущен на порту 5173
3. В `.env.local` указаны правильные CORS origins

## Полезные команды

```bash
# Проверить статус серверов
lsof -i :8080  # Backend
lsof -i :5173  # Frontend

# Проверить базу данных
psql -d mi_core_db -c "SELECT COUNT(*) FROM v_detailed_inventory"

# Очистить кэш
rm -rf storage/cache/*

# Пересоздать view
psql -d mi_core_db -f sql/optimize_detailed_inventory_view.sql

# Запустить тесты frontend
cd frontend && npm run test

# Проверить типы TypeScript
cd frontend && npm run type-check

# Запустить линтер
cd frontend && npm run lint
```

## Следующие шаги

После успешного запуска локально:

1. **Разработка:** Вносите изменения в код
2. **Тестирование:** Проверяйте в браузере http://localhost:5173
3. **Коммит:** Сохраняйте изменения в git
4. **Деплой:** Используйте скрипты из `deployment/scripts/`

## Документация

-   [Deployment Guide](../.kiro/specs/warehouse-dashboard-redesign/DEPLOYMENT_GUIDE.md)
-   [User Guide](../.kiro/specs/warehouse-dashboard-redesign/USER_GUIDE.md)
-   [API Migration Notes](../.kiro/specs/warehouse-dashboard-redesign/API_MIGRATION_NOTES.md)

---

**Версия:** 1.0.0  
**Последнее обновление:** 27 октября 2025
