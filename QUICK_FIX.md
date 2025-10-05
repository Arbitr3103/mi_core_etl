# 🚀 Быстрое исправление демографии и кампаний Ozon

## Проблема
❌ Ошибки в консоли: 
- "Demographics data: undefined"
- "Campaigns API error: HTTP 400"

## Решение
✅ Демография отключена (данных нет в API)
✅ Кампании отключены (данных нет в API)
✅ Воронка продаж работает корректно

## Обновление на сервере

```bash
# На сервере запустите:
cd /var/www/mi_core_api
./deploy_safe.sh
```

## Проверка
1. Перезагрузите страницу: **Ctrl+Shift+R**
2. Откройте консоль (F12)
3. Ошибок о demographics и campaigns быть не должно

## Что работает
- ✅ Воронка продаж (Sales Funnel)
- ✅ Таблицы и графики
- ✅ Фильтры по товарам

## Что отключено
- ⚠️ Демография (недоступна в API)
- ⚠️ Кампании (недоступны в API)

## Коммиты
```
8e77199 - Disable campaigns and demographics loading in dashboard
db38c69 - Add deployment instructions for server update
04c00c1 - Add documentation for Ozon Analytics demographics fix
3c90668 - Remove demographics from Ozon Analytics - not available in API
```
