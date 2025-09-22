#!/usr/bin/env python3
"""
Комплексные тесты для системы расчета маржинальности.
Проверяет корректность расчета прибыли и процента маржинальности.
"""

import sys
import os
import logging
from datetime import datetime, timedelta
from decimal import Decimal

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from run_aggregation import aggregate_daily_metrics, calculate_margin_percentage

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class MarginCalculationTester:
    """Класс для тестирования расчета маржинальности."""
    
    def __init__(self):
        self.connection = None
        self.test_date = '2024-09-22'  # Тестовая дата
        self.test_client_id = 1
        
    def setup_connection(self):
        """Устанавливает соединение с базой данных."""
        try:
            self.connection = connect_to_db()
            logger.info("✅ Соединение с базой данных установлено")
            return True
        except Exception as e:
            logger.error(f"❌ Ошибка подключения к БД: {e}")
            return False
    
    def cleanup_test_data(self):
        """Очищает тестовые данные."""
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor()
            
            # Удаляем тестовые данные
            cursor.execute("DELETE FROM metrics_daily WHERE metric_date = %s", (self.test_date,))
            cursor.execute("DELETE FROM fact_orders WHERE order_date = %s AND order_id LIKE 'TEST_%'", (self.test_date,))
            cursor.execute("DELETE FROM fact_transactions WHERE transaction_date = %s AND transaction_id LIKE 'TEST_%'", (self.test_date,))
            cursor.execute("DELETE FROM dim_products WHERE sku_ozon LIKE 'TEST_%'")
            
            self.connection.commit()
            logger.info("🧹 Тестовые данные очищены")
            return True
            
        except Exception as e:
            logger.error(f"❌ Ошибка очистки тестовых данных: {e}")
            self.connection.rollback()
            return False
        finally:
            cursor.close()
    
    def create_test_data(self):
        """Создает тестовые данные для проверки расчетов."""
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor()
            
            # 1. Создаем тестовый товар с себестоимостью
            cursor.execute("""
                INSERT INTO dim_products (sku_ozon, barcode, product_name, cost_price)
                VALUES ('TEST_SKU_001', 'TEST_BARCODE_001', 'Тестовый товар для маржинальности', 100.00)
            """)
            
            test_product_id = cursor.lastrowid
            logger.info(f"📦 Создан тестовый товар с ID: {test_product_id}")
            
            # 2. Создаем тестовый заказ (продажа)
            cursor.execute("""
                INSERT INTO fact_orders (
                    product_id, order_id, transaction_type, sku, qty, price, 
                    order_date, cost_price, client_id, source_id
                )
                VALUES (%s, 'TEST_ORDER_001', 'продажа', 'TEST_SKU_001', 2, 200.00, %s, 100.00, %s, 2)
            """, (test_product_id, self.test_date, self.test_client_id))
            
            logger.info("🛒 Создан тестовый заказ: 2 товара по 200 руб = 400 руб выручки")
            
            # 3. Создаем тестовые транзакции (комиссии и логистика)
            
            # Комиссия маркетплейса (10% от выручки)
            cursor.execute("""
                INSERT INTO fact_transactions (
                    client_id, source_id, transaction_id, order_id, transaction_type,
                    amount, transaction_date, description
                )
                VALUES (%s, 2, 'TEST_COMMISSION_001', 'TEST_ORDER_001', 'комиссия маркетплейса',
                        -40.00, %s, 'Тестовая комиссия 10%')
            """, (self.test_client_id, self.test_date))
            
            # Логистические расходы
            cursor.execute("""
                INSERT INTO fact_transactions (
                    client_id, source_id, transaction_id, order_id, transaction_type,
                    amount, transaction_date, description
                )
                VALUES (%s, 2, 'TEST_LOGISTICS_001', 'TEST_ORDER_001', 'логистика',
                        -30.00, %s, 'Тестовые расходы на доставку')
            """, (self.test_client_id, self.test_date))
            
            # Эквайринг
            cursor.execute("""
                INSERT INTO fact_transactions (
                    client_id, source_id, transaction_id, order_id, transaction_type,
                    amount, transaction_date, description
                )
                VALUES (%s, 2, 'TEST_ACQUIRING_001', 'TEST_ORDER_001', 'эквайринг',
                        -8.00, %s, 'Тестовый эквайринг 2%')
            """, (self.test_client_id, self.test_date))
            
            self.connection.commit()
            
            logger.info("💳 Созданы тестовые транзакции:")
            logger.info("   - Комиссия: -40.00 руб")
            logger.info("   - Логистика: -30.00 руб") 
            logger.info("   - Эквайринг: -8.00 руб")
            
            # Ожидаемый расчет:
            # Выручка: 400.00
            # Себестоимость: 200.00 (2 товара * 100 руб)
            # Комиссии: 48.00 (40 + 8)
            # Логистика: 30.00
            # Прибыль: 400 - 200 - 48 - 30 = 122.00
            # Маржа: (122 / 400) * 100 = 30.5%
            
            logger.info("📊 Ожидаемые результаты:")
            logger.info("   - Выручка: 400.00 руб")
            logger.info("   - Себестоимость: 200.00 руб")
            logger.info("   - Комиссии: 48.00 руб")
            logger.info("   - Логистика: 30.00 руб")
            logger.info("   - Прибыль: 122.00 руб")
            logger.info("   - Маржа: 30.5%")
            
            return True
            
        except Exception as e:
            logger.error(f"❌ Ошибка создания тестовых данных: {e}")
            self.connection.rollback()
            return False
        finally:
            cursor.close()
    
    def test_margin_percentage_function(self):
        """Тестирует функцию расчета процента маржинальности."""
        logger.info("🧪 Тестирование функции calculate_margin_percentage")
        
        test_cases = [
            (100, 400, 25.0),      # Прибыль 100, выручка 400 = 25%
            (122, 400, 30.5),      # Наш тестовый случай
            (0, 400, 0.0),         # Нулевая прибыль
            (-50, 400, -12.5),     # Убыток
            (100, 0, None),        # Нулевая выручка
            (0, 0, None),          # Нули
        ]
        
        passed = 0
        total = len(test_cases)
        
        for profit, revenue, expected in test_cases:
            result = calculate_margin_percentage(profit, revenue)
            
            if expected is None:
                if result is None:
                    logger.info(f"✅ Прибыль={profit}, Выручка={revenue} → {result} (ожидалось {expected})")
                    passed += 1
                else:
                    logger.error(f"❌ Прибыль={profit}, Выручка={revenue} → {result} (ожидалось {expected})")
            else:
                if result is not None and abs(result - expected) < 0.01:
                    logger.info(f"✅ Прибыль={profit}, Выручка={revenue} → {result:.2f}% (ожидалось {expected}%)")
                    passed += 1
                else:
                    logger.error(f"❌ Прибыль={profit}, Выручка={revenue} → {result} (ожидалось {expected}%)")
        
        logger.info(f"📊 Тесты функции: {passed}/{total} прошли успешно")
        return passed == total
    
    def test_aggregation_with_test_data(self):
        """Тестирует агрегацию на созданных тестовых данных."""
        logger.info("🧪 Тестирование агрегации с тестовыми данными")
        
        # Запускаем агрегацию
        success = aggregate_daily_metrics(self.connection, self.test_date)
        
        if not success:
            logger.error("❌ Агрегация завершилась с ошибкой")
            return False
        
        # Проверяем результаты
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT * FROM metrics_daily 
                WHERE metric_date = %s AND client_id = %s
            """, (self.test_date, self.test_client_id))
            
            result = cursor.fetchone()
            
            if not result:
                logger.error("❌ Результаты агрегации не найдены")
                return False
            
            # Проверяем ожидаемые значения
            expected_revenue = 400.00
            expected_cogs = 200.00
            expected_commission = 48.00
            expected_shipping = 30.00
            expected_profit = 122.00
            expected_margin = 30.5
            
            logger.info("📊 Результаты агрегации:")
            logger.info(f"   - Выручка: {result['revenue_sum']} (ожидалось {expected_revenue})")
            logger.info(f"   - Себестоимость: {result['cogs_sum']} (ожидалось {expected_cogs})")
            logger.info(f"   - Комиссии: {result['commission_sum']} (ожидалось {expected_commission})")
            logger.info(f"   - Логистика: {result['shipping_sum']} (ожидалось {expected_shipping})")
            logger.info(f"   - Прибыль: {result['profit_sum']} (ожидалось {expected_profit})")
            logger.info(f"   - Маржа: {result['margin_percent']}% (ожидалось {expected_margin}%)")
            
            # Проверяем точность расчетов
            tolerance = 0.01
            checks = [
                (abs(float(result['revenue_sum']) - expected_revenue) < tolerance, "Выручка"),
                (abs(float(result['cogs_sum']) - expected_cogs) < tolerance, "Себестоимость"),
                (abs(float(result['commission_sum']) - expected_commission) < tolerance, "Комиссии"),
                (abs(float(result['shipping_sum']) - expected_shipping) < tolerance, "Логистика"),
                (abs(float(result['profit_sum']) - expected_profit) < tolerance, "Прибыль"),
                (abs(float(result['margin_percent']) - expected_margin) < tolerance, "Маржа"),
            ]
            
            passed_checks = sum(1 for check, name in checks if check)
            total_checks = len(checks)
            
            for check, name in checks:
                status = "✅" if check else "❌"
                logger.info(f"{status} {name}")
            
            logger.info(f"📊 Проверки агрегации: {passed_checks}/{total_checks} прошли успешно")
            
            return passed_checks == total_checks
            
        except Exception as e:
            logger.error(f"❌ Ошибка проверки результатов агрегации: {e}")
            return False
        finally:
            cursor.close()
    
    def test_edge_cases(self):
        """Тестирует граничные случаи."""
        logger.info("🧪 Тестирование граничных случаев")
        
        try:
            cursor = self.connection.cursor()
            
            # Тест 1: Товар без себестоимости
            cursor.execute("""
                INSERT INTO dim_products (sku_ozon, barcode, product_name, cost_price)
                VALUES ('TEST_SKU_NO_COST', 'TEST_BARCODE_NO_COST', 'Товар без себестоимости', NULL)
            """)
            
            no_cost_product_id = cursor.lastrowid
            
            cursor.execute("""
                INSERT INTO fact_orders (
                    product_id, order_id, transaction_type, sku, qty, price, 
                    order_date, cost_price, client_id, source_id
                )
                VALUES (%s, 'TEST_ORDER_NO_COST', 'продажа', 'TEST_SKU_NO_COST', 1, 100.00, %s, NULL, %s, 2)
            """, (no_cost_product_id, self.test_date, self.test_client_id))
            
            # Тест 2: Заказ без связанных транзакций
            cursor.execute("""
                INSERT INTO dim_products (sku_ozon, barcode, product_name, cost_price)
                VALUES ('TEST_SKU_NO_TRANS', 'TEST_BARCODE_NO_TRANS', 'Товар без транзакций', 50.00)
            """)
            
            no_trans_product_id = cursor.lastrowid
            
            cursor.execute("""
                INSERT INTO fact_orders (
                    product_id, order_id, transaction_type, sku, qty, price, 
                    order_date, cost_price, client_id, source_id
                )
                VALUES (%s, 'TEST_ORDER_NO_TRANS', 'продажа', 'TEST_SKU_NO_TRANS', 1, 150.00, %s, 50.00, %s, 2)
            """, (no_trans_product_id, self.test_date, self.test_client_id))
            
            self.connection.commit()
            
            # Запускаем агрегацию с граничными случаями
            success = aggregate_daily_metrics(self.connection, self.test_date)
            
            if success:
                logger.info("✅ Агрегация с граничными случаями прошла успешно")
                return True
            else:
                logger.error("❌ Агрегация с граничными случаями завершилась с ошибкой")
                return False
                
        except Exception as e:
            logger.error(f"❌ Ошибка тестирования граничных случаев: {e}")
            self.connection.rollback()
            return False
        finally:
            cursor.close()
    
    def run_all_tests(self):
        """Запускает все тесты."""
        logger.info("🚀 Запуск комплексных тестов расчета маржинальности")
        logger.info("=" * 60)
        
        if not self.setup_connection():
            return False
        
        try:
            # Очищаем старые тестовые данные
            self.cleanup_test_data()
            
            # Тест 1: Функция расчета процента маржинальности
            test1_passed = self.test_margin_percentage_function()
            
            # Тест 2: Создание тестовых данных и агрегация
            if self.create_test_data():
                test2_passed = self.test_aggregation_with_test_data()
            else:
                test2_passed = False
            
            # Тест 3: Граничные случаи
            test3_passed = self.test_edge_cases()
            
            # Итоговые результаты
            logger.info("=" * 60)
            logger.info("📊 ИТОГОВЫЕ РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ:")
            logger.info(f"   1. Функция расчета маржи: {'✅ ПРОШЕЛ' if test1_passed else '❌ НЕ ПРОШЕЛ'}")
            logger.info(f"   2. Агрегация тестовых данных: {'✅ ПРОШЕЛ' if test2_passed else '❌ НЕ ПРОШЕЛ'}")
            logger.info(f"   3. Граничные случаи: {'✅ ПРОШЕЛ' if test3_passed else '❌ НЕ ПРОШЕЛ'}")
            
            all_passed = test1_passed and test2_passed and test3_passed
            
            if all_passed:
                logger.info("🎉 ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!")
                logger.info("✅ Система расчета маржинальности готова к использованию")
            else:
                logger.error("❌ НЕКОТОРЫЕ ТЕСТЫ НЕ ПРОШЛИ")
                logger.error("⚠️  Требуется дополнительная отладка")
            
            return all_passed
            
        finally:
            # Очищаем тестовые данные
            self.cleanup_test_data()
            
            if self.connection:
                self.connection.close()


def main():
    """Основная функция для запуска тестов."""
    tester = MarginCalculationTester()
    success = tester.run_all_tests()
    
    if success:
        exit(0)
    else:
        exit(1)


if __name__ == "__main__":
    main()