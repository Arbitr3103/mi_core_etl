# 🛡️ Безопасный план развертывания на продакшен

## ✅ Правильный подход к развертыванию

Вы абсолютно правы! Давайте сделаем все по правилам:

### 📋 План действий:

1. **🔄 Git workflow** - коммит, пуш, мерж веток
2. **🛡️ Безопасное развертывание** - без поломки работающего дашборда
3. **⏰ Правильная синхронизация** - обновления раз в сутки в 6 утра

---

## Шаг 1: Подготовка Git репозитория

### 1.1 Проверим текущий статус Git

```bash
git status
git branch
```

### 1.2 Создадим ветку для системы пополнения

```bash
git checkout -b feature/replenishment-system
```

### 1.3 Добавим все файлы системы пополнения

```bash
git add src/Replenishment/
git add api/replenishment.php
git add html/replenishment_dashboard.php
git add deployment/replenishment/
git add config_replenishment.php
git add cron_replenishment_weekly.php
git add export_recommendations.php
git add migrate_replenishment_simple.sql
git add integration_code_for_existing_dashboard.html
git add PRODUCTION_DEPLOYMENT_INSTRUCTIONS.md
git add DEPLOYMENT_SUCCESS_REPORT.md
git add QUICK_START_REPLENISHMENT.md
```

### 1.4 Коммит изменений

```bash
git commit -m "feat: Add inventory replenishment recommendation system

- Add ReplenishmentRecommender, SalesAnalyzer, StockCalculator classes
- Add REST API endpoint for replenishment recommendations
- Add interactive dashboard with modern UI
- Add database migration scripts and deployment automation
- Add integration code for existing dashboard
- Add comprehensive documentation and operational procedures
- Configure daily sync (24h) to match existing dashboard schedule

Features:
- Automated ADS (Average Daily Sales) calculation
- Target stock level recommendations based on lead time + safety stock
- Priority-based recommendations (High/Medium/Low)
- Real-time API with JSON responses
- CSV export functionality
- Production-ready deployment scripts
- Comprehensive monitoring and logging

Tested with real data: 12 products processed, 1,426 units recommended"
```

### 1.5 Пуш ветки

```bash
git push origin feature/replenishment-system
```

### 1.6 Мерж в main (после проверки)

```bash
git checkout main
git merge feature/replenishment-system
git push origin main
```

---

## Шаг 2: Безопасное развертывание на сервере

### 2.1 Проверим текущий дашборд

```bash
# Убедимся, что дашборд работает
curl -I "http://178.72.129.61/test_dashboard.html"
```

### 2.2 Создадим полную резервную копию

```bash
ssh root@178.72.129.61 "
    # Создаем резервную копию всего проекта
    BACKUP_DIR='/var/backups/full_backup_$(date +%Y%m%d_%H%M%S)'
    mkdir -p \$BACKUP_DIR

    # Копируем все файлы
    cp -r /var/www/* \$BACKUP_DIR/

    # Бэкап базы данных
    mysqldump -u root mi_core > \$BACKUP_DIR/mi_core_full_backup.sql

    echo 'Полная резервная копия создана: '\$BACKUP_DIR
"
```

### 2.3 Обновим код на сервере через Git

```bash
ssh root@178.72.129.61 "
    cd /var/www

    # Проверяем Git статус
    git status

    # Получаем последние изменения
    git fetch origin
    git pull origin main

    echo 'Код обновлен с Git репозитория'
"
```

---

## Шаг 3: Настройка системы пополнения

### 3.1 Настройка базы данных (безопасно)

```bash
ssh root@178.72.129.61 "
    cd /var/www

    # Создаем пользователя БД (если не существует)
    mysql -u root -e \"
        CREATE USER IF NOT EXISTS 'replenishment_user'@'localhost' IDENTIFIED BY 'secure_replenishment_2025';
        GRANT SELECT, INSERT, UPDATE, DELETE ON mi_core.replenishment_* TO 'replenishment_user'@'localhost';
        GRANT SELECT ON mi_core.fact_orders TO 'replenishment_user'@'localhost';
        GRANT SELECT ON mi_core.inventory_data TO 'replenishment_user'@'localhost';
        GRANT SELECT ON mi_core.dim_products TO 'replenishment_user'@'localhost';
        FLUSH PRIVILEGES;
    \"

    # Выполняем миграцию (только таблицы пополнения)
    mysql -u root mi_core < migrate_replenishment_simple.sql

    echo 'База данных настроена безопасно'
"
```

### 3.2 Создание продакшен конфигурации

```bash
ssh root@178.72.129.61 "
    cd /var/www

    # Создаем продакшен конфигурацию на основе существующей
    cp config.php config_replenishment.php

    # Добавляем настройки пополнения в конец файла
    cat >> config_replenishment.php << 'EOF'

// ============================================================================
// НАСТРОЙКИ СИСТЕМЫ ПОПОЛНЕНИЯ
// ============================================================================

// Настройки пользователя БД для пополнения
define('REPLENISHMENT_DB_USER', 'replenishment_user');
define('REPLENISHMENT_DB_PASSWORD', 'secure_replenishment_2025');

// Настройки системы
define('REPLENISHMENT_ENABLED', true);
define('REPLENISHMENT_DEBUG', false);
define('REPLENISHMENT_LOG_LEVEL', 'info');

// Настройки производительности
define('REPLENISHMENT_MEMORY_LIMIT', '512M');
define('REPLENISHMENT_MAX_EXECUTION_TIME', 300);
define('REPLENISHMENT_BATCH_SIZE', 100);

// Отключаем email для начала
define('EMAIL_REPORTS_ENABLED', false);

// API настройки
define('API_ENABLED', true);
define('API_DEBUG', false);
define('API_KEY_REQUIRED', false);

// Логирование
define('LOG_DIR', __DIR__ . '/logs/replenishment');

// Функция подключения к БД для пополнения
function getReplenishmentDbConnection() {
    static \$pdo = null;

    if (\$pdo === null) {
        \$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        \$pdo = new PDO(\$dsn, REPLENISHMENT_DB_USER, REPLENISHMENT_DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }

    return \$pdo;
}

// Создание директории для логов
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

EOF

    echo 'Продакшен конфигурация создана'
"
```

### 3.3 Обновим API для использования правильной конфигурации

<function_calls>
<invoke name="strReplace">
<parameter name="path">api/replenishment.php
