# Развертывание импорта товаров Wildberries

## Быстрый старт на сервере

### 1. Получить обновления
```bash
cd /path/to/mi_core_etl
git pull origin main
```

### 2. Проверить подключение к WB Content API
```bash
python test_wb_products_import.py
```

**Ожидаемый результат:**
- ✅ Подключение к WB Content API успешно!
- 📦 Получено товаров в тестовом запросе: 1

### 3. Первоначальный импорт товаров WB
```bash
# ВАЖНО: Запустите это ПЕРЕД импортом продаж!
python main.py --source=wb --products-only
```

**Что происходит:**
- Загружаются ВСЕ товары из каталога WB
- Заполняется таблица `dim_products`
- Процесс может занять 30-60 минут (зависит от количества товаров)

### 4. Проверить результат
```bash
# Количество импортированных товаров WB
mysql -e "SELECT COUNT(*) as wb_products FROM dim_products WHERE sku_wb IS NOT NULL;"

# Примеры товаров
mysql -e "SELECT sku_wb, name, brand, barcode FROM dim_products WHERE sku_wb IS NOT NULL LIMIT 5;"
```

### 5. Полный импорт WB (товары + продажи + финансы)
```bash
python main.py --source=wb --last-7-days
```

## Мониторинг

### Проверка статуса импорта
```bash
# Общая статистика товаров
mysql -e "
SELECT 
    COUNT(*) as total_products,
    COUNT(CASE WHEN sku_wb IS NOT NULL THEN 1 END) as wb_products,
    COUNT(CASE WHEN sku_ozon IS NOT NULL THEN 1 END) as ozon_products
FROM dim_products;"
```

### Проверка связывания продаж с товарами
```bash
# Продажи WB с привязанными товарами
mysql -e "
SELECT COUNT(*) as linked_orders 
FROM fact_orders fo 
JOIN dim_products dp ON fo.product_id = dp.id 
WHERE fo.source_id = (SELECT id FROM sources WHERE name = 'WB');"
```

## Автоматизация

После успешного тестирования система автоматически включится в еженедельный ETL:

```bash
# Проверить cron job
crontab -l | grep etl

# Логи автоматических запусков
ls -la logs/etl_run_*.log
```

## Устранение неполадок

### Ошибка "WB_API_KEY not found"
```bash
# Проверить .env файл
cat .env | grep WB_API_KEY
```

### Медленный импорт товаров
- **Нормально**: Content API ограничен 100 запросами/минуту
- **Время**: ~1 секунда на товар (700ms задержка + обработка)
- **Для 1000 товаров**: ~17 минут

### Товары без штрихкодов
```bash
# Товары WB без штрихкодов (пропускаются при импорте)
grep "Товар без штрихкода" logs/etl_run_*.log | wc -l
```

### API лимиты
```bash
# Проверить ошибки лимитов в логах
grep "429\|rate limit" logs/etl_run_*.log
```

## Следующие шаги

1. ✅ Импорт товаров WB работает
2. ✅ Продажи связываются с товарами по штрихкодам  
3. ✅ Система готова к продуктивному использованию
4. 🔄 Еженедельный автоматический импорт включен

**Система полностью готова к работе!** 🚀
