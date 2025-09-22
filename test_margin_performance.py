#!/usr/bin/env python3
"""
Тесты производительности для системы расчета маржинальности.
Проверяет скорость выполнения запросов на больших объемах данных.
"""

import sys
import os
import time
import logging
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from run_aggregation import aggregate_daily_metrics

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class PerformanceTester:
    """Класс для тестирования производительности расчета маржинальности."""
    
    def __init__(self):
        self.connection = None
        
    def setup_connection(self):
        """Устанавливает соединение с базой данных."""
        try:
            self.connection = connect_to_db()
            logger.info("✅ Соединение с базой данных установлено")
            return True
        except Exception as e:
            logger.error(f"❌ Ошибка подключения к БД: {e}")
            return False
    
    def analyze_data_volume(self):
        """Анализирует объем данных в таблицах."""
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            logger.info("📊 Анализ объема данных:")
            
            # Анализ fact_orders
            cursor.execute("SELECT COUNT(*) as count FROM fact_orders")
            orders_count = cursor.fetchone()['count']
            logger.info(f"   - fact_orders: {orders_count:,} записей")
            
            # Анализ fact_transactions
            cursor.execute("SELECT COUNT(*) as count FROM fact_transactions")
            transactions_count = cursor.fetchone()['count']
            logger.info(f"   - fact_transactions: {transactions_count:,} записей")
            
            # Анализ dim_products
            cursor.execute("SELECT COUNT(*) as count FROM dim_products")
            products_count = cursor.fetchone()['count']
            logger.info(f"   - dim_products: {products_count:,} записей")
            
            # Анализ по датам
            cursor.execute("""
                SELECT 
                    MIN(order_date) as min_date,
                    MAX(order_date) as max_date,
                    COUNT(DISTINCT order_date) as unique_dates
                FROM fact_orders
            """)
            date_info = cursor.fetchone()
            
            if date_info['min_date']:
                logger.info(f"   - Период данных: {date_info['min_date']} - {date_info['max_date']}")
                logger.info(f"   - Уникальных дат: {date_info['unique_dates']}")
            
            # Анализ среднего количества записей на дату
            cursor.execute("""
                SELECT 
                    order_date,
                    COUNT(*) as daily_orders
                FROM fact_orders
                GROUP BY order_date
                ORDER BY daily_orders DESC
                LIMIT 5
            """)
            
            top_dates = cursor.fetchall()
            logger.info("   - Топ-5 дат по количеству заказов:")
            for row in top_dates:
                logger.info(f"     {row['order_date']}: {row['daily_orders']:,} заказов")
            
            return True
            
        except Exception as e:
            logger.error(f"❌ Ошибка анализа данных: {e}")
            return False
        finally:
            cursor.close()
    
    def test_query_performance(self, test_date: str):
        """Тестирует производительность запроса агрегации."""
        if not self.connection:
            return False
            
        logger.info(f"⏱️  Тестирование производительности для даты: {test_date}")
        
        try:
            # Измеряем время выполнения агрегации
            start_time = time.time()
            
            success = aggregate_daily_metrics(self.connection, test_date)
            
            end_time = time.time()
            execution_time = end_time - start_time
            
            if success:
                logger.info(f"✅ Агрегация выполнена за {execution_time:.2f} секунд")
                
                # Проверяем результаты
                cursor = self.connection.cursor(dictionary=True)
                cursor.execute("""
                    SELECT COUNT(*) as count FROM metrics_daily 
                    WHERE metric_date = %s
                """, (test_date,))
                
                result_count = cursor.fetchone()['count']
                logger.info(f"📊 Обработано клиентов: {result_count}")
                
                # Показываем пример результатов
                cursor.execute("""
                    SELECT 
                        client_id,
                        orders_cnt,
                        revenue_sum,
                        profit_sum,
                        margin_percent
                    FROM metrics_daily 
                    WHERE metric_date = %s
                    LIMIT 3
                """, (test_date,))
                
                sample_results = cursor.fetchall()
                logger.info("📋 Пример результатов:")
                for row in sample_results:
                    logger.info(f"   Клиент {row['client_id']}: {row['orders_cnt']} заказов, "
                              f"выручка {row['revenue_sum']:.2f}, прибыль {row['profit_sum']:.2f}, "
                              f"маржа {row['margin_percent']:.2f}%")
                
                cursor.close()
                return execution_time
            else:
                logger.error("❌ Агрегация завершилась с ошибкой")
                return None
                
        except Exception as e:
            logger.error(f"❌ Ошибка тестирования производительности: {e}")
            return None
    
    def test_multiple_dates_performance(self, num_dates: int = 5):
        """Тестирует производительность на нескольких датах."""
        if not self.connection:
            return False
            
        logger.info(f"⏱️  Тестирование производительности на {num_dates} датах")
        
        try:
            cursor = self.connection.cursor()
            
            # Получаем последние даты с данными
            cursor.execute("""
                SELECT DISTINCT order_date 
                FROM fact_orders 
                ORDER BY order_date DESC 
                LIMIT %s
            """, (num_dates,))
            
            test_dates = [row[0].strftime('%Y-%m-%d') for row in cursor.fetchall()]
            cursor.close()
            
            if not test_dates:
                logger.warning("⚠️  Нет данных для тестирования")
                return False
            
            logger.info(f"📅 Тестовые даты: {', '.join(test_dates)}")
            
            total_time = 0
            successful_runs = 0
            
            for test_date in test_dates:
                execution_time = self.test_query_performance(test_date)
                
                if execution_time is not None:
                    total_time += execution_time
                    successful_runs += 1
                    logger.info(f"   {test_date}: {execution_time:.2f}s")
                else:
                    logger.error(f"   {test_date}: ОШИБКА")
            
            if successful_runs > 0:
                avg_time = total_time / successful_runs
                logger.info(f"📊 Статистика производительности:")
                logger.info(f"   - Успешных запусков: {successful_runs}/{len(test_dates)}")
                logger.info(f"   - Общее время: {total_time:.2f}s")
                logger.info(f"   - Среднее время на дату: {avg_time:.2f}s")
                
                # Оценка производительности
                if avg_time < 1.0:
                    logger.info("🚀 Отличная производительность (< 1s на дату)")
                elif avg_time < 5.0:
                    logger.info("✅ Хорошая производительность (< 5s на дату)")
                elif avg_time < 10.0:
                    logger.info("⚠️  Приемлемая производительность (< 10s на дату)")
                else:
                    logger.warning("🐌 Медленная производительность (> 10s на дату)")
                    logger.warning("💡 Рекомендуется оптимизация индексов")
                
                return True
            else:
                logger.error("❌ Все тесты завершились с ошибками")
                return False
                
        except Exception as e:
            logger.error(f"❌ Ошибка тестирования множественных дат: {e}")
            return False
    
    def check_indexes(self):
        """Проверяет наличие необходимых индексов."""
        if not self.connection:
            return False
            
        logger.info("🔍 Проверка индексов для оптимизации производительности")
        
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Проверяем индексы для fact_orders
            cursor.execute("SHOW INDEX FROM fact_orders")
            orders_indexes = cursor.fetchall()
            
            # Проверяем индексы для fact_transactions
            cursor.execute("SHOW INDEX FROM fact_transactions")
            transactions_indexes = cursor.fetchall()
            
            # Проверяем индексы для dim_products
            cursor.execute("SHOW INDEX FROM dim_products")
            products_indexes = cursor.fetchall()
            
            # Анализируем наличие рекомендуемых индексов
            recommended_indexes = {
                'fact_orders': ['idx_fact_orders_date_client', 'idx_fact_orders_product'],
                'fact_transactions': ['idx_fact_transactions_order', 'idx_fact_transactions_date_type'],
                'dim_products': ['idx_dim_products_cost']
            }
            
            logger.info("📋 Статус рекомендуемых индексов:")
            
            for table, indexes in recommended_indexes.items():
                if table == 'fact_orders':
                    existing_indexes = [idx['Key_name'] for idx in orders_indexes]
                elif table == 'fact_transactions':
                    existing_indexes = [idx['Key_name'] for idx in transactions_indexes]
                else:
                    existing_indexes = [idx['Key_name'] for idx in products_indexes]
                
                logger.info(f"   {table}:")
                for idx_name in indexes:
                    status = "✅" if idx_name in existing_indexes else "❌"
                    logger.info(f"     {status} {idx_name}")
            
            cursor.close()
            return True
            
        except Exception as e:
            logger.error(f"❌ Ошибка проверки индексов: {e}")
            return False
    
    def run_performance_tests(self):
        """Запускает все тесты производительности."""
        logger.info("🚀 Запуск тестов производительности расчета маржинальности")
        logger.info("=" * 60)
        
        if not self.setup_connection():
            return False
        
        try:
            # 1. Анализ объема данных
            self.analyze_data_volume()
            
            # 2. Проверка индексов
            self.check_indexes()
            
            # 3. Тестирование производительности на нескольких датах
            success = self.test_multiple_dates_performance()
            
            logger.info("=" * 60)
            if success:
                logger.info("🎉 ТЕСТЫ ПРОИЗВОДИТЕЛЬНОСТИ ЗАВЕРШЕНЫ УСПЕШНО!")
            else:
                logger.error("❌ ТЕСТЫ ПРОИЗВОДИТЕЛЬНОСТИ ЗАВЕРШИЛИСЬ С ОШИБКАМИ")
            
            return success
            
        finally:
            if self.connection:
                self.connection.close()


def main():
    """Основная функция для запуска тестов производительности."""
    tester = PerformanceTester()
    success = tester.run_performance_tests()
    
    if success:
        exit(0)
    else:
        exit(1)


if __name__ == "__main__":
    main()