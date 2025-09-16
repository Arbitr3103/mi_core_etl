#!/usr/bin/env python3
"""
Скрипт для анализа структуры MySQL дампа от BaseBuy.ru.
Помогает понять структуру их таблиц для создания маппинга.
"""

import os
import sys
import requests
import zipfile
import logging

# Настройка логирования
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

class BaseBuyDumpAnalyzer:
    """Класс для анализа дампа BaseBuy."""
    
    def __init__(self):
        self.mysql_url = "https://yadi.sk/d/8wvOhKEXtQDYEg"
        self.csv_url = "https://yadi.sk/d/IaStb5-Kl_96Iw"
        # Путь к папке с данными, которая лежит РЯДОМ с проектом, а не ВНУТРИ
        project_dir = os.path.dirname(os.path.abspath(__file__))
        data_dir = os.path.join(os.path.dirname(project_dir), 'project_data')
        self.download_dir = os.path.join(data_dir, "basebuy_data")
        
        # Создаем директорию для загрузки
        os.makedirs(self.download_dir, exist_ok=True)
    
    def download_files(self):
        """Скачивает файлы дампа."""
        logger.info("📥 Начинаем скачивание файлов BaseBuy...")
        
        # Для Яндекс.Диска нужно получить прямые ссылки
        logger.warning("⚠️ Ссылки ведут на Яндекс.Диск - требуется ручное скачивание")
        logger.info(f"MySQL дамп: {self.mysql_url}")
        logger.info(f"CSV файлы: {self.csv_url}")
        
        print("""
🔗 ИНСТРУКЦИЯ ПО СКАЧИВАНИЮ:

1. Перейдите по ссылкам:
   MySQL: https://yadi.sk/d/8wvOhKEXtQDYEg
   CSV: https://yadi.sk/d/IaStb5-Kl_96Iw

2. Скачайте файлы в папку: ../project_data/basebuy_data/

3. Запустите анализ повторно: python3 analyze_basebuy_dump.py --analyze

Альтернативно, если у вас есть прямые ссылки на файлы, 
обновите этот скрипт с правильными URL.
        """)
    
    def analyze_mysql_dump(self, dump_file):
        """Анализирует структуру MySQL дампа."""
        logger.info(f"🔍 Анализируем MySQL дамп: {dump_file}")
        
        try:
            with open(dump_file, 'r', encoding='utf-8') as f:
                content = f.read()
            
            # Ищем CREATE TABLE statements
            tables = {}
            lines = content.split('\n')
            current_table = None
            
            for line in lines:
                line = line.strip()
                
                # Начало создания таблицы
                if line.startswith('CREATE TABLE'):
                    table_name = line.split('`')[1] if '`' in line else line.split()[2]
                    current_table = table_name
                    tables[current_table] = {'columns': [], 'indexes': []}
                    logger.info(f"Найдена таблица: {table_name}")
                
                # Колонки таблицы
                elif current_table and line.startswith('`') and 'KEY' not in line:
                    column_def = line.split('`')[1] if '`' in line else line.split()[0]
                    column_type = line.split('`')[2].strip() if '`' in line else ''
                    tables[current_table]['columns'].append({
                        'name': column_def,
                        'definition': line
                    })
                
                # Индексы
                elif current_table and ('KEY' in line or 'INDEX' in line):
                    tables[current_table]['indexes'].append(line)
                
                # Конец таблицы
                elif line.startswith(');') or line.startswith(') ENGINE'):
                    current_table = None
            
            return tables
            
        except Exception as e:
            logger.error(f"Ошибка анализа дампа: {e}")
            return {}
    
    def analyze_csv_files(self, csv_dir):
        """Анализирует CSV файлы."""
        logger.info(f"📊 Анализируем CSV файлы в: {csv_dir}")
        
        csv_files = {}
        
        try:
            for filename in os.listdir(csv_dir):
                if filename.endswith('.csv'):
                    filepath = os.path.join(csv_dir, filename)
                    
                    with open(filepath, 'r', encoding='utf-8') as f:
                        # Читаем первые несколько строк для анализа структуры
                        lines = [f.readline().strip() for _ in range(5)]
                    
                    headers = lines[0].split(',') if lines else []
                    
                    csv_files[filename] = {
                        'headers': headers,
                        'sample_lines': lines[1:4] if len(lines) > 1 else []
                    }
                    
                    logger.info(f"CSV файл: {filename}, колонки: {len(headers)}")
            
            return csv_files
            
        except Exception as e:
            logger.error(f"Ошибка анализа CSV: {e}")
            return {}
    
    def create_mapping_template(self, tables_info):
        """Создает шаблон маппинга между BaseBuy и нашими таблицами."""
        logger.info("🗺️ Создаем шаблон маппинга...")
        
        mapping_template = """
# МАППИНГ ДАННЫХ BASEBUY -> MI_CORE_DB
# Создано автоматически на основе анализа дампа

## Наши целевые таблицы:
# - regions (id, name)
# - brands (id, name, region_id)  
# - car_models (id, name, brand_id)
# - car_specifications (id, car_model_id, year_start, year_end, pcd, dia, fastener_type, fastener_params)

## Найденные таблицы BaseBuy:
"""
        
        for table_name, table_info in tables_info.items():
            mapping_template += f"\n### Таблица: {table_name}\n"
            mapping_template += "Колонки:\n"
            
            for column in table_info['columns']:
                mapping_template += f"  - {column['name']}: {column['definition']}\n"
            
            # Предполагаемый маппинг
            if 'region' in table_name.lower() or 'область' in table_name.lower():
                mapping_template += f"\n🎯 ВОЗМОЖНЫЙ МАППИНГ -> regions:\n"
                mapping_template += f"  {table_name}.??? -> regions.name\n"
            
            elif 'brand' in table_name.lower() or 'марк' in table_name.lower():
                mapping_template += f"\n🎯 ВОЗМОЖНЫЙ МАППИНГ -> brands:\n"
                mapping_template += f"  {table_name}.??? -> brands.name\n"
                mapping_template += f"  {table_name}.??? -> brands.region_id\n"
            
            elif 'model' in table_name.lower() or 'модел' in table_name.lower():
                mapping_template += f"\n🎯 ВОЗМОЖНЫЙ МАППИНГ -> car_models:\n"
                mapping_template += f"  {table_name}.??? -> car_models.name\n"
                mapping_template += f"  {table_name}.??? -> car_models.brand_id\n"
            
            elif 'spec' in table_name.lower() or 'generation' in table_name.lower() or 'поколен' in table_name.lower():
                mapping_template += f"\n🎯 ВОЗМОЖНЫЙ МАППИНГ -> car_specifications:\n"
                mapping_template += f"  {table_name}.??? -> car_specifications.year_start\n"
                mapping_template += f"  {table_name}.??? -> car_specifications.year_end\n"
                mapping_template += f"  {table_name}.??? -> car_specifications.pcd\n"
                mapping_template += f"  {table_name}.??? -> car_specifications.dia\n"
        
        # Сохраняем шаблон
        with open('./basebuy_mapping_template.md', 'w', encoding='utf-8') as f:
            f.write(mapping_template)
        
        logger.info("✅ Шаблон маппинга сохранен в: basebuy_mapping_template.md")
        
        return mapping_template
    
    def run_analysis(self):
        """Запускает полный анализ."""
        logger.info("🚀 Запуск анализа структуры BaseBuy")
        
        # Проверяем наличие скачанных файлов
        mysql_files = [f for f in os.listdir(self.download_dir) if f.endswith('.sql')]
        csv_files = [f for f in os.listdir(self.download_dir) if f.endswith('.csv')]
        
        if not mysql_files and not csv_files:
            logger.warning("Файлы не найдены, запускаем скачивание...")
            self.download_files()
            return
        
        all_tables = {}
        
        # Анализируем MySQL дампы
        for sql_file in mysql_files:
            filepath = os.path.join(self.download_dir, sql_file)
            tables = self.analyze_mysql_dump(filepath)
            all_tables.update(tables)
        
        # Анализируем CSV файлы
        if csv_files:
            csv_info = self.analyze_csv_files(self.download_dir)
            logger.info(f"Найдено CSV файлов: {len(csv_info)}")
        
        # Создаем маппинг
        if all_tables:
            self.create_mapping_template(all_tables)
            
            # Выводим краткий отчет
            print(f"\n📊 ОТЧЕТ ПО АНАЛИЗУ:")
            print(f"Найдено таблиц: {len(all_tables)}")
            
            for table_name, info in all_tables.items():
                print(f"  - {table_name}: {len(info['columns'])} колонок")
        
        else:
            logger.warning("Таблицы не найдены в дампе")


def main():
    """Главная функция."""
    analyzer = BaseBuyDumpAnalyzer()
    
    if len(sys.argv) > 1 and sys.argv[1] == '--analyze':
        analyzer.run_analysis()
    else:
        analyzer.download_files()


if __name__ == "__main__":
    main()
