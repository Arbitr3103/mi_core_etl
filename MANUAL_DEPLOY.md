# 🚀 Ручной Деплой на Сервер

## Быстрый Деплой (5 минут)

### Шаг 1: Подключитесь к серверу

```bash
ssh root@185.221.153.28
```

### Шаг 2: Перейдите в проект

```bash
cd /var/www/mi_core_etl
```

### Шаг 3: Создайте бэкап (опционально)

```bash
cd /var/www
cp -r mi_core_etl mi_core_etl_backup_$(date +%Y%m%d_%H%M%S)
cd mi_core_etl
```

### Шаг 4: Обновите код

```bash
git pull origin main
```

Ожидаемый вывод:

```
Updating 6988c4c..b34ac49
Fast-forward
 90 files changed, 28310 insertions(+), 1176 deletions(-)
 create mode 100644 src/DataQualityMonitor.php
 create mode 100644 src/AlertHandlers.php
 ...
```

### Шаг 5: Установите права

```bash
chmod +x monitor_data_quality.php
chmod +x setup_monitoring_cron.sh
chmod +x sync-real-product-names-v2.php
chmod +x test_monitoring_system.php
mkdir -p logs
chmod 755 logs
```

### Шаг 6: Проверьте систему

```bash
php test_monitoring_system.php
```

Должно показать:

```
✅ ALL TESTS PASSED - System ready for deployment!
```

### Шаг 7: Запустите тест мониторинга

```bash
php monitor_data_quality.php --verbose
```

### Шаг 8: Настройте cron (автоматический мониторинг)

```bash
./setup_monitoring_cron.sh
```

Когда спросит "Do you want to install these cron jobs? (y/n)", ответьте: **y**

### Шаг 9: Проверьте cron задачи

```bash
crontab -l | grep monitor_data_quality
```

Должно показать:

```
0 * * * * cd /var/www/mi_core_etl && php monitor_data_quality.php
```

### Шаг 10: Проверьте веб-доступ

Откройте в браузере:

- **Dashboard**: http://185.221.153.28/html/quality_dashboard.php
- **API**: http://185.221.153.28/api/quality-metrics.php?action=health

Или проверьте через curl:

```bash
curl http://185.221.153.28/api/quality-metrics.php?action=health
```

---

## ✅ Готово!

Система мониторинга установлена и работает!

### Что дальше?

1. **Настройте алерты** (опционально):

   ```bash
   nano config.php
   ```

   Добавьте в конец:

   ```php
   define('ALERT_EMAIL', 'your-email@example.com');
   define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/...');
   ```

2. **Запустите синхронизацию**:

   ```bash
   php sync-real-product-names-v2.php
   ```

3. **Проверьте логи**:

   ```bash
   tail -f logs/monitoring_cron.log
   ```

4. **Откройте дашборд** и наслаждайтесь мониторингом!

---

## 🐛 Если что-то пошло не так

### Проблема: git pull не работает

```bash
# Проверьте статус
git status

# Если есть изменения, сохраните их
git stash

# Попробуйте снова
git pull origin main
```

### Проблема: Permission denied

```bash
# Установите права снова
chmod +x *.php *.sh
chmod 755 logs
```

### Проблема: Dashboard не открывается

```bash
# Проверьте PHP error log
tail -f /var/log/php-fpm/error.log
# или
tail -f /var/log/apache2/error.log
```

### Проблема: API возвращает ошибку

```bash
# Проверьте подключение к БД
php -r "require 'config.php'; echo 'DB: ' . DB_NAME . PHP_EOL;"

# Проверьте API напрямую
cd api
php quality-metrics.php
```

---

## 📚 Документация

- **Быстрый старт**: `MONITORING_QUICK_START.md`
- **Полная документация**: `docs/MONITORING_SYSTEM_README.md`
- **Решение проблем**: `docs/MDM_TROUBLESHOOTING_GUIDE.md`
- **Чеклист деплоя**: `SERVER_DEPLOYMENT_CHECKLIST.md`

---

## 📞 Поддержка

Если возникли проблемы:

1. Проверьте логи в `logs/`
2. Запустите тесты: `php tests/test_data_quality_monitoring.php`
3. Посмотрите troubleshooting guide
4. Проверьте что все файлы на месте: `ls -la src/ html/ api/`

---

**Время деплоя**: ~5 минут  
**Сложность**: Легко  
**Требования**: SSH доступ к серверу
