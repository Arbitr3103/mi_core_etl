"""
Скрипт автоматического импорта себестоимости товаров из Excel файлов.

Функционал:
- Поиск файла cost_price.xlsx в папке uploads/
- Чтение данных из Excel (колонки: barcode, cost_price)
- Обновление таблицы dim_products по штрихкоду
- Архивация обработанных файлов с датой
- Полное логирование процесса

Автор: ETL система mi_core
"""

import os
import sys
import logging
import pandas as pd
import shutil
from datetime import datetime
from pathlib import Path
from typing import Optional, Tuple

# Добавляем путь к модулю importers для подключения к БД
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Константы
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
UPLOADS_DIR = os.path.join(BASE_DIR, "uploads")
ARCHIVE_DIR = os.path.join(BASE_DIR, "uploads", "archive")
COST_FILE_NAME = "cost_price.xlsx"
EXPECTED_COLUMNS = ['баркод', 'артикул', 'СС без НДС']


def ensure_directories():
    """Создает необходимые директории если их нет."""
    Path(UPLOADS_DIR).mkdir(parents=True, exist_ok=True)
    Path(ARCHIVE_DIR).mkdir(parents=True, exist_ok=True)
    logger.info(f"Проверены директории: {UPLOADS_DIR}, {ARCHIVE_DIR}")


def find_cost_file() -> Optional[str]:
    """
    Ищет файл cost_price.xlsx в папке uploads.
    
    Returns:
        Optional[str]: Полный путь к файлу или None если не найден
    """
    cost_file_path = os.path.join(UPLOADS_DIR, COST_FILE_NAME)
    
    if os.path.exists(cost_file_path):
        logger.info(f"✅ Найден файл себестоимости: {cost_file_path}")
        return cost_file_path
    else:
        logger.info(f"📁 Файл {COST_FILE_NAME} не найден в {UPLOADS_DIR}")
        return None


def validate_excel_structure(df: pd.DataFrame) -> bool:
    """
    Проверяет структуру Excel файла.
    
    Args:
        df: DataFrame с данными из Excel
        
    Returns:
        bool: True если структура корректна
    """
    # Проверяем наличие обязательных колонок (нужны баркод ИЛИ артикул + цена)
    required_price_col = 'СС без НДС'
    has_barcode = 'баркод' in df.columns
    has_article = 'артикул' in df.columns
    has_price = required_price_col in df.columns
    
    if not has_price:
        logger.error(f"❌ Отсутствует обязательная колонка: {required_price_col}")
        logger.error(f"Найденные колонки: {list(df.columns)}")
        return False
    
    if not (has_barcode or has_article):
        logger.error("❌ Отсутствуют колонки идентификации товара: нужен 'баркод' или 'артикул'")
        logger.error(f"Найденные колонки: {list(df.columns)}")
        return False
    
    # Проверяем наличие данных
    if df.empty:
        logger.error("❌ Excel файл пустой")
        return False
    
    # Проверяем наличие пустых значений в ключевых колонках
    if has_barcode:
        null_barcodes = df['баркод'].isnull().sum()
        if null_barcodes > 0:
            logger.warning(f"⚠️ Найдено {null_barcodes} пустых штрихкодов")
    
    if has_article:
        null_articles = df['артикул'].isnull().sum()
        if null_articles > 0:
            logger.warning(f"⚠️ Найдено {null_articles} пустых артикулов")
    
    null_prices = df[required_price_col].isnull().sum()
    if null_prices > 0:
        logger.warning(f"⚠️ Найдено {null_prices} пустых цен (будут пропущены)")
    
    logger.info(f"📊 Структура файла корректна. Строк данных: {len(df)}")
    logger.info(f"📋 Доступные колонки: баркод={has_barcode}, артикул={has_article}, цена={has_price}")
    return True


def read_cost_file(file_path: str) -> Optional[pd.DataFrame]:
    """
    Читает Excel файл с себестоимостью.
    
    Args:
        file_path: Путь к Excel файлу
        
    Returns:
        Optional[pd.DataFrame]: DataFrame с данными или None при ошибке
    """
    try:
        logger.info(f"📖 Читаем Excel файл: {file_path}")
        
        # Читаем Excel файл
        df = pd.read_excel(file_path)
        
        # Валидируем структуру
        if not validate_excel_structure(df):
            return None
        
        # Определяем доступные колонки
        has_barcode = 'баркод' in df.columns
        has_article = 'артикул' in df.columns
        price_col = 'СС без НДС'
        
        # Очищаем данные от пустых значений цены
        df_clean = df.dropna(subset=[price_col])
        
        # Конвертируем цену в числовой формат
        df_clean[price_col] = pd.to_numeric(df_clean[price_col], errors='coerce')
        
        # Очищаем строки с некорректными ценами
        df_clean = df_clean.dropna(subset=[price_col])
        df_clean = df_clean[df_clean[price_col] > 0]
        
        # Обрабатываем идентификаторы товаров
        if has_barcode:
            df_clean['баркод'] = df_clean['баркод'].astype(str).str.strip()
        if has_article:
            df_clean['артикул'] = df_clean['артикул'].astype(str).str.strip()
        
        
        logger.info(f"✅ Файл успешно прочитан. Валидных записей: {len(df_clean)}")
        return df_clean
        
    except Exception as e:
        logger.error(f"❌ Ошибка чтения Excel файла: {e}")
        return None


