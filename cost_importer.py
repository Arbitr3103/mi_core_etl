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


def update_product_costs(df: pd.DataFrame) -> Tuple[int, int]:
    """
    Обновляет себестоимость товаров в базе данных с каскадным поиском.
    Сначала ищет по артикулу (sku_ozon), затем по штрихкоду (barcode).
    
    Args:
        df: DataFrame с данными (артикул, баркод, СС без НДС)
        
    Returns:
        Tuple[int, int]: (количество обновленных, количество не найденных)
    """
    connection = None
    cursor = None
    updated_count = 0
    not_found_count = 0
    
    # Определяем доступные колонки
    has_barcode = 'баркод' in df.columns
    has_article = 'артикул' in df.columns
    price_col = 'СС без НДС'
    
    try:
        # Подключаемся к базе данных
        connection = connect_to_db()
        cursor = connection.cursor()
        
        logger.info(f"🔄 Начинаем обновление себестоимости для {len(df)} товаров")
        
        # Обрабатываем каждую строку
        for index, row in df.iterrows():
            cost_price = row[price_col]
            updated = False
            
            try:
                # Каскадный поиск: сначала по артикулу (sku_ozon), если есть
                if has_article and pd.notna(row['артикул']) and str(row['артикул']).strip():
                    article = str(row['артикул']).strip()
                    sql_sku = "UPDATE dim_products SET cost_price = %s, updated_at = CURRENT_TIMESTAMP WHERE sku_ozon = %s"
                    cursor.execute(sql_sku, (cost_price, article))
                    
                    if cursor.rowcount > 0:
                        updated_count += 1
                        logger.info(f"✅ Обновлен товар по артикулу {article}: {cost_price}")
                        updated = True
                
                # Если не найден по артикулу, ищем по штрихкоду
                if not updated and has_barcode and pd.notna(row['баркод']) and str(row['баркод']).strip():
                    barcode = str(row['баркод']).strip()
                    sql_barcode = "UPDATE dim_products SET cost_price = %s, updated_at = CURRENT_TIMESTAMP WHERE barcode = %s"
                    cursor.execute(sql_barcode, (cost_price, barcode))
                    
                    if cursor.rowcount > 0:
                        updated_count += 1
                        logger.info(f"✅ Обновлен товар по штрихкоду {barcode}: {cost_price}")
                        updated = True
                
                # Если товар не найден ни по одному критерию
                if not updated:
                    not_found_count += 1
                    identifiers = []
                    if has_article and pd.notna(row['артикул']):
                        identifiers.append(f"артикул: {row['артикул']}")
                    if has_barcode and pd.notna(row['баркод']):
                        identifiers.append(f"баркод: {row['баркод']}")
                    logger.warning(f"⚠️ Товар не найден ({', '.join(identifiers)})")
                
            except Exception as e:
                logger.error(f"❌ Ошибка обновления товара: {e}")
                not_found_count += 1
        
        # Фиксируем изменения
        connection.commit()
        
        logger.info(f"✅ Обновление завершено. Обновлено: {updated_count}, не найдено: {not_found_count}")
        return updated_count, not_found_count
        
    except Exception as e:
        logger.error(f"❌ Ошибка при обновлении БД: {e}")
        if connection:
            connection.rollback()
        return 0, len(df)
        
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
        
        # Обновляем себестоимость в БД
        updated_count, not_found_count = update_product_costs(df)
        
        # Если обновления были успешны, архивируем файл
        if updated_count > 0:
            if archive_processed_file(cost_file_path):
                logger.info("✅ Импорт себестоимости завершен успешно")
            else:
                logger.warning("⚠️ Импорт выполнен, но файл не удалось заархивировать")
        else:
            logger.warning("⚠️ Ни одного товара не было обновлено. Файл не архивирован.")
        
        # Итоговая статистика
        logger.info(f"📊 ИТОГО: обновлено {updated_count} товаров, не найдено {not_found_count}")
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка в main(): {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
