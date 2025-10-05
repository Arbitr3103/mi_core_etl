# ✅ Быстрый чеклист деплоя исправлений Ozon API

## 🚀 Что нужно сделать на сервере:

### 1. Получить код

```bash
cd /path/to/mi_core_etl
git pull origin main
```

### 2. Применить миграцию БД

```bash
mysql -u mi_core_user -p mi_core_db < migrations/add_revenue_to_funnel_data.sql
```

### 3. Проверить API

```bash
curl "http://your-domain/src/api/ozon-analytics.php?action=health"
```

### 4. Проверить дашборд

Откройте дашборд в браузере и убедитесь, что данные загружаются.

## 🔧 Что было исправлено:

- ✅ **Основная проблема**: Метод `processFunnelData()` теперь правильно обрабатывает реальную структуру Ozon API
- ✅ **Структура данных**: Извлекает `product_id` из `dimensions[0]['id']` и метрики из массива `metrics`
- ✅ **Поле revenue**: Добавлено поле выручки в обработку и сохранение данных
- ✅ **База данных**: Создана миграция для добавления поля `revenue` в таблицу `ozon_funnel_data`

## 🎯 Ожидаемый результат:

После применения исправлений дашборд должен:

- Отображать реальные данные вместо нулевых значений
- Показывать выручку по товарам
- Корректно рассчитывать конверсии воронки продаж

## 📞 Если что-то не работает:

1. Проверьте логи: `tail -f /var/log/apache2/error.log`
2. Проверьте миграцию: `mysql -u mi_core_user -p mi_core_db -e "DESCRIBE ozon_funnel_data;"`
3. Тестируйте API: `./test_production_api.sh`
4. Смотрите полную документацию: `PRODUCTION_DEPLOYMENT.md`

---

**Коммит с исправлениями**: `43c2833` (Fix Ozon API data processing for real API structure)
