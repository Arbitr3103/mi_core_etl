
# МАППИНГ ДАННЫХ BASEBUY -> MI_CORE_DB
# Создано автоматически на основе анализа дампа

## Наши целевые таблицы:
# - regions (id, name)
# - brands (id, name, region_id)  
# - car_models (id, name, brand_id)
# - car_specifications (id, car_model_id, year_start, year_end, pcd, dia, fastener_type, fastener_params)

## Найденные таблицы BaseBuy:

### Таблица: car_characteristic
Колонки:
  - id_car_characteristic: `id_car_characteristic` int NOT NULL AUTO_INCREMENT COMMENT 'id',
  - name: `name` varchar(255) NOT NULL COMMENT 'Название',
  - id_parent: `id_parent` int DEFAULT NULL COMMENT 'id',
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_characteristic_value
Колонки:
  - id_car_characteristic_value: `id_car_characteristic_value` int NOT NULL AUTO_INCREMENT,
  - id_car_modification: `id_car_modification` int NOT NULL COMMENT 'ID',
  - id_car_characteristic: `id_car_characteristic` int NOT NULL COMMENT 'id',
  - value: `value` varchar(500) NOT NULL,
  - unit: `unit` varchar(255) DEFAULT NULL COMMENT 'Еденица измерения',
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_equipment
Колонки:
  - id_car_equipment: `id_car_equipment` int NOT NULL AUTO_INCREMENT COMMENT 'id',
  - id_car_modification: `id_car_modification` int NOT NULL COMMENT 'ID',
  - name: `name` varchar(255) NOT NULL,
  - price_min: `price_min` int DEFAULT NULL COMMENT 'Цена от',
  - year: `year` int DEFAULT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_generation
Колонки:
  - id_car_generation: `id_car_generation` int NOT NULL AUTO_INCREMENT,
  - id_car_model: `id_car_model` int NOT NULL COMMENT 'ID',
  - name: `name` varchar(255) NOT NULL,
  - year_begin: `year_begin` varchar(255) DEFAULT NULL,
  - year_end: `year_end` varchar(255) DEFAULT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

🎯 ВОЗМОЖНЫЙ МАППИНГ -> car_specifications:
  car_generation.??? -> car_specifications.year_start
  car_generation.??? -> car_specifications.year_end
  car_generation.??? -> car_specifications.pcd
  car_generation.??? -> car_specifications.dia

### Таблица: car_mark
Колонки:
  - id_car_mark: `id_car_mark` int NOT NULL AUTO_INCREMENT COMMENT 'ID',
  - name: `name` varchar(255) NOT NULL,
  - name_rus: `name_rus` varchar(255) DEFAULT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_model
Колонки:
  - id_car_model: `id_car_model` int NOT NULL AUTO_INCREMENT COMMENT 'ID',
  - id_car_mark: `id_car_mark` int NOT NULL COMMENT 'ID',
  - name: `name` varchar(255) NOT NULL,
  - name_rus: `name_rus` varchar(255) DEFAULT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

🎯 ВОЗМОЖНЫЙ МАППИНГ -> car_models:
  car_model.??? -> car_models.name
  car_model.??? -> car_models.brand_id

### Таблица: car_modification
Колонки:
  - id_car_modification: `id_car_modification` int NOT NULL AUTO_INCREMENT COMMENT 'ID',
  - id_car_serie: `id_car_serie` int NOT NULL COMMENT 'ID',
  - id_car_model: `id_car_model` int NOT NULL COMMENT 'ID',
  - name: `name` varchar(255) NOT NULL,
  - start_production_year: `start_production_year` int DEFAULT NULL,
  - end_production_year: `end_production_year` int DEFAULT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_option
Колонки:
  - id_car_option: `id_car_option` int NOT NULL AUTO_INCREMENT,
  - name: `name` varchar(255) NOT NULL,
  - id_parent: `id_parent` int DEFAULT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_option_value
Колонки:
  - id_car_option_value: `id_car_option_value` int NOT NULL AUTO_INCREMENT,
  - id_car_option: `id_car_option` int NOT NULL,
  - id_car_equipment: `id_car_equipment` int NOT NULL COMMENT 'id',
  - is_base: `is_base` tinyint(1) NOT NULL DEFAULT '1',
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_serie
Колонки:
  - id_car_serie: `id_car_serie` int NOT NULL AUTO_INCREMENT COMMENT 'ID',
  - id_car_model: `id_car_model` int NOT NULL COMMENT 'ID',
  - id_car_generation: `id_car_generation` int DEFAULT NULL,
  - name: `name` varchar(255) NOT NULL,
  - date_create: `date_create` int unsigned NOT NULL,
  - date_update: `date_update` int unsigned NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL,

### Таблица: car_type
Колонки:
  - id_car_type: `id_car_type` int NOT NULL AUTO_INCREMENT,
  - name: `name` varchar(255) NOT NULL,

### Таблица: year
Колонки:
  - id: `id` int NOT NULL AUTO_INCREMENT COMMENT 'id',
  - id_car_make: `id_car_make` int NOT NULL COMMENT 'ID',
  - id_car_model: `id_car_model` int DEFAULT NULL COMMENT 'ID',
  - id_car_generation: `id_car_generation` int DEFAULT NULL,
  - id_car_serie: `id_car_serie` int DEFAULT NULL COMMENT 'ID',
  - id_car_trim: `id_car_trim` int DEFAULT NULL COMMENT 'ID',
  - year: `year` int NOT NULL,
  - id_car_type: `id_car_type` int NOT NULL COMMENT 'Тип транспорта',
