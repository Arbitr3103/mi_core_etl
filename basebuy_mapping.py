#!/usr/bin/env python3
"""
Маппинг данных BaseBuy -> mi_core_db.
Определяет соответствие между структурами данных.
"""

# МАППИНГ BASEBUY -> MI_CORE_DB
BASEBUY_MAPPING = {
    # Регионы (используем car_type как регионы)
    'regions': {
        'source_table': 'car_type',
        'source_file': 'car_type.csv',
        'mapping': {
            'name': 'name'  # car_type.name -> regions.name
        },
        'key_field': 'id_car_type'
    },
    
    # Марки автомобилей
    'brands': {
        'source_table': 'car_mark', 
        'source_file': 'car_mark.csv',
        'mapping': {
            'name': 'name_rus',  # Используем русское название
            'region_id': 'id_car_type'  # Связь с типом транспорта
        },
        'key_field': 'id_car_mark'
    },
    
    # Модели автомобилей
    'car_models': {
        'source_table': 'car_model',
        'source_file': 'car_model.csv', 
        'mapping': {
            'name': 'name_rus',  # Используем русское название
            'brand_id': 'id_car_mark'  # Связь с маркой
        },
        'key_field': 'id_car_model'
    },
    
    # Спецификации (поколения)
    'car_specifications': {
        'source_table': 'car_generation',
        'source_file': 'car_generation.csv',
        'mapping': {
            'car_model_id': 'id_car_model',
            'year_start': 'year_begin',
            'year_end': 'year_end',
            # PCD и DIA пока не найдены в структуре BaseBuy
            # Возможно, они в car_characteristic_value
            'pcd': None,  # Требует дополнительного анализа
            'dia': None,  # Требует дополнительного анализа
            'fastener_type': None,  # Требует дополнительного анализа
            'fastener_params': None  # Требует дополнительного анализа
        },
        'key_field': 'id_car_generation'
    }
}

# Дополнительные таблицы для поиска технических характеристик
TECHNICAL_SPECS_SOURCES = {
    'car_characteristic': {
        'file': 'car_characteristic.csv',
        'description': 'Справочник характеристик (PCD, DIA могут быть здесь)'
    },
    'car_characteristic_value': {
        'file': 'car_characteristic_value.csv', 
        'description': 'Значения характеристик по модификациям'
    },
    'car_modification': {
        'file': 'car_modification.csv',
        'description': 'Модификации автомобилей с годами производства'
    }
}

def get_mapping_for_table(table_name):
    """Возвращает маппинг для указанной таблицы."""
    return BASEBUY_MAPPING.get(table_name)

def get_all_source_files():
    """Возвращает список всех исходных CSV файлов."""
    files = []
    for table_config in BASEBUY_MAPPING.values():
        files.append(table_config['source_file'])
    return files

def get_technical_specs_files():
    """Возвращает файлы с техническими характеристиками."""
    return TECHNICAL_SPECS_SOURCES
