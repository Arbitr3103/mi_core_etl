#!/usr/bin/env python3
"""
Скрипт первоначальной загрузки автомобильных данных из BaseBuy в mi_core_db.
Выполняет идемпотентную загрузку данных из CSV файлов.
"""

import os
import sys
import csv
import mysql.connector
from mysql.connector import Error
import logging
from dotenv import load_dotenv
from basebuy_mapping import BASEBUY_MAPPING

# Загружаем переменные из .env файла
load_dotenv()

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class InitialCarDataLoader:
    """Класс для первоначальной загрузки автомобильных данных."""
    
    def __init__(self):
        """Инициализация загрузчика."""
        self.db_config = {
            'host': os.getenv('DB_HOST', '127.0.0.1'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASSWORD'),
            'database': os.getenv('DB_NAME'),
            'charset': 'utf8mb4',
            'connection_timeout': 5
        }
        
        self.basebuy_data_dir = './basebuy_data/auto_20250901'
        
        # Маппинг BaseBuy ID -> mi_core_db ID для связей
        self.id_mappings = {
            'regions': {},  # basebuy_id -> mi_core_id
            'brands': {},
            'car_models': {},
            'car_specifications': {}
        }
    
    def connect_to_db(self):
        """Создает подключение к базе данных."""
        try:
            connection = mysql.connector.connect(**self.db_config)
            return connection
        except Error as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            raise
    
    def read_csv_file(self, filename):
        """Читает CSV файл и возвращает данные."""
        filepath = os.path.join(self.basebuy_data_dir, filename)
        
        if not os.path.exists(filepath):
            logger.error(f"Файл не найден: {filepath}")
            return []
        
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                # Убираем кавычки из заголовков и данных
                content = f.read().replace("'", "")
                
            # Парсим CSV из строки
            reader = csv.DictReader(content.splitlines())
            data = list(reader)
            
            logger.info(f"Прочитано {len(data)} записей из {filename}")
            return data
            
        except Exception as e:
            logger.error(f"Ошибка чтения файла {filename}: {e}")
            return []
    
    def load_regions(self):
        """Загружает регионы (типы транспорта)."""
        logger.info("🌍 Загружаем регионы...")
        
        config = BASEBUY_MAPPING['regions']
        data = self.read_csv_file(config['source_file'])
        
        if not data:
            logger.warning("Нет данных для загрузки регионов")
            return
        
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            for row in data:
                basebuy_id = row[config['key_field']]
                name = row[config['mapping']['name']]
                
                # Проверяем, существует ли уже такой регион
                cursor.execute(
                    "SELECT id FROM regions WHERE name = %s",
                    (name,)
                )
                existing = cursor.fetchone()
                
                if existing:
                    region_id = existing[0]
                    logger.debug(f"Регион '{name}' уже существует с ID {region_id}")
                else:
                    # Вставляем новый регион
                    cursor.execute(
                        "INSERT INTO regions (name) VALUES (%s)",
                        (name,)
                    )
                    region_id = cursor.lastrowid
                    logger.info(f"Добавлен регион: {name} (ID: {region_id})")
                
                # Сохраняем маппинг для связей
                self.id_mappings['regions'][basebuy_id] = region_id
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"✅ Загружено регионов: {len(self.id_mappings['regions'])}")
            
        except Error as e:
            logger.error(f"Ошибка загрузки регионов: {e}")
            raise
    
    def load_brands(self):
        """Загружает марки автомобилей."""
        logger.info("🚗 Загружаем марки автомобилей...")
        
        config = BASEBUY_MAPPING['brands']
        data = self.read_csv_file(config['source_file'])
        
        if not data:
            logger.warning("Нет данных для загрузки марок")
            return
        
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            for row in data:
                basebuy_id = row[config['key_field']]
                name = row[config['mapping']['name']]
                basebuy_region_id = row[config['mapping']['region_id']]
                
                # Получаем ID региона из маппинга
                region_id = self.id_mappings['regions'].get(basebuy_region_id)
                
                if not region_id:
                    logger.warning(f"Не найден регион для марки {name}, пропускаем")
                    continue
                
                # Проверяем, существует ли уже такая марка
                cursor.execute(
                    "SELECT id FROM brands WHERE name = %s AND region_id = %s",
                    (name, region_id)
                )
                existing = cursor.fetchone()
                
                if existing:
                    brand_id = existing[0]
                    logger.debug(f"Марка '{name}' уже существует с ID {brand_id}")
                else:
                    # Вставляем новую марку
                    cursor.execute(
                        "INSERT INTO brands (name, region_id) VALUES (%s, %s)",
                        (name, region_id)
                    )
                    brand_id = cursor.lastrowid
                    logger.info(f"Добавлена марка: {name} (ID: {brand_id})")
                
                # Сохраняем маппинг для связей
                self.id_mappings['brands'][basebuy_id] = brand_id
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"✅ Загружено марок: {len(self.id_mappings['brands'])}")
            
        except Error as e:
            logger.error(f"Ошибка загрузки марок: {e}")
            raise
    
    def load_car_models(self):
        """Загружает модели автомобилей."""
        logger.info("🚙 Загружаем модели автомобилей...")
        
        config = BASEBUY_MAPPING['car_models']
        data = self.read_csv_file(config['source_file'])
        
        if not data:
            logger.warning("Нет данных для загрузки моделей")
            return
        
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            for row in data:
                basebuy_id = row[config['key_field']]
                name = row[config['mapping']['name']]
                basebuy_brand_id = row[config['mapping']['brand_id']]
                
                # Получаем ID марки из маппинга
                brand_id = self.id_mappings['brands'].get(basebuy_brand_id)
                
                if not brand_id:
                    logger.warning(f"Не найдена марка для модели {name}, пропускаем")
                    continue
                
                # Проверяем, существует ли уже такая модель
                cursor.execute(
                    "SELECT id FROM car_models WHERE name = %s AND brand_id = %s",
                    (name, brand_id)
                )
                existing = cursor.fetchone()
                
                if existing:
                    model_id = existing[0]
                    logger.debug(f"Модель '{name}' уже существует с ID {model_id}")
                else:
                    # Вставляем новую модель
                    cursor.execute(
                        "INSERT INTO car_models (name, brand_id) VALUES (%s, %s)",
                        (name, brand_id)
                    )
                    model_id = cursor.lastrowid
                    logger.info(f"Добавлена модель: {name} (ID: {model_id})")
                
                # Сохраняем маппинг для связей
                self.id_mappings['car_models'][basebuy_id] = model_id
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"✅ Загружено моделей: {len(self.id_mappings['car_models'])}")
            
        except Error as e:
            logger.error(f"Ошибка загрузки моделей: {e}")
            raise
    
    def load_car_specifications(self):
        """Загружает спецификации автомобилей (поколения)."""
        logger.info("⚙️ Загружаем спецификации автомобилей...")
        
        config = BASEBUY_MAPPING['car_specifications']
        data = self.read_csv_file(config['source_file'])
        
        if not data:
            logger.warning("Нет данных для загрузки спецификаций")
            return
        
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            for row in data:
                basebuy_id = row[config['key_field']]
                basebuy_model_id = row[config['mapping']['car_model_id']]
                year_start = row[config['mapping']['year_start']] or None
                year_end = row[config['mapping']['year_end']] or None
                
                # Получаем ID модели из маппинга
                car_model_id = self.id_mappings['car_models'].get(basebuy_model_id)
                
                if not car_model_id:
                    logger.warning(f"Не найдена модель для спецификации {basebuy_id}, пропускаем")
                    continue
                
                # Конвертируем годы в числа
                try:
                    year_start = int(year_start) if year_start else None
                    year_end = int(year_end) if year_end else None
                except (ValueError, TypeError):
                    logger.warning(f"Некорректные годы для спецификации {basebuy_id}: {year_start}-{year_end}")
                    year_start = year_end = None
                
                # Пропускаем записи без year_start (критически важное поле)
                if year_start is None:
                    logger.warning(f"Пропускаем спецификацию {basebuy_id}: отсутствует year_start")
                    continue
                
                # Проверяем, существует ли уже такая спецификация
                cursor.execute("""
                    SELECT id FROM car_specifications 
                    WHERE car_model_id = %s AND year_start = %s AND year_end = %s
                """, (car_model_id, year_start, year_end))
                existing = cursor.fetchone()
                
                if existing:
                    spec_id = existing[0]
                    logger.debug(f"Спецификация уже существует с ID {spec_id}")
                else:
                    # Вставляем новую спецификацию
                    # PCD, DIA, fastener_type, fastener_params пока оставляем NULL
                    cursor.execute("""
                        INSERT INTO car_specifications 
                        (car_model_id, year_start, year_end, pcd, dia, fastener_type, fastener_params)
                        VALUES (%s, %s, %s, %s, %s, %s, %s)
                    """, (car_model_id, year_start, year_end, None, None, None, None))
                    spec_id = cursor.lastrowid
                    logger.info(f"Добавлена спецификация: модель {car_model_id}, {year_start}-{year_end} (ID: {spec_id})")
                
                # Сохраняем маппинг для связей
                self.id_mappings['car_specifications'][basebuy_id] = spec_id
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"✅ Загружено спецификаций: {len(self.id_mappings['car_specifications'])}")
            
        except Error as e:
            logger.error(f"Ошибка загрузки спецификаций: {e}")
            raise
    
    def get_statistics(self):
        """Выводит статистику загруженных данных."""
        logger.info("📊 Получаем статистику загруженных данных...")
        
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            tables = ['regions', 'brands', 'car_models', 'car_specifications']
            
            print("\n📈 СТАТИСТИКА ЗАГРУЗКИ:")
            print("=" * 50)
            
            for table in tables:
                cursor.execute(f"SELECT COUNT(*) FROM {table}")
                count = cursor.fetchone()[0]
                print(f"{table:20}: {count:6} записей")
            
            # Проверяем связи
            print("\n🔗 ПРОВЕРКА СВЯЗЕЙ:")
            print("=" * 50)
            
            cursor.execute("""
                SELECT b.name as brand, r.name as region 
                FROM brands b 
                JOIN regions r ON b.region_id = r.id 
                LIMIT 5
            """)
            
            print("Примеры марок с регионами:")
            for row in cursor.fetchall():
                print(f"  {row[0]} ({row[1]})")
            
            cursor.execute("""
                SELECT cm.name as model, b.name as brand
                FROM car_models cm
                JOIN brands b ON cm.brand_id = b.id
                LIMIT 5
            """)
            
            print("\nПримеры моделей с марками:")
            for row in cursor.fetchall():
                print(f"  {row[0]} ({row[1]})")
            
            cursor.close()
            connection.close()
            
        except Error as e:
            logger.error(f"Ошибка получения статистики: {e}")
    
    def run_initial_load(self):
        """Запускает полную первоначальную загрузку."""
        logger.info("🚀 Начинаем первоначальную загрузку автомобильных данных")
        
        try:
            # Загружаем данные в правильном порядке (учитывая зависимости)
            self.load_regions()
            self.load_brands()
            self.load_car_models()
            self.load_car_specifications()
            
            # Выводим статистику
            self.get_statistics()
            
            logger.info("🎉 Первоначальная загрузка завершена успешно!")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при загрузке: {e}")
            raise


def main():
    """Главная функция."""
    # Проверяем переменные окружения
    required_vars = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME']
    missing_vars = [var for var in required_vars if not os.getenv(var)]
    
    if missing_vars:
        logger.error(f"Не заданы переменные окружения: {missing_vars}")
        logger.error("Создайте .env файл на основе .env.example")
        
        # Отладочная информация
        logger.info("Отладка переменных окружения:")
        for var in required_vars:
            value = os.getenv(var)
            logger.info(f"  {var} = {'***' if 'PASSWORD' in var and value else value}")
        
        return 1
    
    # Запускаем загрузку
    loader = InitialCarDataLoader()
    loader.run_initial_load()
    
    return 0


if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)
