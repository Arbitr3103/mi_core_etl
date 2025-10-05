# 🚀 Инструкция по развертыванию исправлений Ozon Analytics

## Что исправлено
✅ Удалены ошибки "Demographics data: undefined" в консоли
✅ Отключена загрузка демографических данных (недоступны в API)
✅ Воронка продаж и кампании работают корректно

## Развертывание на сервере

### Шаг 1: Подключитесь к серверу
```bash
ssh vladimir@178.72.129.61
```

### Шаг 2: Перейдите в директорию проекта
```bash
cd /var/www/mi_core_api
```

### Шаг 3: Запустите безопасный деплой
```bash
./deploy_safe.sh
```

Скрипт автоматически:
- 💾 Сохранит локальные изменения (git stash)
- 📥 Подтянет обновления с GitHub
- 🔧 Установит правильные права на файлы
- 🔄 Перезапустит PHP-FPM
- ✅ Проверит развертывание

### Шаг 4: Проверьте результат
1. Откройте дашборд: https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php
2. Перейдите в раздел "📊 Аналитика Ozon"
3. Откройте консоль браузера (F12)
4. Перезагрузите страницу: **Ctrl+Shift+R**
5. Убедитесь, что ошибок о demographics нет

## Что изменилось

### Файлы:
- `src/js/OzonAnalyticsIntegration.js`
- `js/ozon/OzonAnalyticsIntegration.js`

### Изменения:
- Отключена инициализация демографии
- Метод `loadDemographicsData()` возвращает `null`
- Метод `updateComponents()` пропускает демографию
- Удалена загрузка демографических данных

## Коммиты
```
070a4d0 - Add quick fix guide for Ozon Analytics
04c00c1 - Add documentation for Ozon Analytics demographics fix
3c90668 - Remove demographics from Ozon Analytics - not available in API
```

## Откат (если нужно)
```bash
cd /var/www/mi_core_api
git log --oneline -5  # Посмотрите предыдущие коммиты
git checkout <предыдущий_коммит>
./deploy_safe.sh
```

## Поддержка
Если возникли проблемы:
1. Проверьте логи: `tail -f /var/log/nginx/error.log`
2. Проверьте PHP-FPM: `sudo systemctl status php8.1-fpm`
3. Проверьте права: `ls -la /var/www/mi_core_api/src/js/`

## Дата обновления
2025-10-05
