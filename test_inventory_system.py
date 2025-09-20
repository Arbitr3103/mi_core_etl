#!/usr/bin/env python3
"""
Тестовый скрипт для проверки системы управления складом.

Функционал:
- Проверка подключения к базе данных
- Проверка структуры таблиц
- Тестирование API подключений (без реальных запросов)
- Проверка конфигурации

Автор: ETL System
Дата: 20 сентября 2025
"""

import os
import sys
import logging
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(__file__))

try:
    from ozon_importer import connect_to_db
    import config
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    print("Убедитесь, что все необходимые модули доступны")
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class InventorySystemTester:
    """Класс для тестирования системы управления складом."""
    
    def __init__(self):
        """Инициализация тестера."""
        self.connection = None
        self.cursor = None
        self.test_results = []
        
    def add_test_result(self, test_name: str, success: bool, message: str = ""):
        """Добавление результата теста."""
        self.test_results.append({
            'test': test_name,
            'success': success,
            'message': message,
            'timestamp': datetime.now()
        })
        
        status = "✅" if success else "❌"
        logger.info(f"{status} {test_name}: {message}")

    def test_database_connection(self) -> bool:
        """Тест подключения к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            
            # Проверяем подключение
            self.cursor.execute("SELECT 1 as test")
            result = self.cursor.fetchone()
            
            if result and result['test'] == 1:
                self.add_test_result("Подключение к БД", True, "Подключение успешно")
                return True
            else:
                self.add_test_result("Подключение к БД", False, "Неверный ответ от БД")
                return False
                
        except Exception as e:
            self.add_test_result("Подключение к БД", False, f"Ошибка: {e}")
            return False

    def test_tables_structure(self) -> bool:
        """Тест структуры таблиц."""
        if not self.cursor:
            self.add_test_result("Структура таблиц", False, "Нет подключения к БД")
            return False
        
        try:
            # Проверяем существование основных таблиц
            required_tables = ['dim_products', 'inventory', 'stock_movements']
            
            self.cursor.execute("SHOW TABLES")
            existing_tables = [table[list(table.keys())[0]] for table in self.cursor.fetchall()]
            
            missing_tables = []
            for table in required_tables:
                if table not in existing_tables:
                    missing_tables.append(table)
            
            if missing_tables:
                self.add_test_result("Структура таблиц", False, 
                                   f"Отсутствуют таблицы: {', '.join(missing_tables)}")
                return False
            
            # Проверяем структуру таблицы inventory
            self.cursor.execute("DESCRIBE inventory")
            inventory_columns = [col['Field'] for col in self.cursor.fetchall()]
            
            required_inventory_columns = [
                'id', 'product_id', 'warehouse_name', 'stock_type', 
                'quantity_present', 'quantity_reserved', 'source'
            ]
            
            missing_columns = []
            for col in required_inventory_columns:
                if col not in inventory_columns:
                    missing_columns.append(col)
            
            if missing_columns:
                self.add_test_result("Структура таблиц", False,
                                   f"В таблице inventory отсутствуют колонки: {', '.join(missing_columns)}")
                return False
            
            # Проверяем структуру таблицы stock_movements
            self.cursor.execute("DESCRIBE stock_movements")
            movements_columns = [col['Field'] for col in self.cursor.fetchall()]
            
            required_movements_columns = [
                'id', 'movement_id', 'product_id', 'movement_date', 
                'movement_type', 'quantity', 'source'
            ]
            
            missing_columns = []
            for col in required_movements_columns:
                if col not in movements_columns:
                    missing_columns.append(col)
            
            if missing_columns:
                self.add_test_result("Структура таблиц", False,
                                   f"В таблице stock_movements отсутствуют колонки: {', '.join(missing_columns)}")
                return False
            
            self.add_test_result("Структура таблиц", True, "Все таблицы и колонки присутствуют")
            return True
            
        except Exception as e:
            self.add_test_result("Структура таблиц", False, f"Ошибка: {e}")
            return False

    def test_config_settings(self) -> bool:
        """Тест настроек конфигурации."""
        try:
            # Проверяем наличие основных настроек
            required_settings = [
                'OZON_CLIENT_ID', 'OZON_API_KEY', 'WB_API_TOKEN'
            ]
            
            missing_settings = []
            placeholder_settings = []
            
            for setting in required_settings:
                if not hasattr(config, setting):
                    missing_settings.append(setting)
                else:
                    value = getattr(config, setting)
                    if not value or 'your_' in value.lower() or 'here' in value.lower():
                        placeholder_settings.append(setting)
            
            if missing_settings:
                self.add_test_result("Конфигурация", False,
                                   f"Отсутствуют настройки: {', '.join(missing_settings)}")
                return False
            
            if placeholder_settings:
                self.add_test_result("Конфигурация", False,
                                   f"Не заполнены настройки: {', '.join(placeholder_settings)}")
                return False
            
            self.add_test_result("Конфигурация", True, "Все настройки присутствуют")
            return True
            
        except Exception as e:
            self.add_test_result("Конфигурация", False, f"Ошибка: {e}")
            return False

    def test_views_existence(self) -> bool:
        """Тест существования представлений."""
        if not self.cursor:
            self.add_test_result("Представления", False, "Нет подключения к БД")
            return False
        
        try:
            # Проверяем существование представлений
            required_views = [
                'v_inventory_with_products', 
                'v_movements_with_products', 
                'v_product_turnover_30d'
            ]
            
            self.cursor.execute("SHOW FULL TABLES WHERE Table_type = 'VIEW'")
            existing_views = [view[list(view.keys())[0]] for view in self.cursor.fetchall()]
            
            missing_views = []
            for view in required_views:
                if view not in existing_views:
                    missing_views.append(view)
            
            if missing_views:
                self.add_test_result("Представления", False,
                                   f"Отсутствуют представления: {', '.join(missing_views)}")
                return False
            
            self.add_test_result("Представления", True, "Все представления созданы")
            return True
            
        except Exception as e:
            self.add_test_result("Представления", False, f"Ошибка: {e}")
            return False

    def test_sample_data_operations(self) -> bool:
        """Тест операций с тестовыми данными."""
        if not self.cursor:
            self.add_test_result("Тестовые операции", False, "Нет подключения к БД")
            return False
        
        try:
            # Проверяем возможность вставки тестовых данных
            
            # Сначала проверим, есть ли товары в dim_products
            self.cursor.execute("SELECT COUNT(*) as count FROM dim_products LIMIT 1")
            products_count = self.cursor.fetchone()['count']
            
            if products_count == 0:
                self.add_test_result("Тестовые операции", False, 
                                   "В таблице dim_products нет товаров для тестирования")
                return False
            
            # Получаем первый товар для тестирования
            self.cursor.execute("SELECT id FROM dim_products LIMIT 1")
            test_product = self.cursor.fetchone()
            test_product_id = test_product['id']
            
            # Тестируем вставку в inventory
            test_inventory_data = {
                'product_id': test_product_id,
                'warehouse_name': 'TEST-Склад',
                'stock_type': 'FBO',
                'quantity_present': 100,
                'quantity_reserved': 10,
                'source': 'Ozon'
            }
            
            self.cursor.execute("""
                INSERT INTO inventory 
                (product_id, warehouse_name, stock_type, quantity_present, quantity_reserved, source)
                VALUES (%(product_id)s, %(warehouse_name)s, %(stock_type)s, 
                       %(quantity_present)s, %(quantity_reserved)s, %(source)s)
                ON DUPLICATE KEY UPDATE
                    quantity_present = VALUES(quantity_present),
                    quantity_reserved = VALUES(quantity_reserved)
            """, test_inventory_data)
            
            # Тестируем вставку в stock_movements
            test_movement_data = {
                'movement_id': f'TEST_{datetime.now().strftime("%Y%m%d_%H%M%S")}',
                'product_id': test_product_id,
                'movement_date': datetime.now(),
                'movement_type': 'sale',
                'quantity': -5,
                'warehouse_name': 'TEST-Склад',
                'order_id': 'TEST_ORDER_123',
                'source': 'Ozon'
            }
            
            self.cursor.execute("""
                INSERT IGNORE INTO stock_movements 
                (movement_id, product_id, movement_date, movement_type, quantity, 
                 warehouse_name, order_id, source)
                VALUES (%(movement_id)s, %(product_id)s, %(movement_date)s, %(movement_type)s, 
                       %(quantity)s, %(warehouse_name)s, %(order_id)s, %(source)s)
            """, test_movement_data)
            
            # Проверяем, что данные вставились
            self.cursor.execute(
                "SELECT COUNT(*) as count FROM inventory WHERE warehouse_name = 'TEST-Склад'"
            )
            inventory_test_count = self.cursor.fetchone()['count']
            
            self.cursor.execute(
                "SELECT COUNT(*) as count FROM stock_movements WHERE warehouse_name = 'TEST-Склад'"
            )
            movements_test_count = self.cursor.fetchone()['count']
            
            # Очищаем тестовые данные
            self.cursor.execute("DELETE FROM inventory WHERE warehouse_name = 'TEST-Склад'")
            self.cursor.execute("DELETE FROM stock_movements WHERE warehouse_name = 'TEST-Склад'")
            
            self.connection.commit()
            
            if inventory_test_count > 0 and movements_test_count > 0:
                self.add_test_result("Тестовые операции", True, 
                                   "Вставка и удаление данных работают корректно")
                return True
            else:
                self.add_test_result("Тестовые операции", False, 
                                   "Данные не были вставлены корректно")
                return False
            
        except Exception as e:
            self.add_test_result("Тестовые операции", False, f"Ошибка: {e}")
            return False

    def run_all_tests(self) -> bool:
        """Запуск всех тестов."""
        logger.info("🚀 Запуск тестирования системы управления складом")
        logger.info("=" * 60)
        
        try:
            # Запускаем тесты
            tests = [
                self.test_database_connection,
                self.test_tables_structure,
                self.test_views_existence,
                self.test_config_settings,
                self.test_sample_data_operations
            ]
            
            passed_tests = 0
            total_tests = len(tests)
            
            for test in tests:
                if test():
                    passed_tests += 1
            
            # Выводим итоговый отчет
            logger.info("=" * 60)
            logger.info("📊 ИТОГОВЫЙ ОТЧЕТ ТЕСТИРОВАНИЯ")
            logger.info("=" * 60)
            
            for result in self.test_results:
                status = "✅" if result['success'] else "❌"
                logger.info(f"{status} {result['test']}: {result['message']}")
            
            logger.info("-" * 60)
            logger.info(f"Пройдено тестов: {passed_tests}/{total_tests}")
            
            if passed_tests == total_tests:
                logger.info("🎉 Все тесты пройдены успешно! Система готова к работе.")
                return True
            else:
                logger.info("⚠️ Некоторые тесты не пройдены. Проверьте конфигурацию.")
                return False
                
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при тестировании: {e}")
            return False
        finally:
            if self.cursor:
                self.cursor.close()
            if self.connection:
                self.connection.close()


def main():
    """Главная функция."""
    tester = InventorySystemTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎯 СЛЕДУЮЩИЕ ШАГИ:")
        print("1. Заполните реальные API ключи в config.py")
        print("2. Запустите миграцию: mysql < create_inventory_tables.sql")
        print("3. Протестируйте импорт остатков: python importers/stock_importer.py")
        print("4. Протестируйте импорт движений: python importers/movement_importer.py")
        print("5. Настройте cron задачи для автоматизации")
    else:
        print("\n🔧 ТРЕБУЕТСЯ ИСПРАВЛЕНИЕ:")
        print("1. Исправьте ошибки, указанные в отчете")
        print("2. Запустите тест повторно")
    
    return 0 if success else 1


if __name__ == "__main__":
    exit(main())
