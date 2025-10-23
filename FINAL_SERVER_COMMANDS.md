# 🚀 Финальные команды для установки мониторинга

## ✅ Статус: Файлы загружены на сервер!

Все файлы системы мониторинга успешно загружены в `/home/vladimir/monitoring_upload/`

## 📋 Выполните эти команды на сервере:

### 1. Подключитесь к серверу:

```bash
ssh vladimir@178.72.129.61
```

### 2. Переместите файлы в веб-директорию:

```bash
sudo cp -r /home/vladimir/monitoring_upload/* /var/www/market-mi.ru/
```

### 3. Установите правильные права доступа:

```bash
sudo chown -R www-data:www-data /var/www/market-mi.ru/api/
sudo chown -R www-data:www-data /var/www/market-mi.ru/config/
sudo chown www-data:www-data /var/www/market-mi.ru/monitoring.html
sudo chmod +x /var/www/market-mi.ru/scripts/*.sh
sudo chmod +x /var/www/market-mi.ru/scripts/*.php
```

### 4. Создайте папку для логов:

```bash
sudo mkdir -p /var/log/warehouse-dashboard
sudo chown www-data:www-data /var/log/warehouse-dashboard
sudo chmod 755 /var/log/warehouse-dashboard
```

### 5. Проверьте работу мониторинга:

```bash
cd /var/www/market-mi.ru
php api/monitoring.php
```

### 6. (Опционально) Настройте автоматический мониторинг:

```bash
bash /var/www/market-mi.ru/scripts/setup_monitoring_cron.sh
```

## 🎯 Результат:

После выполнения команд у вас будет доступно:

-   **Дашборд мониторинга**: https://www.market-mi.ru/monitoring.html
-   **API мониторинга**: https://www.market-mi.ru/api/monitoring.php?action=health
-   **Статус системы**: https://www.market-mi.ru/api/monitoring-status.php

## 🔍 Проверка работы:

```bash
# Проверить API мониторинга
curl https://www.market-mi.ru/api/monitoring.php?action=health

# Проверить дашборд
curl https://www.market-mi.ru/monitoring.html
```

## 📊 Что включает система мониторинга:

-   ✅ Мониторинг состояния системы (CPU, память, диск)
-   ✅ Проверка доступности API endpoints
-   ✅ Мониторинг времени отклика
-   ✅ Система алертов и уведомлений
-   ✅ Веб-дашборд с реальным временем
-   ✅ Автоматические проверки каждые 5-15 минут
-   ✅ Логирование всех событий

---

**Все готово для запуска!** 🎉
