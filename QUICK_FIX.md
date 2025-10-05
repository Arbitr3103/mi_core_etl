# 🚀 Быстрое исправление демографии Ozon

## Проблема
❌ Ошибки в консоли: "Demographics data: undefined"

## Решение
✅ Демография отключена (данных нет в API)
✅ Воронка продаж работает корректно

## Обновление на сервере

```bash
# Запустите скрипт:
./update_server.sh
```

## Проверка
1. Перезагрузите страницу: **Ctrl+Shift+R**
2. Откройте консоль (F12)
3. Ошибок о demographics быть не должно

## Что работает
- ✅ Воронка продаж
- ✅ Кампании
- ✅ Таблицы и графики

## Коммит
```
04c00c1 - Add documentation for Ozon Analytics demographics fix
3c90668 - Remove demographics from Ozon Analytics - not available in API
```