def update_product_costs(df: pd.DataFrame) -> Tuple[int, int, int]:
    """
    Обновляет/создает товары в справочнике dim_products с UPSERT логикой.
    Основной ключ - штрихкод (barcode). Если товар найден - обновляет, если нет - создает.
    
    Args:
        df: DataFrame с данными (артикул, баркод, СС без НДС)
        
    Returns:
        Tuple[int, int, int]: (количество обновленных, количество созданных, количество ошибок)
    """
    connection = None
    cursor = None
    updated_count = 0
    created_count = 0
    error_count = 0
    
    # Определяем доступные колонки
    has_barcode = 'баркод' in df.columns
    has_article = 'артикул' in df.columns
    price_col = 'СС без НДС'
    
    try:
        # Подключаемся к базе данных
        connection = connect_to_db()
        cursor = connection.cursor()
        
        logger.info(f"🔄 Начинаем UPSERT обработку для {len(df)} товаров")
        
        # Обрабатываем каждую строку
        for index, row in df.iterrows():
            cost_price = row[price_col]
            barcode = str(row['баркод']).strip() if has_barcode and pd.notna(row['баркод']) else None
            article = str(row['артикул']).strip() if has_article and pd.notna(row['артикул']) else None
            
            # Штрихкод - основной ключ, без него не обрабатываем
            if not barcode:
                logger.warning(f"⚠️ Пропускаем строку без штрихкода: артикул={article}")
                error_count += 1
                continue
            
            try:
                # Шаг 1: Ищем товар по штрихкоду
                cursor.execute("SELECT id FROM dim_products WHERE barcode = %s", (barcode,))
                existing_product = cursor.fetchone()
                
                if existing_product:
                    # Товар найден - обновляем
                    product_id = existing_product['id']
                    update_sql = """
                        UPDATE dim_products 
                        SET cost_price = %s, 
                            sku_internal = %s,
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = %s
                    """
                    cursor.execute(update_sql, (cost_price, article, product_id))
                    updated_count += 1
                    logger.info(f"✅ Обновлен товар {barcode} (ID: {product_id}): цена={cost_price}, артикул={article}")
                    
                else:
                    # Товар не найден - создаем новый
                    insert_sql = """
                        INSERT INTO dim_products (barcode, sku_internal, cost_price, created_at, updated_at)
                        VALUES (%s, %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    """
                    cursor.execute(insert_sql, (barcode, article, cost_price))
                    new_product_id = cursor.lastrowid
                    created_count += 1
                    logger.info(f"🆕 Создан новый товар {barcode} (ID: {new_product_id}): цена={cost_price}, артикул={article}")
                
            except Exception as e:
                logger.error(f"❌ Ошибка обработки товара {barcode}: {e}")
                error_count += 1
        
        # Фиксируем изменения
        connection.commit()
        
        logger.info(f"✅ UPSERT завершен. Обновлено: {updated_count}, создано: {created_count}, ошибок: {error_count}")
        return updated_count, created_count, error_count
        
    except Exception as e:
        logger.error(f"❌ Ошибка при обновлении БД: {e}")
        if connection:
            connection.rollback()
        return 0, 0, len(df)
        
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()


def archive_processed_file(file_path: str) -> bool:
    """
    Архивирует обработанный файл с добавлением даты.
    
    Args:
        file_path: Путь к обработанному файлу
        
    Returns:
        bool: True если архивация успешна
    """
    try:
        # Генерируем имя архивного файла с датой
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        file_name = os.path.basename(file_path)
        name_without_ext = os.path.splitext(file_name)[0]
        extension = os.path.splitext(file_name)[1]
        
        archive_filename = f"{name_without_ext}_{timestamp}{extension}"
        archive_path = os.path.join(ARCHIVE_DIR, archive_filename)
        
        # Перемещаем файл в архив
        shutil.move(file_path, archive_path)
        
        logger.info(f"📦 Файл заархивирован: {archive_path}")
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка архивации файла: {e}")
        return False


def main():
    """Основная функция скрипта."""
    logger.info("🚀 Запуск импорта себестоимости товаров")
    
    try:
        # Создаем необходимые директории
        ensure_directories()
        
        # Ищем файл себестоимости
        cost_file_path = find_cost_file()
        if not cost_file_path:
            logger.info("📂 Файлов для обработки не найдено. Завершение.")
            return
        
        # Читаем данные из Excel
        df = read_cost_file(cost_file_path)
        if df is None or df.empty:
            logger.error("❌ Не удалось прочитать данные из файла")
            return
        
        # Обновляем/создаем товары в БД с UPSERT логикой
        updated_count, created_count, error_count = update_product_costs(df)
        
        # Если были успешные операции, архивируем файл
        total_success = updated_count + created_count
        if total_success > 0:
            if archive_processed_file(cost_file_path):
                logger.info("✅ Импорт себестоимости завершен успешно")
            else:
                logger.warning("⚠️ Импорт выполнен, но файл не удалось заархивировать")
        else:
            logger.warning("⚠️ Ни одного товара не было обработано. Файл не архивирован.")
        
        # Итоговая статистика
        logger.info(f"📊 ИТОГО: обновлено {updated_count}, создано {created_count}, ошибок {error_count}")
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка в main(): {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
