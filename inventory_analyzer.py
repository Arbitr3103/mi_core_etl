#!/usr/bin/env python3
"""
Модуль анализа запасов для системы пополнения склада.
Анализирует текущие остатки товаров и выявляет товары, требующие пополнения.
"""

import sys
import os
import logging
from datetime import datetime, timedelta
from typing import List, Dict, Optional, Tuple
from dataclasses import dataclass

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


@dataclass
class InventoryItem:
    """Класс для представления товара в запасах."""
    product_id: int
    sku: str
    product_name: str
    source: str
    current_stock: int
    reserved_stock: int
    available_stock: int
    last_updated: datetime
    cost_price: Optional[float] = None


@dataclass
class ProductSettings:
    """Настройки товара для пополнения."""
    min_stock_level: int = 0
    max_stock_level: int = 0
    reorder_point: int = 0
    lead_time_days: int = 14
    safety_stock_days: int = 7
    is_active: bool = True


class InventoryAnalyzer:
    """Класс для анализа текущих запасов товаров."""
    
    def __init__(self, connection=None):
        """
        Инициализация анализатора запасов.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        self.settings = self._load_system_settings()
        
    def _load_system_settings(self) -> Dict[str, any]:
        """Загружает системные настройки из базы данных."""
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT setting_key, setting_value, setting_type 
                FROM replenishment_settings 
                WHERE is_active = TRUE
            """)
            
            settings = {}
            for row in cursor.fetchall():
                key = row['setting_key']
                value = row['setting_value']
                setting_type = row['setting_type']
                
                # Преобразуем значение в соответствующий тип
                if setting_type == 'INTEGER':
                    settings[key] = int(value)
                elif setting_type == 'DECIMAL':
                    settings[key] = float(value)
                elif setting_type == 'BOOLEAN':
                    settings[key] = value.lower() in ('true', '1', 'yes')
                elif setting_type == 'JSON':
                    import json
                    settings[key] = json.loads(value)
                else:
                    settings[key] = value
                    
            cursor.close()
            logger.info(f"Загружено {len(settings)} системных настроек")
            return settings
            
        except Exception as e:
            logger.error(f"Ошибка загрузки настроек: {e}")
            return self._get_default_settings()
    
    def _get_default_settings(self) -> Dict[str, any]:
        """Возвращает настройки по умолчанию."""
        return {
            'default_lead_time_days': 14,
            'default_safety_stock_days': 7,
            'critical_stockout_threshold': 3,
            'high_priority_threshold': 7,
            'slow_moving_threshold_days': 30,
            'overstocked_threshold_days': 90,
            'min_sales_history_days': 14,
            'max_recommended_order_multiplier': 3.0
        }
    
    def get_current_stock(self, product_id: Optional[int] = None, 
                         source: Optional[str] = None) -> List[InventoryItem]:
        """
        Получить текущие остатки товаров.
        
        Args:
            product_id: ID конкретного товара (опционально)
            source: Источник данных (опционально)
            
        Returns:
            Список объектов InventoryItem
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            sql = """
                SELECT 
                    i.product_id,
                    COALESCE(dp.sku_ozon, i.sku, 'UNKNOWN') as sku,
                    COALESCE(dp.product_name, 'Unknown Product') as product_name,
                    i.source,
                    i.quantity_present as current_stock,
                    COALESCE(i.quantity_reserved, 0) as reserved_stock,
                    (i.quantity_present - COALESCE(i.quantity_reserved, 0)) as available_stock,
                    i.updated_at as last_updated,
                    dp.cost_price
                FROM inventory i
                LEFT JOIN dim_products dp ON i.product_id = dp.id
                WHERE i.quantity_present >= 0
            """
            
            params = []
            
            if product_id:
                sql += " AND i.product_id = %s"
                params.append(product_id)
                
            if source:
                sql += " AND i.source = %s"
                params.append(source)
                
            sql += " ORDER BY i.source, dp.product_name"
            
            cursor.execute(sql, params)
            results = cursor.fetchall()
            cursor.close()
            
            inventory_items = []
            for row in results:
                item = InventoryItem(
                    product_id=row['product_id'],
                    sku=row['sku'],
                    product_name=row['product_name'],
                    source=row['source'],
                    current_stock=row['current_stock'],
                    reserved_stock=row['reserved_stock'],
                    available_stock=max(0, row['available_stock']),  # Не может быть отрицательным
                    last_updated=row['last_updated'],
                    cost_price=row['cost_price']
                )
                inventory_items.append(item)
            
            logger.info(f"Получено {len(inventory_items)} товаров в запасах")
            return inventory_items
            
        except Exception as e:
            logger.error(f"Ошибка получения остатков: {e}")
            return []
    
    def get_product_settings(self, product_id: int) -> ProductSettings:
        """
        Получить настройки товара для пополнения.
        
        Args:
            product_id: ID товара
            
        Returns:
            Объект ProductSettings с настройками товара
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT 
                    COALESCE(min_stock_level, 0) as min_stock_level,
                    COALESCE(max_stock_level, 0) as max_stock_level,
                    COALESCE(reorder_point, 0) as reorder_point,
                    COALESCE(lead_time_days, %s) as lead_time_days,
                    COALESCE(safety_stock_days, %s) as safety_stock_days,
                    COALESCE(is_active_for_replenishment, TRUE) as is_active
                FROM dim_products 
                WHERE id = %s
            """, (
                self.settings.get('default_lead_time_days', 14),
                self.settings.get('default_safety_stock_days', 7),
                product_id
            ))
            
            result = cursor.fetchone()
            cursor.close()
            
            if result:
                return ProductSettings(
                    min_stock_level=result['min_stock_level'],
                    max_stock_level=result['max_stock_level'],
                    reorder_point=result['reorder_point'],
                    lead_time_days=result['lead_time_days'],
                    safety_stock_days=result['safety_stock_days'],
                    is_active=result['is_active']
                )
            else:
                # Возвращаем настройки по умолчанию
                return ProductSettings()
                
        except Exception as e:
            logger.error(f"Ошибка получения настроек товара {product_id}: {e}")
            return ProductSettings()
    
    def get_products_below_threshold(self, threshold_days: int = None) -> List[InventoryItem]:
        """
        Получить товары с критически низкими остатками.
        
        Args:
            threshold_days: Порог в днях до исчерпания запасов
            
        Returns:
            Список товаров с низкими остатками
        """
        if threshold_days is None:
            threshold_days = self.settings.get('critical_stockout_threshold', 3)
            
        try:
            # Получаем все товары
            all_items = self.get_current_stock()
            
            # Фильтруем товары с низкими остатками
            # Для упрощения считаем, что товар критичен, если остаток меньше порога
            critical_items = []
            
            for item in all_items:
                settings = self.get_product_settings(item.product_id)
                
                # Если установлена точка перезаказа, используем её
                if settings.reorder_point > 0:
                    if item.available_stock <= settings.reorder_point:
                        critical_items.append(item)
                # Иначе используем минимальный уровень запасов
                elif settings.min_stock_level > 0:
                    if item.available_stock <= settings.min_stock_level:
                        critical_items.append(item)
                # Или простую логику: если остаток очень мал
                else:
                    if item.available_stock <= threshold_days:  # Упрощенная логика
                        critical_items.append(item)
            
            logger.info(f"Найдено {len(critical_items)} товаров с критическими остатками")
            return critical_items
            
        except Exception as e:
            logger.error(f"Ошибка поиска товаров с низкими остатками: {e}")
            return []
    
    def get_overstocked_products(self, threshold_days: int = None) -> List[InventoryItem]:
        """
        Получить товары с избыточными запасами.
        
        Args:
            threshold_days: Порог в днях для определения избыточных запасов
            
        Returns:
            Список товаров с избыточными запасами
        """
        if threshold_days is None:
            threshold_days = self.settings.get('overstocked_threshold_days', 90)
            
        try:
            # Получаем все товары
            all_items = self.get_current_stock()
            
            overstocked_items = []
            
            for item in all_items:
                settings = self.get_product_settings(item.product_id)
                
                # Если установлен максимальный уровень запасов
                if settings.max_stock_level > 0:
                    if item.current_stock > settings.max_stock_level:
                        overstocked_items.append(item)
                # Или если запасов слишком много (упрощенная логика)
                elif item.current_stock > threshold_days:  # Условно считаем избыточным
                    overstocked_items.append(item)
            
            logger.info(f"Найдено {len(overstocked_items)} товаров с избыточными запасами")
            return overstocked_items
            
        except Exception as e:
            logger.error(f"Ошибка поиска товаров с избыточными запасами: {e}")
            return []
    
    def validate_inventory_data(self, item: InventoryItem) -> List[str]:
        """
        Валидация данных об остатках товара.
        
        Args:
            item: Объект InventoryItem для валидации
            
        Returns:
            Список найденных проблем
        """
        issues = []
        
        # Проверка на отрицательные остатки
        if item.current_stock < 0:
            issues.append(f"Отрицательный остаток: {item.current_stock}")
            
        # Проверка резерва
        if item.reserved_stock > item.current_stock:
            issues.append(f"Резерв ({item.reserved_stock}) больше остатка ({item.current_stock})")
            
        # Проверка доступного остатка
        if item.available_stock < 0:
            issues.append(f"Отрицательный доступный остаток: {item.available_stock}")
            
        # Проверка актуальности данных
        if item.last_updated:
            days_old = (datetime.now() - item.last_updated).days
            if days_old > 7:
                issues.append(f"Данные устарели на {days_old} дней")
        
        return issues
    
    def get_inventory_summary(self) -> Dict[str, any]:
        """
        Получить сводную информацию по запасам.
        
        Returns:
            Словарь с аналитикой по запасам
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Общая статистика по запасам
            cursor.execute("""
                SELECT 
                    i.source,
                    COUNT(DISTINCT i.product_id) as total_products,
                    SUM(i.quantity_present) as total_stock,
                    SUM(COALESCE(i.quantity_reserved, 0)) as total_reserved,
                    SUM(i.quantity_present - COALESCE(i.quantity_reserved, 0)) as total_available,
                    COUNT(CASE WHEN i.quantity_present = 0 THEN 1 END) as out_of_stock_count,
                    COUNT(CASE WHEN i.quantity_present <= 5 THEN 1 END) as low_stock_count,
                    AVG(i.quantity_present) as avg_stock_per_product
                FROM inventory i
                WHERE i.quantity_present >= 0
                GROUP BY i.source
                ORDER BY i.source
            """)
            
            source_stats = cursor.fetchall()
            
            # Общая статистика
            cursor.execute("""
                SELECT 
                    COUNT(DISTINCT product_id) as total_unique_products,
                    SUM(quantity_present) as grand_total_stock,
                    COUNT(CASE WHEN quantity_present = 0 THEN 1 END) as total_out_of_stock,
                    COUNT(CASE WHEN quantity_present <= 5 THEN 1 END) as total_low_stock
                FROM inventory
                WHERE quantity_present >= 0
            """)
            
            overall_stats = cursor.fetchone()
            cursor.close()
            
            summary = {
                'overall': overall_stats,
                'by_source': source_stats,
                'analysis_date': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                'settings': self.settings
            }
            
            logger.info("Сформирована сводка по запасам")
            return summary
            
        except Exception as e:
            logger.error(f"Ошибка формирования сводки: {e}")
            return {}
    
    def close(self):
        """Закрыть соединение с базой данных."""
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для тестирования анализатора запасов."""
    logger.info("🔍 Запуск анализатора запасов")
    
    analyzer = None
    try:
        # Создаем анализатор
        analyzer = InventoryAnalyzer()
        
        # Получаем сводку по запасам
        summary = analyzer.get_inventory_summary()
        
        if summary:
            print("\n📊 СВОДКА ПО ЗАПАСАМ:")
            print("=" * 50)
            
            overall = summary['overall']
            print(f"Всего уникальных товаров: {overall['total_unique_products']:,}")
            print(f"Общий объем запасов: {overall['grand_total_stock']:,}")
            print(f"Товаров без остатков: {overall['total_out_of_stock']:,}")
            print(f"Товаров с низкими остатками: {overall['total_low_stock']:,}")
            
            print("\n📈 ПО ИСТОЧНИКАМ:")
            for source_stat in summary['by_source']:
                print(f"\n{source_stat['source']}:")
                print(f"  - Товаров: {source_stat['total_products']:,}")
                print(f"  - Общий запас: {source_stat['total_stock']:,}")
                print(f"  - Доступно: {source_stat['total_available']:,}")
                print(f"  - Зарезервировано: {source_stat['total_reserved']:,}")
                print(f"  - Без остатков: {source_stat['out_of_stock_count']:,}")
        
        # Тестируем поиск критических товаров
        critical_items = analyzer.get_products_below_threshold()
        
        if critical_items:
            print(f"\n🚨 КРИТИЧЕСКИЕ ОСТАТКИ ({len(critical_items)} товаров):")
            for item in critical_items[:5]:  # Показываем первые 5
                print(f"  - {item.sku}: {item.available_stock} шт. ({item.source})")
        
        # Тестируем поиск избыточных запасов
        overstocked_items = analyzer.get_overstocked_products()
        
        if overstocked_items:
            print(f"\n📦 ИЗБЫТОЧНЫЕ ЗАПАСЫ ({len(overstocked_items)} товаров):")
            for item in overstocked_items[:5]:  # Показываем первые 5
                print(f"  - {item.sku}: {item.current_stock} шт. ({item.source})")
        
        print("\n✅ Анализ запасов завершен успешно!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if analyzer:
            analyzer.close()


if __name__ == "__main__":
    main()