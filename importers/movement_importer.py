#!/usr/bin/env python3
"""
Модуль импорта движений товаров с маркетплейсов Ozon и Wildberries.

Функционал:
- Получение истории движений товаров через API Ozon и Wildberries
- Запись в таблицу stock_movements с защитой от дубликатов
- Обработка различных типов операций (продажи, возвраты, списания)
- Детальное логирование всех операций

Автор: ETL System
Дата: 20 сентября 2025
"""

import os
import sys
import logging
import requests
import time
from datetime import datetime, timedelta
from typing import List, Dict, Any, Optional

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

from ozon_importer import connect_to_db
import config

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class MovementImporter:
    """Класс для импорта движений товаров с маркетплейсов."""
    
    def __init__(self):
        """Инициализация импортера."""
        self.connection = None
        self.cursor = None
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            logger.info("Успешное подключение к базе данных")
        except Exception as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            raise
    
    def close_database_connection(self):
        """Закрытие подключения к базе данных."""
        if self.cursor:
            self.cursor.close()
        if self.connection:
            self.connection.close()
        logger.info("Подключение к БД закрыто")

    def get_ozon_movements(self, hours_back: int = 24) -> List[Dict[str, Any]]:
        """
        Получение движений товаров с Ozon за указанный период.
        
        Args:
            hours_back: Количество часов назад для получения данных
            
        Returns:
            List[Dict]: Список движений товаров
        """
        logger.info(f"🔄 Начинаем получение движений с Ozon за последние {hours_back} часов...")
        
        movements_data = []
        
        # Определяем временной диапазон
        end_date = datetime.now()
        start_date = end_date - timedelta(hours=hours_back)
        
        try:
            # Получаем FBO отправления
            fbo_movements = self._get_ozon_fbo_movements(start_date, end_date)
            movements_data.extend(fbo_movements)
            
            # Получаем FBS отправления
            fbs_movements = self._get_ozon_fbs_movements(start_date, end_date)
            movements_data.extend(fbs_movements)
            
        except Exception as e:
            logger.error(f"Ошибка при получении движений Ozon: {e}")
            raise
        
        logger.info(f"✅ Получено {len(movements_data)} движений с Ozon")
        return movements_data

    def _get_ozon_fbo_movements(self, start_date: datetime, end_date: datetime) -> List[Dict[str, Any]]:
        """Получение FBO движений с Ozon."""
        url = "https://api-seller.ozon.ru/v2/posting/fbo/list"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        movements = []
        offset = 0
        limit = 1000
        
        try:
            while True:
                payload = {
                    "dir": "ASC",
                    "filter": {
                        "since": start_date.isoformat(),
                        "to": end_date.isoformat(),
                        "status": ""
                    },
                    "limit": limit,
                    "offset": offset,
                    "with": {
                        "analytics_data": True,
                        "financial_data": True
                    }
                }
                
                response = requests.post(url, json=payload, headers=headers)
                response.raise_for_status()
                
                data = response.json()
                postings = data.get('result', [])
                
                if not postings:
                    break
                
                logger.info(f"Получено {len(postings)} FBO отправлений (offset: {offset})")
                
                for posting in postings:
                    posting_movements = self._process_ozon_posting(posting, 'FBO')
                    movements.extend(posting_movements)
                
                if len(postings) < limit:
                    break
                    
                offset += limit
                time.sleep(0.1)
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе FBO движений Ozon: {e}")
            raise
        
        return movements

    def _get_ozon_fbs_movements(self, start_date: datetime, end_date: datetime) -> List[Dict[str, Any]]:
        """Получение FBS движений с Ozon."""
        url = "https://api-seller.ozon.ru/v3/posting/fbs/list"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        movements = []
        offset = 0
        limit = 1000
        
        try:
            while True:
                payload = {
                    "dir": "ASC",
                    "filter": {
                        "since": start_date.isoformat(),
                        "to": end_date.isoformat(),
                        "status": ""
                    },
                    "limit": limit,
                    "offset": offset,
                    "with": {
                        "analytics_data": True,
                        "financial_data": True
                    }
                }
                
                response = requests.post(url, json=payload, headers=headers)
                response.raise_for_status()
                
                data = response.json()
                postings = data.get('result', [])
                
                if not postings:
                    break
                
                logger.info(f"Получено {len(postings)} FBS отправлений (offset: {offset})")
                
                for posting in postings:
                    posting_movements = self._process_ozon_posting(posting, 'FBS')
                    movements.extend(posting_movements)
                
                if len(postings) < limit:
                    break
                    
                offset += limit
                time.sleep(0.1)
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе FBS движений Ozon: {e}")
            raise
        
        return movements

    def _process_ozon_posting(self, posting: Dict[str, Any], stock_type: str) -> List[Dict[str, Any]]:
        """
        Обработка отправления Ozon и создание движений.
        
        Args:
            posting: Данные отправления
            stock_type: Тип склада (FBO/FBS)
            
        Returns:
            List[Dict]: Список движений для данного отправления
        """
        movements = []
        
        posting_number = posting.get('posting_number', '')
        order_date = posting.get('created_at', '')
        status = posting.get('status', '')
        
        # Определяем тип движения на основе статуса
        movement_type = self._map_ozon_status_to_movement_type(status)
        
        if not movement_type:
            return movements
        
        # Обрабатываем товары в отправлении
        for product in posting.get('products', []):
            offer_id = product.get('offer_id', '')
            quantity = product.get('quantity', 0)
            
            # Получаем product_id из БД
            product_id = self._get_product_id_by_ozon_sku(offer_id)
            
            if not product_id:
                logger.warning(f"Товар с offer_id {offer_id} не найден в БД")
                continue
            
            # Создаем запись движения
            movement = {
                'movement_id': f"{posting_number}_{offer_id}",
                'product_id': product_id,
                'movement_date': order_date,
                'movement_type': movement_type,
                'quantity': -quantity if movement_type == 'sale' else quantity,  # Отрицательное для продаж
                'warehouse_name': posting.get('warehouse_name', f'{stock_type}-Склад'),
                'order_id': posting_number,
                'source': 'Ozon'
            }
            
            movements.append(movement)
        
        return movements

    def _map_ozon_status_to_movement_type(self, status: str) -> Optional[str]:
        """
        Маппинг статусов Ozon на типы движений.
        
        Args:
            status: Статус отправления Ozon
            
        Returns:
            str: Тип движения или None
        """
        status_mapping = {
            'delivered': 'sale',
            'delivering': 'sale',
            'cancelled': 'return',
            'returned': 'return',
            'not_accepted': 'return'
        }
        
        return status_mapping.get(status.lower())

    def get_wb_movements(self, hours_back: int = 24) -> List[Dict[str, Any]]:
        """
        Получение движений товаров с Wildberries за указанный период.
        
        Args:
            hours_back: Количество часов назад для получения данных
            
        Returns:
            List[Dict]: Список движений товаров
        """
        logger.info(f"🔄 Начинаем получение движений с Wildberries за последние {hours_back} часов...")
        
        movements_data = []
        
        # Определяем временной диапазон
        end_date = datetime.now()
        start_date = end_date - timedelta(hours=hours_back)
        
        try:
            # Получаем детальный отчет по операциям
            wb_movements = self._get_wb_detailed_report(start_date, end_date)
            movements_data.extend(wb_movements)
            
        except Exception as e:
            logger.error(f"Ошибка при получении движений WB: {e}")
            raise
        
        logger.info(f"✅ Получено {len(movements_data)} движений с Wildberries")
        return movements_data

    def _get_wb_detailed_report(self, start_date: datetime, end_date: datetime) -> List[Dict[str, Any]]:
        """Получение детального отчета по операциям WB."""
        url = "https://suppliers-api.wildberries.ru/api/v1/supplier/reportDetailByPeriod"
        headers = {
            "Authorization": config.WB_API_TOKEN
        }
        
        params = {
            "dateFrom": start_date.strftime('%Y-%m-%d'),
            "dateTo": end_date.strftime('%Y-%m-%d'),
            "limit": 100000,
            "rrdid": 0
        }
        
        movements = []
        
        try:
            response = requests.get(url, headers=headers, params=params)
            response.raise_for_status()
            
            data = response.json()
            
            for item in data:
                movement = self._process_wb_report_item(item)
                if movement:
                    movements.append(movement)
                    
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе отчета WB: {e}")
            raise
        
        return movements

    def _process_wb_report_item(self, item: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        """
        Обработка элемента отчета WB и создание движения.
        
        Args:
            item: Элемент отчета WB
            
        Returns:
            Dict: Данные движения или None
        """
        supplier_article = item.get('supplierArticle', '')
        nm_id = item.get('nmId', '')
        doc_type_name = item.get('docTypeName', '')
        quantity = item.get('quantity', 0)
        date_str = item.get('date', '')
        
        # Определяем тип движения
        movement_type = self._map_wb_doc_type_to_movement_type(doc_type_name)
        
        if not movement_type:
            return None
        
        # Получаем product_id из БД
        product_id = self._get_product_id_by_wb_sku(str(nm_id))
        
        if not product_id:
            logger.warning(f"Товар с nmId {nm_id} не найден в БД")
            return None
        
        # Создаем уникальный ID движения
        movement_id = f"wb_{nm_id}_{date_str}_{doc_type_name}_{item.get('rrdid', 0)}"
        
        movement = {
            'movement_id': movement_id,
            'product_id': product_id,
            'movement_date': date_str,
            'movement_type': movement_type,
            'quantity': -quantity if movement_type == 'sale' else quantity,
            'warehouse_name': item.get('warehouseName', 'WB-Склад'),
            'order_id': item.get('srid', ''),
            'source': 'Wildberries'
        }
        
        return movement

    def _map_wb_doc_type_to_movement_type(self, doc_type: str) -> Optional[str]:
        """
        Маппинг типов документов WB на типы движений.
        
        Args:
            doc_type: Тип документа WB
            
        Returns:
            str: Тип движения или None
        """
        doc_type_mapping = {
            'Продажа': 'sale',
            'Возврат': 'return',
            'Списание': 'disposal',
            'Потеря': 'loss',
            'Недостача': 'shortage',
            'Брак': 'defect'
        }
        
        return doc_type_mapping.get(doc_type)

    def _get_product_id_by_ozon_sku(self, sku_ozon: str) -> Optional[int]:
        """Получение product_id по SKU Ozon."""
        if not sku_ozon:
            return None
            
        try:
            self.cursor.execute(
                "SELECT id FROM dim_products WHERE sku_ozon = %s",
                (sku_ozon,)
            )
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            logger.error(f"Ошибка при поиске товара по sku_ozon {sku_ozon}: {e}")
            return None

    def _get_product_id_by_wb_sku(self, sku_wb: str) -> Optional[int]:
        """Получение product_id по SKU Wildberries."""
        if not sku_wb:
            return None
            
        try:
            self.cursor.execute(
                "SELECT id FROM dim_products WHERE sku_wb = %s",
                (sku_wb,)
            )
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            logger.error(f"Ошибка при поиске товара по sku_wb {sku_wb}: {e}")
            return None

    def add_movements(self, movements_data: List[Dict[str, Any]], source: str):
        """
        Добавление движений в таблицу stock_movements с защитой от дубликатов.
        
        Args:
            movements_data: Список данных о движениях
            source: Источник данных ('Ozon' или 'Wildberries')
        """
        logger.info(f"🔄 Начинаем добавление движений для источника {source}")
        
        if not movements_data:
            logger.warning(f"Нет данных о движениях для {source}")
            return
        
        try:
            insert_query = """
            INSERT IGNORE INTO stock_movements 
            (movement_id, product_id, movement_date, movement_type, quantity, 
             warehouse_name, order_id, source)
            VALUES (%(movement_id)s, %(product_id)s, %(movement_date)s, %(movement_type)s, 
                   %(quantity)s, %(warehouse_name)s, %(order_id)s, %(source)s)
            """
            
            self.cursor.executemany(insert_query, movements_data)
            inserted_count = self.cursor.rowcount
            
            self.connection.commit()
            logger.info(f"✅ Добавлено {inserted_count} новых движений для {source}")
            
        except Exception as e:
            logger.error(f"Ошибка при добавлении движений {source}: {e}")
            self.connection.rollback()
            raise

    def run_movements_update(self, hours_back: int = 24):
        """
        Основная функция запуска обновления движений.
        
        Args:
            hours_back: Количество часов назад для получения данных
        """
        logger.info(f"🚀 Запуск обновления движений товаров за последние {hours_back} часов")
        
        try:
            self.connect_to_database()
            
            # Получаем и добавляем движения Ozon
            logger.info("=" * 50)
            logger.info("ОБНОВЛЕНИЕ ДВИЖЕНИЙ OZON")
            logger.info("=" * 50)
            
            ozon_movements = self.get_ozon_movements(hours_back)
            self.add_movements(ozon_movements, 'Ozon')
            
            # Получаем и добавляем движения Wildberries
            logger.info("=" * 50)
            logger.info("ОБНОВЛЕНИЕ ДВИЖЕНИЙ WILDBERRIES")
            logger.info("=" * 50)
            
            wb_movements = self.get_wb_movements(hours_back)
            self.add_movements(wb_movements, 'Wildberries')
            
            # Выводим итоговую статистику
            self._print_movements_statistics()
            
            logger.info("✅ Обновление движений завершено успешно")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при обновлении движений: {e}")
            raise
        finally:
            self.close_database_connection()

    def _print_movements_statistics(self):
        """Вывод статистики по движениям."""
        logger.info("📊 СТАТИСТИКА ДВИЖЕНИЙ:")
        logger.info("=" * 40)
        
        try:
            # Статистика за последние 24 часа
            self.cursor.execute("""
                SELECT 
                    source,
                    movement_type,
                    COUNT(*) as count,
                    SUM(ABS(quantity)) as total_quantity
                FROM stock_movements 
                WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY source, movement_type
                ORDER BY source, movement_type
            """)
            
            stats = self.cursor.fetchall()
            
            current_source = None
            for stat in stats:
                if stat['source'] != current_source:
                    current_source = stat['source']
                    logger.info(f"{current_source}:")
                
                logger.info(f"  {stat['movement_type']}: {stat['count']} операций, {stat['total_quantity']} единиц")
            
            # Общая статистика
            self.cursor.execute("""
                SELECT 
                    COUNT(*) as total_movements,
                    COUNT(DISTINCT product_id) as unique_products,
                    MIN(movement_date) as earliest_date,
                    MAX(movement_date) as latest_date
                FROM stock_movements
            """)
            
            total_stats = self.cursor.fetchone()
            logger.info("-" * 40)
            logger.info(f"Всего движений в БД: {total_stats['total_movements']}")
            logger.info(f"Уникальных товаров: {total_stats['unique_products']}")
            logger.info(f"Период: {total_stats['earliest_date']} - {total_stats['latest_date']}")
                
        except Exception as e:
            logger.error(f"Ошибка при получении статистики движений: {e}")


def main():
    """Главная функция."""
    import argparse
    
    parser = argparse.ArgumentParser(description='Импорт движений товаров')
    parser.add_argument('--hours', type=int, default=24, 
                       help='Количество часов назад для получения данных (по умолчанию: 24)')
    
    args = parser.parse_args()
    
    importer = MovementImporter()
    importer.run_movements_update(args.hours)


if __name__ == "__main__":
    main()
