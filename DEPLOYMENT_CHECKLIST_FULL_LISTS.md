# 🚀 Чеклист деплоя функциональности полных списков товаров

## ✅ Подготовка к деплою

### 1. Файлы созданы и готовы

- ✅ `js/inventory-display-controller.js` - JavaScript контроллер
- ✅ `css/inventory-display-styles.css` - CSS стили
- ✅ `html/inventory_marketing_dashboard.php` - обновленный дашборд
- ✅ `test_full_lists_functionality.html` - тестовая страница

### 2. Git статус

- ✅ Все изменения закоммичены
- ✅ Изменения запушены в репозиторий
- ✅ Коммит: "feat: добавлено отображение полных списков товаров в дашборде"

### 3. API готовность

- ✅ Параметр `limit=all` уже поддерживается в API
- ✅ Структура данных `{count: X, items: [...]}` работает корректно
- ✅ Тестирование показало: 9 критических, 13 низких остатков, 0 избытков

## 🔧 Команды для деплоя

### Вариант 1: Безопасный деплой (рекомендуется)

```bash
# 1. Создать бэкап текущих файлов
cp html/inventory_marketing_dashboard.php html/inventory_marketing_dashboard.php.backup

# 2. Скопировать новые файлы на сервер
scp css/inventory-display-styles.css user@server:/path/to/css/
scp js/inventory-display-controller.js user@server:/path/to/js/
scp html/inventory_marketing_dashboard.php user@server:/path/to/html/

# 3. Проверить права доступа
chmod 644 css/inventory-display-styles.css
chmod 644 js/inventory-display-controller.js
chmod 644 html/inventory_marketing_dashboard.php
```

### Вариант 2: Через git pull (если настроен)

```bash
# На сервере
cd /path/to/project
git pull origin feature/regional-analytics
```

### Вариант 3: Через rsync

```bash
rsync -avz --exclude='.git' ./ user@server:/path/to/project/
```

## 🧪 Тестирование после деплоя

### 1. Проверить доступность файлов

```bash
# Проверить, что файлы загружаются
curl -I https://your-domain.com/css/inventory-display-styles.css
curl -I https://your-domain.com/js/inventory-display-controller.js
```

### 2. Открыть тестовую страницу

- Перейти на: `https://your-domain.com/test_full_lists_functionality.html`
- Запустить все тесты
- Убедиться, что все тесты проходят

### 3. Проверить основной дашборд

- Открыть: `https://your-domain.com/html/inventory_marketing_dashboard.php`
- Проверить наличие переключателей "Топ-10" / "Показать все"
- Переключить режимы и убедиться в работоспособности
- Проверить счетчики товаров

### 4. Проверить API

```bash
# Тест с limit=all
curl "https://your-domain.com/api/inventory-analytics.php?action=dashboard&limit=all"

# Тест с limit=10
curl "https://your-domain.com/api/inventory-analytics.php?action=dashboard&limit=10"
```

## 🔍 Что проверить в браузере

### Функциональность

- [ ] Переключатели "Топ-10" / "Показать все" отображаются
- [ ] Переключение работает без ошибок
- [ ] Счетчики показывают правильные числа
- [ ] Компактный режим применяется при "Показать все"
- [ ] Прокрутка работает для больших списков
- [ ] Предпочтения сохраняются в localStorage

### Визуальное отображение

- [ ] Мелкий шрифт (12px) в компактном режиме
- [ ] Чередование цветов строк
- [ ] Правильные цвета для статусов товаров
- [ ] Адаптивность на мобильных устройствах

### Производительность

- [ ] Быстрое переключение между режимами
- [ ] Плавная прокрутка больших списков
- [ ] Отсутствие ошибок в консоли браузера

## 🚨 План отката (если что-то пойдет не так)

### Быстрый откат

```bash
# Восстановить бэкап дашборда
cp html/inventory_marketing_dashboard.php.backup html/inventory_marketing_dashboard.php

# Удалить новые файлы (если нужно)
rm css/inventory-display-styles.css
rm js/inventory-display-controller.js
```

### Через git

```bash
# Откатиться к предыдущему коммиту
git revert HEAD
git push origin feature/regional-analytics
```

## 📞 Контакты для поддержки

- **Разработчик**: Kiro AI Assistant
- **Документация**: `INVENTORY_FULL_LISTS_IMPLEMENTATION_SUMMARY.md`
- **Тесты**: `test_full_lists_functionality.html`

## 🎯 Ожидаемый результат

После успешного деплоя пользователи смогут:

- Видеть все 32 критических товара (вместо 10)
- Видеть все 53 товара с низкими остатками (вместо 10)
- Видеть все 28 товаров с избытком (вместо 10)
- Переключаться между полным и сокращенным видом
- Пользоваться компактным отображением с прокруткой

**Время деплоя**: ~5-10 минут  
**Время тестирования**: ~10-15 минут  
**Общее время**: ~20-25 минут
