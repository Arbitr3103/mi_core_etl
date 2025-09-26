# 🔧 Руководство по устранению проблем

## 🚨 Текущие проблемы и их решения

### Проблема №1: 404 Not Found

**Ошибка:** `Failed to load resource: the server responded with a status of 404 (Not Found)`

**Причина:** Nginx не знает, где искать файлы API после установки SSL сертификата.

**Решение на сервере Elysia:**

1. **Подключитесь к серверу по SSH:**

   ```bash
   ssh vladimir@elysia
   ```

2. **Проверьте текущую конфигурацию Nginx:**

   ```bash
   sudo nginx -t
   cat /etc/nginx/sites-enabled/default
   ```

3. **Найдите блок для api.zavodprostavok.ru и исправьте путь:**

   ```bash
   sudo nano /etc/nginx/sites-enabled/default
   ```

4. **Найдите и замените строку:**

   ```nginx
   # БЫЛО (неправильно):
   root /var/www/html;

   # ДОЛЖНО БЫТЬ (правильно):
   root /var/www/mi_core_api/src;
   ```

5. **Проверьте и перезагрузите Nginx:**

   ```bash
   sudo nginx -t
   sudo systemctl reload nginx
   ```

6. **Проверьте, что файлы доступны:**
   ```bash
   ls -la /var/www/mi_core_api/src/api/
   curl https://api.zavodprostavok.ru/api/countries.php
   ```

### Проблема №2: Mixed Content (старый код)

**Ошибка:** `Mixed Content: requested insecure content from http://178.72.129.61`

**Причина:** На сайте WordPress остался старый код с IP адресом.

**Где искать старый код в WordPress:**

#### 1. В админке WordPress:

**Внешний вид → Редактор тем:**

```bash
# Проверьте эти файлы:
- functions.php
- header.php
- footer.php
- single.php
- page.php
- archive.php
```

**Поиск через админку:**

1. Зайдите в админку WordPress
2. Внешний вид → Редактор тем
3. Нажмите Ctrl+F и ищите: `178.72.129.61`

#### 2. Плагины для вставки кода:

**Insert Headers and Footers:**

- Настройки → Insert Headers and Footers
- Проверьте поля "Scripts in Header" и "Scripts in Footer"

**Code Snippets:**

- Snippets → All Snippets
- Проверьте активные сниппеты

**Custom CSS & JS:**

- Внешний вид → Custom CSS & JS
- Проверьте JS файлы

#### 3. Настройки темы:

- Внешний вид → Настроить
- Дополнительный CSS
- Дополнительные настройки темы

#### 4. Через FTP/SSH поиск:

**Если есть доступ к файлам сайта:**

```bash
# Поиск в файлах WordPress
grep -r "178.72.129.61" /path/to/wordpress/
grep -r "CountryFilter" /path/to/wordpress/wp-content/themes/
grep -r "api.*countries" /path/to/wordpress/wp-content/
```

#### 5. Проверка через браузер:

**В консоли браузера (F12):**

```javascript
// Найти все скрипты на странице
Array.from(document.scripts).forEach((script) => {
  if (script.src.includes("178.72.129.61")) {
    console.log("Найден старый скрипт:", script.src);
    console.log("Элемент:", script);
  }
});

// Найти все элементы с старым API
document.querySelectorAll("*").forEach((el) => {
  if (el.innerHTML && el.innerHTML.includes("178.72.129.61")) {
    console.log("Найден элемент со старым API:", el);
  }
});
```

### Что нужно заменить:

#### Найти и заменить ВСЕ упоминания:

**Старые URL (удалить/заменить):**

```javascript
http://178.72.129.61/api/countries.php
http://178.72.129.61/api/brands.php
http://178.72.129.61/api/models.php
http://178.72.129.61/api/years.php
http://178.72.129.61/api/products.php
http://178.72.129.61/api/js/CountryFilter.js
```

**Новые URL (использовать):**

```javascript
https://api.zavodprostavok.ru/api/countries.php
https://api.zavodprostavok.ru/api/brands.php
https://api.zavodprostavok.ru/api/models.php
https://api.zavodprostavok.ru/api/years.php
https://api.zavodprostavok.ru/api/products.php
https://api.zavodprostavok.ru/api/js/CountryFilter.js
```

### Примеры замены:

#### 1. В functions.php:

```php
// БЫЛО:
wp_enqueue_script('country-filter', 'http://178.72.129.61/api/js/CountryFilter.js');

// СТАЛО:
wp_enqueue_script('country-filter', 'https://api.zavodprostavok.ru/api/js/CountryFilter.js');
```

#### 2. В JavaScript коде:

```javascript
// БЫЛО:
const API_URL = "http://178.72.129.61/api";

// СТАЛО:
const API_URL = "https://api.zavodprostavok.ru/api";
```

#### 3. В HTML:

```html
<!-- БЫЛО: -->
<script src="http://178.72.129.61/api/js/CountryFilter.js"></script>

<!-- СТАЛО: -->
<script src="https://api.zavodprostavok.ru/api/js/CountryFilter.js"></script>
```

## 🧪 Проверка после исправлений:

### 1. Проверка API:

```bash
# Должны работать все эти URL:
curl https://api.zavodprostavok.ru/api/countries.php
curl https://api.zavodprostavok.ru/api/brands.php
curl https://api.zavodprostavok.ru/api/
```

### 2. Проверка в браузере:

1. Откройте F12 → Console
2. Не должно быть ошибок 404 или Mixed Content
3. Все запросы должны идти к `api.zavodprostavok.ru`

### 3. Проверка фильтра:

1. Откройте страницу каталога
2. Найдите выпадающий список "Страна изготовления"
3. Убедитесь, что список загружается
4. Выберите страну и проверьте, что срабатывает фильтрация

## 📞 Если проблемы остаются:

### Временное решение:

Если не можете найти старый код, добавьте этот скрипт в конец страницы:

```html
<script>
  // Удаляем все старые скрипты с IP адресом
  document.addEventListener("DOMContentLoaded", function () {
    Array.from(document.scripts).forEach((script) => {
      if (script.src && script.src.includes("178.72.129.61")) {
        script.remove();
        console.log("Удален старый скрипт:", script.src);
      }
    });

    // Загружаем новый скрипт
    const newScript = document.createElement("script");
    newScript.src = "https://api.zavodprostavok.ru/api/js/CountryFilter.js";
    newScript.onload = function () {
      console.log("Загружен новый скрипт");
      // Инициализация фильтра
      if (
        typeof CountryFilter !== "undefined" &&
        document.getElementById("country-filter-container")
      ) {
        new CountryFilter("country-filter-container", function (countryId) {
          console.log("Выбрана страна:", countryId);
        });
      }
    };
    document.head.appendChild(newScript);
  });
</script>
```

### Контакты для поддержки:

- **Тест API:** https://api.zavodprostavok.ru/api/test_api_endpoints.html
- **Документация:** https://api.zavodprostavok.ru/api/
- **Проверка стран:** https://api.zavodprostavok.ru/api/countries.php
