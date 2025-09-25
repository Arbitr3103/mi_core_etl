# 🚀 Руководство по развертыванию системы фильтрации по странам

## Быстрый старт

### 1. Клонирование репозитория

```bash
git clone https://github.com/Arbitr3103/mi_core_etl.git
cd mi_core_etl
```

### 2. Автоматическое развертывание

```bash
./deploy_country_filter.sh production
```

### 3. Ручная настройка (если нужно)

#### Настройка базы данных

```bash
# Создание индексов для оптимизации
mysql -u username -p database_name < create_country_filter_indexes.sql

# Или выполните SQL команды из файла вручную
```

#### Настройка веб-сервера

**Nginx:**

```nginx
location /api/ {
    try_files $uri $uri/ /api/index.php;
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

**Apache (.htaccess):**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/(.*)$ api/index.php [QSA,L]
```

### 4. Проверка работоспособности

#### Тестирование API

```bash
php test_country_filter_api.php
```

#### Тестирование производительности

```bash
php test_country_filter_performance.php
```

#### Комплексное тестирование

```bash
./tests/run_complete_tests.sh
```

---

## Структура файлов на сервере

```
/
├── CountryFilterAPI.php          # Основной API класс
├── api/                          # API endpoints
│   ├── countries.php
│   ├── countries-by-brand.php
│   ├── countries-by-model.php
│   └── products-filter.php
├── js/                           # Frontend компоненты
│   ├── CountryFilter.js
│   └── FilterManager.js
├── css/                          # Стили
│   └── country-filter.css
├── demo/                         # Демо страницы
│   ├── country-filter-demo.html
│   └── mobile-country-filter-demo.html
└── tests/                        # Тесты (опционально)
```

---

## Требования к серверу

### Минимальные требования:

- **PHP**: 7.4+
- **MySQL**: 5.7+ или MariaDB 10.2+
- **Веб-сервер**: Nginx или Apache
- **Память**: 512MB RAM
- **Место**: 50MB свободного места

### Рекомендуемые требования:

- **PHP**: 8.0+
- **MySQL**: 8.0+
- **Память**: 1GB+ RAM
- **SSD**: для лучшей производительности

---

## Настройка конфигурации

### Параметры базы данных

Отредактируйте `CountryFilterAPI.php`:

```php
private $host = 'localhost';
private $dbname = 'your_database';
private $username = 'your_username';
private $password = 'your_password';
```

### Настройки производительности

```php
// Включение кэширования
private $cacheEnabled = true;
private $cacheTime = 3600; // 1 час

// Настройки пагинации
private $defaultLimit = 100;
private $maxLimit = 1000;
```

---

## Интеграция в существующее приложение

### HTML интеграция

```html
<!-- Подключение стилей -->
<link rel="stylesheet" href="css/country-filter.css" />

<!-- HTML разметка -->
<div id="country-filter-container">
  <label for="country-select">Страна изготовления:</label>
  <select id="country-select">
    <option value="">Все страны</option>
  </select>
</div>

<!-- Подключение скриптов -->
<script src="js/CountryFilter.js"></script>
<script src="js/FilterManager.js"></script>
```

### JavaScript инициализация

```javascript
// Инициализация фильтра
const filterManager = new FilterManager({
  apiBaseUrl: "/api",
  onFiltersChange: function (filters) {
    console.log("Фильтры изменились:", filters);
    // Обновление результатов
  },
});

// Добавление фильтра по стране
filterManager.initCountryFilter("country-filter-container");
```

---

## Мониторинг и обслуживание

### Логи для мониторинга

```bash
# Логи веб-сервера
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# Логи PHP
tail -f /var/log/php/error.log
```

### Регулярные проверки

```bash
# Еженедельная проверка производительности
0 2 * * 1 /path/to/test_country_filter_performance.php

# Ежедневная проверка API
0 6 * * * /path/to/test_country_filter_api.php
```

### Резервное копирование

```bash
# Бэкап файлов системы
tar -czf country_filter_backup_$(date +%Y%m%d).tar.gz \
    CountryFilterAPI.php api/ js/ css/ demo/

# Бэкап базы данных (таблицы связанные с фильтром)
mysqldump -u username -p database_name \
    regions brands car_models car_specifications dim_products \
    > country_filter_db_backup_$(date +%Y%m%d).sql
```

---

## Устранение неполадок

### Частые проблемы:

**1. API возвращает ошибку 500**

- Проверьте настройки базы данных
- Убедитесь что таблицы существуют
- Проверьте логи PHP на ошибки

**2. Фильтр не загружает страны**

- Проверьте CORS настройки
- Убедитесь что API endpoints доступны
- Проверьте JavaScript консоль на ошибки

**3. Медленная работа**

- Выполните создание индексов БД
- Включите кэширование
- Проверьте нагрузку на сервер

**4. Проблемы на мобильных устройствах**

- Проверьте viewport meta tag
- Убедитесь что CSS файлы загружаются
- Протестируйте на реальных устройствах

### Контакты для поддержки

- Документация: `COUNTRY_FILTER_API_GUIDE.md`
- Производительность: `COUNTRY_FILTER_PERFORMANCE_GUIDE.md`
- Тесты: `tests/` директория

---

## ✅ Чек-лист развертывания

- [ ] Клонирован репозиторий
- [ ] Запущен `deploy_country_filter.sh`
- [ ] Настроена база данных
- [ ] Созданы индексы БД
- [ ] Настроен веб-сервер
- [ ] Проверены права доступа к файлам
- [ ] Протестированы API endpoints
- [ ] Проверена работа демо страниц
- [ ] Настроен мониторинг
- [ ] Создан план резервного копирования

**🎉 Система готова к работе!**
