# Инструкция по настройке логотипа Market-MIRu

## Добавление логотипа на дашборд

Логотип Market-MIRu был добавлен в верхний левый угол всех страниц дашборда:

1. В папку `/images` добавлен файл `market_mi_logo.jpeg`
2. Внесены изменения в стили и HTML в следующие файлы:
   - `dashboard_index.php`
   - `dashboard_marketplace_enhanced.php`
   - `dashboard_inventory_v4.php`

## Настройки для сервера

Для корректного отображения логотипа на сервере:

1. Убедитесь что существует папка `images` в корневом каталоге проекта
2. Проверьте наличие файла `.htaccess` и его корректные настройки
3. В случае использования Nginx убедитесь в корректной настройке для доступа к статическим файлам:

```nginx
location /images/ {
    alias /path/to/project/images/;
    access_log off;
    expires max;
    try_files $uri =404;
}
```

## Информация о логотипе

- Размер: 40px в высоту (адаптивный)
- Положение: фиксированное, в верхнем левом углу
- Z-index: 10000 (поверх других элементов)

## Настройка под другой хостинг

Если сайт размещается не в корневом каталоге, измените пути к изображению:

```html
<img src="/images/market_mi_logo.jpeg" alt="Market-MIRu" class="logo-image">
```

на:

```html
<img src="/ваш-подкаталог/images/market_mi_logo.jpeg" alt="Market-MIRu" class="logo-image">
```
