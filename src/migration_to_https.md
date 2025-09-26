# 🔒 Миграция на HTTPS: api.zavodprostavok.ru

## ✅ Что уже сделано на сервере:

- ✅ Получен SSL сертификат для домена api.zavodprostavok.ru
- ✅ Настроен Nginx для работы по HTTPS
- ✅ Настроено автоматическое перенаправление с HTTP на HTTPS
- ✅ Обновлены все JavaScript файлы в репозитории

## 🔄 Что нужно изменить на сайте zavodprostavok.ru:

### Старые URL (нужно заменить):

```javascript
// ❌ СТАРЫЕ URL - больше не используйте
http://178.72.129.61/api/countries.php
http://178.72.129.61/api/brands.php
http://178.72.129.61/api/models.php
http://178.72.129.61/api/years.php
http://178.72.129.61/api/products.php
```

### Новые URL (используйте эти):

```javascript
// ✅ НОВЫЕ URL - используйте эти
https://api.zavodprostavok.ru/api/countries.php
https://api.zavodprostavok.ru/api/brands.php
https://api.zavodprostavok.ru/api/models.php
https://api.zavodprostavok.ru/api/years.php
https://api.zavodprostavok.ru/api/products.php
```

## 📝 Примеры замены кода:

### 1. Если у вас есть fetch запросы:

**Было:**

```javascript
fetch("http://178.72.129.61/api/countries.php")
  .then((response) => response.json())
  .then((data) => {
    console.log(data);
  });
```

**Стало:**

```javascript
fetch("https://api.zavodprostavok.ru/api/countries.php")
  .then((response) => response.json())
  .then((data) => {
    console.log(data);
  });
```

### 2. Если у вас есть jQuery AJAX:

**Было:**

```javascript
$.ajax({
  url: "http://178.72.129.61/api/brands.php",
  method: "GET",
  success: function (data) {
    console.log(data);
  },
});
```

**Стало:**

```javascript
$.ajax({
  url: "https://api.zavodprostavok.ru/api/brands.php",
  method: "GET",
  success: function (data) {
    console.log(data);
  },
});
```

### 3. Если у вас есть подключение скриптов:

**Было:**

```html
<script src="http://178.72.129.61/api/js/CountryFilter.js"></script>
```

**Стало:**

```html
<script src="https://api.zavodprostavok.ru/api/js/CountryFilter.js"></script>
```

### 4. Если у вас есть переменная с базовым URL:

**Было:**

```javascript
const API_BASE_URL = "http://178.72.129.61/api";
```

**Стало:**

```javascript
const API_BASE_URL = "https://api.zavodprostavok.ru/api";
```

## 🔍 Где искать код для замены:

### В WordPress админке:

1. **Внешний вид → Редактор тем**

   - Проверьте файлы: `functions.php`, `header.php`, `footer.php`
   - Ищите строки с `178.72.129.61`

2. **Плагины для вставки кода:**

   - "Insert Headers and Footers"
   - "Code Snippets"
   - "Custom CSS & JS"

3. **Настройки темы:**

   - Дополнительный CSS
   - Дополнительный JavaScript
   - Кастомные поля

4. **Редактор страниц/записей:**
   - HTML блоки
   - Кастомный код в страницах каталога

## 🧪 Как проверить, что все работает:

### 1. Откройте консоль браузера (F12):

```javascript
// Выполните этот код в консоли для проверки
fetch("https://api.zavodprostavok.ru/api/countries.php")
  .then((response) => response.json())
  .then((data) => console.log("API работает:", data))
  .catch((error) => console.error("Ошибка API:", error));
```

### 2. Проверьте сетевые запросы:

- Откройте F12 → Network
- Обновите страницу с фильтрами
- Убедитесь, что все запросы идут к `api.zavodprostavok.ru`
- Проверьте, что статус ответов 200 (успешно)

### 3. Тестовые страницы:

- **API документация:** https://api.zavodprostavok.ru/api/
- **Тест endpoints:** https://api.zavodprostavok.ru/api/test_api_endpoints.html
- **Проверка стран:** https://api.zavodprostavok.ru/api/countries.php

## ⚠️ Важные моменты:

1. **Обязательно используйте HTTPS** (не HTTP)
2. **Старый IP адрес больше не работает** для продакшена
3. **Все браузеры поддерживают HTTPS** - проблем совместимости нет
4. **SSL сертификат валиден** и автоматически обновляется

## 🚀 Готовый код для WordPress:

Если вы не можете найти старый код, используйте этот новый:

```html
<!-- Добавьте в functions.php или в footer.php -->
<script>
  // Инициализация фильтра стран
  document.addEventListener("DOMContentLoaded", function () {
    // Загружаем скрипт динамически
    const script = document.createElement("script");
    script.src = "https://api.zavodprostavok.ru/api/js/CountryFilter.js";
    script.onload = function () {
      // Инициализируем фильтр после загрузки скрипта
      if (document.getElementById("country-filter-container")) {
        const countryFilter = new CountryFilter(
          "country-filter-container",
          function (selectedCountryId) {
            console.log("Выбрана страна:", selectedCountryId);

            // Ваша логика обработки выбора страны
            if (selectedCountryId) {
              // Например, перезагрузка страницы с параметром
              const url = new URL(window.location);
              url.searchParams.set("country_id", selectedCountryId);
              window.location.href = url.toString();
            } else {
              // Убираем фильтр
              const url = new URL(window.location);
              url.searchParams.delete("country_id");
              window.location.href = url.toString();
            }
          }
        );
      }
    };
    document.head.appendChild(script);
  });
</script>

<!-- И добавьте контейнер в нужное место на странице каталога -->
<div id="country-filter-container"></div>
```

## 📞 Поддержка:

Если что-то не работает:

1. Проверьте консоль браузера на ошибки
2. Убедитесь, что используете HTTPS (не HTTP)
3. Проверьте, что домен `api.zavodprostavok.ru` доступен
4. Протестируйте API напрямую: https://api.zavodprostavok.ru/api/countries.php
