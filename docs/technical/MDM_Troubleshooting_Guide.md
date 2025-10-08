# Руководство по устранению неполадок системы MDM

## Содержание

1. [Общие принципы диагностики](#общие-принципы-диагностики)
2. [Проблемы с производительностью](#проблемы-с-производительностью)
3. [Проблемы с базой данных](#проблемы-с-базой-данных)
4. [Проблемы ETL процессов](#проблемы-etl-процессов)
5. [Проблемы с API](#проблемы-с-api)
6. [Проблемы веб-интерфейса](#проблемы-веб-интерфейса)
7. [Проблемы безопасности](#проблемы-безопасности)
8. [Мониторинг и логирование](#мониторинг-и-логирование)

## Общие принципы диагностики

### Пошаговый подход к диагностике

1. **Определите симптомы** - что именно не работает
2. **Соберите информацию** - логи, метрики, время возникновения
3. **Воспроизведите проблему** - попытайтесь повторить ошибку
4. **Изолируйте причину** - определите компонент с проблемой
5. **Примените решение** - исправьте проблему
6. **Проверьте результат** - убедитесь, что проблема решена
7. **Документируйте** - запишите решение для будущего

### Основные инструменты диагностики

**Логи системы:**

```bash
# Логи приложения
tail -f /var/log/mdm/application.log

# Логи веб-сервера
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log

# Логи PHP
tail -f /var/log/php8.1-fpm.log

# Системные логи
journalctl -u nginx -f
journalctl -u mysql -f
```

**Мониторинг ресурсов:**

```bash
# Использование CPU и памяти
htop

# Дисковое пространство
df -h

# Сетевые соединения
netstat -tulpn

# Процессы MySQL
mysqladmin processlist -u root -p
```

## Проблемы с производительностью

### Медленная работа веб-интерфейса

**Симптомы:**

- Долгая загрузка страниц (>5 секунд)
- Таймауты при выполнении операций
- Высокое использование CPU/памяти

**Диагностика:**

1. **Проверьте нагрузку на сервер:**

```bash
# Общая нагрузка
uptime
top

# Использование памяти
free -h

# Дисковая активность
iostat -x 1
```

2. **Анализ медленных запросов MySQL:**

```sql
-- Включение логирования медленных запросов
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;

-- Просмотр медленных запросов
SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10;
```

3. **Проверка PHP процессов:**

```bash
# Статус PHP-FPM
sudo systemctl status php8.1-fpm

# Активные процессы PHP
ps aux | grep php-fpm
```

**Решения:**

1. **Оптимизация MySQL:**

```sql
-- Анализ и оптимизация таблиц
ANALYZE TABLE master_products, sku_mapping;
OPTIMIZE TABLE master_products, sku_mapping;

-- Проверка индексов
SHOW INDEX FROM master_products;
```

2. **Настройка кэширования:**

```bash
# Проверка Redis
redis-cli ping
redis-cli info memory

# Очистка кэша при необходимости
redis-cli flushall
```

3. **Увеличение ресурсов PHP:**

```ini
# /etc/php/8.1/fpm/php.ini
memory_limit = 512M
max_execution_time = 300
max_input_vars = 3000

# /etc/php/8.1/fpm/pool.d/www.conf
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15
```

### Высокое использование памяти

**Диагностика:**

```bash
# Анализ использования памяти по процессам
ps aux --sort=-%mem | head -20

# Детальная информация о памяти
cat /proc/meminfo

# Проверка swap
swapon -s
```

**Решения:**

1. **Оптимизация MySQL:**

```ini
# /etc/mysql/mysql.conf.d/mysqld.cnf
innodb_buffer_pool_size = 8G  # Уменьшить если нужно
query_cache_size = 128M       # Уменьшить кэш запросов
```

2. **Настройка PHP-FPM:**

```ini
# Уменьшение количества процессов
pm.max_children = 25
pm.start_servers = 5
```

## Проблемы с базой данных

### Ошибки подключения к БД

**Симптомы:**

- "Connection refused" ошибки
- "Too many connections" ошибки
- Таймауты подключения

**Диагностика:**

1. **Проверка статуса MySQL:**

```bash
sudo systemctl status mysql
mysqladmin ping -u root -p
```

2. **Проверка соединений:**

```sql
SHOW PROCESSLIST;
SHOW STATUS LIKE 'Connections';
SHOW STATUS LIKE 'Max_used_connections';
SHOW VARIABLES LIKE 'max_connections';
```

3. **Проверка логов MySQL:**

```bash
tail -f /var/log/mysql/error.log
```

**Решения:**

1. **Увеличение лимита соединений:**

```sql
SET GLOBAL max_connections = 300;
```

2. **Оптимизация пула соединений в приложении:**

```php
// config/database.php
'mysql' => [
    'pool_size' => 10,
    'timeout' => 30,
    'retry_attempts' => 3
]
```

### Блокировки таблиц

**Диагностика:**

```sql
-- Просмотр заблокированных запросов
SELECT * FROM information_schema.INNODB_LOCKS;
SELECT * FROM information_schema.INNODB_LOCK_WAITS;

-- Активные транзакции
SELECT * FROM information_schema.INNODB_TRX;
```

**Решения:**

1. **Завершение блокирующих процессов:**

```sql
-- Найти блокирующий процесс
SELECT
    r.trx_id waiting_trx_id,
    r.trx_mysql_thread_id waiting_thread,
    r.trx_query waiting_query,
    b.trx_id blocking_trx_id,
    b.trx_mysql_thread_id blocking_thread,
    b.trx_query blocking_query
FROM information_schema.innodb_lock_waits w
INNER JOIN information_schema.innodb_trx b ON b.trx_id = w.blocking_trx_id
INNER JOIN information_schema.innodb_trx r ON r.trx_id = w.requesting_trx_id;

-- Завершить блокирующий процесс
KILL {blocking_thread_id};
```

## Проблемы ETL процессов

### ETL процессы не запускаются

**Диагностика:**

1. **Проверка cron заданий:**

```bash
crontab -l
sudo crontab -u www-data -l
```

2. **Проверка логов ETL:**

```bash
tail -f /var/log/mdm/etl.log
```

3. **Ручной запуск ETL:**

```bash
cd /var/www/mdm
php bin/etl.php --source=ozon --dry-run
```

**Решения:**

1. **Исправление cron заданий:**

```bash
# Редактирование crontab
sudo crontab -u www-data -e

# Добавление задания
0 */4 * * * cd /var/www/mdm && php bin/etl.php --source=all >> /var/log/mdm/etl-cron.log 2>&1
```

2. **Проверка прав доступа:**

```bash
sudo chown -R www-data:www-data /var/www/mdm
sudo chmod +x /var/www/mdm/bin/etl.php
```

### Ошибки при обработке данных

**Типичные ошибки:**

1. **Ошибки API внешних сервисов:**

```bash
# Проверка доступности API
curl -I "https://api-seller.ozon.ru/v1/ping"

# Проверка API ключей
grep -r "API_KEY" /var/www/mdm/.env
```

2. **Ошибки парсинга данных:**

```php
// Включение детального логирования
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/mdm/php-errors.log');
```

**Решения:**

1. **Обновление API ключей:**

```bash
# Редактирование .env файла
nano /var/www/mdm/.env
```

2. **Добавление обработки ошибок:**

```php
try {
    $result = $apiClient->getData();
} catch (ApiException $e) {
    $logger->error('API Error: ' . $e->getMessage());
    // Retry logic or fallback
}
```

## Проблемы с API

### API возвращает ошибки 500

**Диагностика:**

1. **Проверка логов PHP:**

```bash
tail -f /var/log/php8.1-fpm.log
tail -f /var/log/mdm/api.log
```

2. **Тестирование API endpoints:**

```bash
# Тест аутентификации
curl -X POST https://mdm.your-company.com/api/auth/token \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"test"}'

# Тест получения данных
curl -X GET https://mdm.your-company.com/api/products \
  -H "Authorization: Bearer {token}"
```

**Решения:**

1. **Проверка конфигурации API:**

```php
// api/config.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
```

2. **Добавление обработки исключений:**

```php
try {
    // API logic
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
```

### Проблемы с аутентификацией API

**Симптомы:**

- Ошибки "Invalid token"
- Ошибки "Token expired"
- Ошибки "Unauthorized access"

**Диагностика:**

```bash
# Проверка JWT секрета
grep JWT_SECRET /var/www/mdm/.env

# Тест декодирования токена
php -r "
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
\$token = 'your_token_here';
\$key = 'your_jwt_secret';
try {
    \$decoded = JWT::decode(\$token, \$key, ['HS256']);
    print_r(\$decoded);
} catch (Exception \$e) {
    echo 'Error: ' . \$e->getMessage();
}
"
```

**Решения:**

1. **Обновление JWT секрета:**

```bash
# Генерация нового секрета
openssl rand -base64 32

# Обновление в .env
JWT_SECRET=new_generated_secret_here
```

2. **Настройка времени жизни токенов:**

```php
// config/jwt.php
'ttl' => 3600, // 1 час
'refresh_ttl' => 86400, // 24 часа
```

## Проблемы веб-интерфейса

### Страницы не загружаются

**Диагностика:**

1. **Проверка Nginx:**

```bash
sudo nginx -t
sudo systemctl status nginx
tail -f /var/log/nginx/error.log
```

2. **Проверка PHP-FPM:**

```bash
sudo systemctl status php8.1-fpm
tail -f /var/log/php8.1-fpm.log
```

**Решения:**

1. **Перезапуск сервисов:**

```bash
sudo systemctl restart nginx
sudo systemctl restart php8.1-fpm
```

2. **Проверка конфигурации Nginx:**

```nginx
# Убедитесь, что путь к сокету правильный
fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
```

### JavaScript ошибки в браузере

**Диагностика:**

1. Откройте Developer Tools (F12)
2. Проверьте вкладку Console на наличие ошибок
3. Проверьте вкладку Network на failed requests

**Типичные проблемы и решения:**

1. **CORS ошибки:**

```php
// api/index.php
header('Access-Control-Allow-Origin: https://mdm.your-company.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
```

2. **Проблемы с путями к ресурсам:**

```html
<!-- Используйте абсолютные пути -->
<script src="/assets/js/app.js"></script>
<link rel="stylesheet" href="/assets/css/app.css" />
```

## Проблемы безопасности

### Подозрительная активность

**Признаки:**

- Необычно высокий трафик
- Множественные неудачные попытки входа
- Запросы к несуществующим endpoints

**Диагностика:**

1. **Анализ логов доступа:**

```bash
# Топ IP адресов по количеству запросов
awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -nr | head -20

# Поиск подозрительных запросов
grep -E "(SELECT|UNION|DROP|INSERT)" /var/log/nginx/access.log

# 404 ошибки
grep " 404 " /var/log/nginx/access.log | tail -20
```

2. **Проверка неудачных попыток входа:**

```bash
grep "authentication failed" /var/log/mdm/security.log
```

**Решения:**

1. **Блокировка подозрительных IP:**

```bash
# Временная блокировка через iptables
sudo iptables -A INPUT -s suspicious_ip -j DROP

# Постоянная блокировка через fail2ban
sudo fail2ban-client set nginx-limit-req banip suspicious_ip
```

2. **Усиление безопасности:**

```nginx
# Ограничение частоты запросов
limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
limit_req zone=api burst=20 nodelay;

# Блокировка по User-Agent
if ($http_user_agent ~* (bot|crawler|spider)) {
    return 403;
}
```

### Утечка данных

**Немедленные действия:**

1. **Изоляция системы:**

```bash
# Временное отключение внешнего доступа
sudo iptables -A INPUT -p tcp --dport 80 -j DROP
sudo iptables -A INPUT -p tcp --dport 443 -j DROP
```

2. **Анализ компрометации:**

```bash
# Поиск подозрительных файлов
find /var/www/mdm -name "*.php" -mtime -1 -exec grep -l "eval\|base64_decode\|shell_exec" {} \;

# Проверка целостности файлов
md5sum /var/www/mdm/src/**/*.php > current_checksums.txt
diff original_checksums.txt current_checksums.txt
```

3. **Восстановление из резервной копии:**

```bash
# Восстановление файлов приложения
sudo rm -rf /var/www/mdm
sudo tar -xzf /backups/mdm_backup_latest.tar.gz -C /var/www/

# Восстановление базы данных
mysql -u root -p mdm_production < /backups/mdm_db_backup_latest.sql
```

## Мониторинг и логирование

### Настройка системы мониторинга

**Установка и настройка Prometheus + Grafana:**

1. **Установка Prometheus:**

```bash
# Создание пользователя
sudo useradd --no-create-home --shell /bin/false prometheus

# Скачивание и установка
wget https://github.com/prometheus/prometheus/releases/download/v2.40.0/prometheus-2.40.0.linux-amd64.tar.gz
tar xvf prometheus-2.40.0.linux-amd64.tar.gz
sudo cp prometheus-2.40.0.linux-amd64/prometheus /usr/local/bin/
sudo cp prometheus-2.40.0.linux-amd64/promtool /usr/local/bin/
```

2. **Конфигурация Prometheus:**

```yaml
# /etc/prometheus/prometheus.yml
global:
  scrape_interval: 15s

scrape_configs:
  - job_name: "mdm-system"
    static_configs:
      - targets: ["localhost:9090"]

  - job_name: "mysql"
    static_configs:
      - targets: ["localhost:9104"]

  - job_name: "nginx"
    static_configs:
      - targets: ["localhost:9113"]
```

### Настройка алертов

**Создание правил алертинга:**

```yaml
# /etc/prometheus/alert_rules.yml
groups:
  - name: mdm_alerts
    rules:
      - alert: HighCPUUsage
        expr: cpu_usage_percent > 80
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High CPU usage detected"

      - alert: DatabaseConnectionError
        expr: mysql_up == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "MySQL database is down"

      - alert: ETLProcessFailed
        expr: etl_last_success_timestamp < (time() - 14400)
        for: 0m
        labels:
          severity: warning
        annotations:
          summary: "ETL process hasn't run successfully in 4 hours"
```

### Централизованное логирование

**Настройка ELK Stack (Elasticsearch, Logstash, Kibana):**

1. **Конфигурация Logstash:**

```ruby
# /etc/logstash/conf.d/mdm.conf
input {
  file {
    path => "/var/log/mdm/*.log"
    type => "mdm_application"
  }
  file {
    path => "/var/log/nginx/access.log"
    type => "nginx_access"
  }
}

filter {
  if [type] == "mdm_application" {
    grok {
      match => { "message" => "%{TIMESTAMP_ISO8601:timestamp} %{LOGLEVEL:level} %{GREEDYDATA:message}" }
    }
  }
}

output {
  elasticsearch {
    hosts => ["localhost:9200"]
    index => "mdm-logs-%{+YYYY.MM.dd}"
  }
}
```

### Автоматизированные проверки здоровья

**Скрипт проверки состояния системы:**

```bash
#!/bin/bash
# /usr/local/bin/mdm_health_check.sh

# Проверка веб-сервера
if ! curl -f -s http://localhost/health > /dev/null; then
    echo "CRITICAL: Web server not responding"
    exit 2
fi

# Проверка базы данных
if ! mysqladmin ping -u mdm_user -p$DB_PASSWORD > /dev/null 2>&1; then
    echo "CRITICAL: Database not responding"
    exit 2
fi

# Проверка дискового пространства
DISK_USAGE=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
if [ $DISK_USAGE -gt 90 ]; then
    echo "WARNING: Disk usage is ${DISK_USAGE}%"
    exit 1
fi

# Проверка ETL процессов
LAST_ETL=$(mysql -u mdm_user -p$DB_PASSWORD -e "SELECT MAX(created_at) FROM etl_logs" -s -N)
CURRENT_TIME=$(date +%s)
LAST_ETL_TIME=$(date -d "$LAST_ETL" +%s)
DIFF=$((CURRENT_TIME - LAST_ETL_TIME))

if [ $DIFF -gt 14400 ]; then  # 4 часа
    echo "WARNING: ETL process hasn't run in $((DIFF/3600)) hours"
    exit 1
fi

echo "OK: All systems operational"
exit 0
```

**Добавление в cron:**

```bash
# Проверка каждые 5 минут
*/5 * * * * /usr/local/bin/mdm_health_check.sh >> /var/log/mdm/health_check.log 2>&1
```

---

**Помните:** При возникновении критических проблем всегда делайте резервную копию данных перед применением исправлений. Ведите документацию всех изменений для будущего анализа.
