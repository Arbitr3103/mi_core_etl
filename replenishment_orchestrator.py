#!/usr/bin/env python3
"""
Главный оркестрационный скрипт системы пополнения склада.
Координирует работу всех компонентов: анализ запасов, расчет скорости продаж,
генерацию рекомендаций и отправку алертов.
"""

import sys
import os
import logging
import argparse
import json
import time
from datetime import datetime, timedelta
from typing import Dict, List, Optional
from dataclasses import asdict

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from inventory_analyzer import InventoryAnalyzer
from sales_velocity_calculator import SalesVelocityCalculator
from replenishment_recommender import ReplenishmentRecommender
from alert_manager import AlertManager

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('replenishment_orchestrator.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class ReplenishmentOrchestrator:
    """Главный класс для координации всех компонентов системы пополнения."""
    
    def __init__(self, connection=None):
        """
        Инициализация оркестратора.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        self.start_time = datetime.now()
        
        # Инициализируем все компоненты
        self.inventory_analyzer = InventoryAnalyzer(self.connection)
        self.sales_calculator = SalesVelocityCalculator(self.connection)
        self.recommender = ReplenishmentRecommender(self.connection)
        self.alert_manager = AlertManager(self.connection)
        
        # Статистика выполнения
        self.execution_stats = {
            'start_time': self.start_time,
            'products_analyzed': 0,
            'recommendations_generated': 0,
            'alerts_created': 0,
            'errors_count': 0,
            'warnings_count': 0
        }
    
    def run_full_analysis(self, source: Optional[str] = None, 
                         save_to_db: bool = True,
                         send_alerts: bool = True) -> Dict[str, any]:
        """
        Запустить полный анализ пополнения склада.
        
        Args:
            source: Источник данных для анализа (опционально)
            save_to_db: Сохранять результаты в базу данных
            send_alerts: Отправлять алерты и уведомления
            
        Returns:
            Словарь с результатами анализа
        """
        logger.info("🚀 ЗАПУСК ПОЛНОГО АНАЛИЗА ПОПОЛНЕНИЯ СКЛАДА")
        logger.info("=" * 60)
        
        try:
            # Этап 1: Анализ текущих запасов
            logger.info("📦 Этап 1: Анализ текущих запасов")
            inventory_results = self._analyze_inventory(source)
            
            # Этап 2: Расчет скорости продаж
            logger.info("📈 Этап 2: Расчет скорости продаж")
            sales_results = self._calculate_sales_velocity(source)
            
            # Этап 3: Генерация рекомендаций
            logger.info("🎯 Этап 3: Генерация рекомендаций по пополнению")
            recommendations = self._generate_recommendations(source, save_to_db)
            
            # Этап 4: Создание и отправка алертов
            if send_alerts:
                logger.info("🚨 Этап 4: Создание и отправка алертов")
                alert_results = self._process_alerts()
            else:
                alert_results = {'alerts_processed': 0, 'alerts_sent': 0}
            
            # Этап 5: Формирование итогового отчета
            logger.info("📊 Этап 5: Формирование итогового отчета")
            final_report = self._create_final_report(
                inventory_results, sales_results, recommendations, alert_results
            )
            
            # Обновляем статистику
            self.execution_stats['end_time'] = datetime.now()
            self.execution_stats['total_duration'] = (
                self.execution_stats['end_time'] - self.execution_stats['start_time']
            ).total_seconds()
            
            logger.info("✅ ПОЛНЫЙ АНАЛИЗ ЗАВЕРШЕН УСПЕШНО")
            logger.info(f"⏱️  Время выполнения: {self.execution_stats['total_duration']:.1f} сек")
            
            return final_report
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка в полном анализе: {e}")
            self.execution_stats['errors_count'] += 1
            return {'error': str(e), 'execution_stats': self.execution_stats}
    
    def _analyze_inventory(self, source: Optional[str] = None) -> Dict[str, any]:
        """Анализ текущих запасов."""
        try:
            start_time = time.time()
            
            # Получаем текущие запасы
            inventory_items = self.inventory_analyzer.get_current_stock(source=source)
            
            if not inventory_items:
                logger.warning("⚠️  Нет данных о запасах для анализа")
                return {'items_count': 0, 'analysis_time': 0}
            
            # Анализируем запасы
            low_stock_items = []
            zero_stock_items = []
            total_value = 0
            
            for item in inventory_items:
                if item.available_stock <= 0:
                    zero_stock_items.append(item)
                elif item.available_stock <= item.min_stock_level:
                    low_stock_items.append(item)
                
                if item.cost_price:
                    total_value += item.current_stock * item.cost_price
            
            analysis_time = time.time() - start_time
            self.execution_stats['products_analyzed'] = len(inventory_items)
            
            results = {
                'items_count': len(inventory_items),
                'low_stock_count': len(low_stock_items),
                'zero_stock_count': len(zero_stock_items),
                'total_inventory_value': round(total_value, 2),
                'analysis_time': round(analysis_time, 2)
            }
            
            logger.info(f"   📊 Проанализировано товаров: {results['items_count']}")
            logger.info(f"   ⚠️  Товаров с низким остатком: {results['low_stock_count']}")
            logger.info(f"   🚫 Товаров с нулевым остатком: {results['zero_stock_count']}")
            logger.info(f"   💰 Общая стоимость запасов: {results['total_inventory_value']:,.2f} руб")
            
            return results
            
        except Exception as e:
            logger.error(f"Ошибка анализа запасов: {e}")
            self.execution_stats['errors_count'] += 1
            return {'error': str(e)}
    
    def _calculate_sales_velocity(self, source: Optional[str] = None) -> Dict[str, any]:
        """Расчет скорости продаж."""
        try:
            start_time = time.time()
            
            # Получаем список товаров для анализа
            inventory_items = self.inventory_analyzer.get_current_stock(source=source)
            
            if not inventory_items:
                return {'products_analyzed': 0, 'analysis_time': 0}
            
            # Анализируем скорость продаж
            fast_moving_count = 0
            slow_moving_count = 0
            no_sales_count = 0
            
            for item in inventory_items[:100]:  # Ограничиваем для демонстрации
                try:
                    metrics = self.sales_calculator.get_sales_metrics(item.product_id)
                    
                    if metrics.daily_sales_rate_7d > 5:
                        fast_moving_count += 1
                    elif metrics.daily_sales_rate_7d == 0:
                        no_sales_count += 1
                    elif metrics.daily_sales_rate_7d < 1:
                        slow_moving_count += 1
                        
                except Exception as e:
                    logger.debug(f"Ошибка анализа товара {item.sku}: {e}")
                    continue
            
            analysis_time = time.time() - start_time
            
            results = {
                'products_analyzed': min(len(inventory_items), 100),
                'fast_moving_count': fast_moving_count,
                'slow_moving_count': slow_moving_count,
                'no_sales_count': no_sales_count,
                'analysis_time': round(analysis_time, 2)
            }
            
            logger.info(f"   📊 Проанализировано товаров: {results['products_analyzed']}")
            logger.info(f"   🚀 Быстро движущихся: {results['fast_moving_count']}")
            logger.info(f"   🐌 Медленно движущихся: {results['slow_moving_count']}")
            logger.info(f"   🚫 Без продаж: {results['no_sales_count']}")
            
            return results
            
        except Exception as e:
            logger.error(f"Ошибка расчета скорости продаж: {e}")
            self.execution_stats['errors_count'] += 1
            return {'error': str(e)}
    
    def _generate_recommendations(self, source: Optional[str] = None, 
                                save_to_db: bool = True) -> List[Dict]:
        """Генерация рекомендаций по пополнению."""
        try:
            start_time = time.time()
            
            # Генерируем рекомендации
            recommendations = self.recommender.generate_recommendations(source=source)
            
            if not recommendations:
                logger.warning("⚠️  Рекомендации не сгенерированы")
                return []
            
            # Сохраняем в базу данных
            if save_to_db:
                save_success = self.recommender.save_recommendations_to_db(recommendations)
                if not save_success:
                    logger.warning("⚠️  Ошибка сохранения рекомендаций в БД")
                    self.execution_stats['warnings_count'] += 1
            
            # Статистика по приоритетам
            priority_stats = {}
            total_order_value = 0
            
            for rec in recommendations:
                priority = rec.priority_level.value
                priority_stats[priority] = priority_stats.get(priority, 0) + 1
                
                if rec.recommended_order_value:
                    total_order_value += rec.recommended_order_value
            
            analysis_time = time.time() - start_time
            self.execution_stats['recommendations_generated'] = len(recommendations)
            
            logger.info(f"   📊 Сгенерировано рекомендаций: {len(recommendations)}")
            logger.info(f"   🚨 Критических: {priority_stats.get('CRITICAL', 0)}")
            logger.info(f"   ⚠️  Высокоприоритетных: {priority_stats.get('HIGH', 0)}")
            logger.info(f"   💰 Общая стоимость заказов: {total_order_value:,.2f} руб")
            
            # Преобразуем рекомендации в словари для JSON-сериализации
            recommendations_dict = []
            for rec in recommendations[:20]:  # Ограничиваем для демонстрации
                rec_dict = {
                    'product_id': rec.product_id,
                    'sku': rec.sku,
                    'product_name': rec.product_name,
                    'priority_level': rec.priority_level.value,
                    'urgency_score': rec.urgency_score,
                    'current_stock': rec.current_stock,
                    'recommended_order_quantity': rec.recommended_order_quantity,
                    'recommended_order_value': rec.recommended_order_value,
                    'days_until_stockout': rec.days_until_stockout
                }
                recommendations_dict.append(rec_dict)
            
            return recommendations_dict
            
        except Exception as e:
            logger.error(f"Ошибка генерации рекомендаций: {e}")
            self.execution_stats['errors_count'] += 1
            return []
    
    def _process_alerts(self) -> Dict[str, any]:
        """Обработка алертов и уведомлений."""
        try:
            start_time = time.time()
            
            # Обрабатываем все типы алертов
            alert_summary = self.alert_manager.process_all_alerts()
            
            if not alert_summary:
                logger.warning("⚠️  Алерты не обработаны")
                return {'alerts_processed': 0, 'alerts_sent': 0}
            
            analysis_time = time.time() - start_time
            self.execution_stats['alerts_created'] = alert_summary.get('total_alerts', 0)
            
            logger.info(f"   📊 Обработано алертов: {alert_summary.get('total_alerts', 0)}")
            logger.info(f"   🚨 Критических: {alert_summary.get('critical_alerts', 0)}")
            logger.info(f"   📧 Email отправлен: {'✅' if alert_summary.get('email_success') else '❌'}")
            
            return {
                'alerts_processed': alert_summary.get('total_alerts', 0),
                'critical_alerts': alert_summary.get('critical_alerts', 0),
                'email_sent': alert_summary.get('email_success', False),
                'processing_time': round(analysis_time, 2)
            }
            
        except Exception as e:
            logger.error(f"Ошибка обработки алертов: {e}")
            self.execution_stats['errors_count'] += 1
            return {'error': str(e)}
    
    def _create_final_report(self, inventory_results: Dict, sales_results: Dict,
                           recommendations: List[Dict], alert_results: Dict) -> Dict[str, any]:
        """Создание итогового отчета."""
        try:
            report = {
                'analysis_summary': {
                    'execution_date': self.start_time.strftime('%Y-%m-%d %H:%M:%S'),
                    'total_duration_seconds': self.execution_stats.get('total_duration', 0),
                    'products_analyzed': self.execution_stats['products_analyzed'],
                    'recommendations_generated': self.execution_stats['recommendations_generated'],
                    'alerts_created': self.execution_stats['alerts_created'],
                    'errors_count': self.execution_stats['errors_count'],
                    'warnings_count': self.execution_stats['warnings_count']
                },
                'inventory_analysis': inventory_results,
                'sales_analysis': sales_results,
                'recommendations': {
                    'total_count': len(recommendations),
                    'top_recommendations': recommendations[:10] if recommendations else []
                },
                'alerts': alert_results,
                'status': 'SUCCESS' if self.execution_stats['errors_count'] == 0 else 'COMPLETED_WITH_ERRORS'
            }
            
            return report
            
        except Exception as e:
            logger.error(f"Ошибка создания итогового отчета: {e}")
            return {'error': str(e)}
    
    def run_quick_check(self) -> Dict[str, any]:
        """
        Быстрая проверка критических остатков.
        
        Returns:
            Словарь с результатами быстрой проверки
        """
        logger.info("⚡ БЫСТРАЯ ПРОВЕРКА КРИТИЧЕСКИХ ОСТАТКОВ")
        
        try:
            start_time = time.time()
            
            # Получаем критические рекомендации из БД
            critical_recommendations = self.recommender.get_critical_recommendations(limit=50)
            
            # Создаем алерты только для критических товаров
            critical_alerts = self.alert_manager.detect_critical_stock_levels()
            
            # Сохраняем алерты
            if critical_alerts:
                self.alert_manager.save_alerts_to_db(critical_alerts)
                self.alert_manager.send_email_alerts(critical_alerts)
            
            execution_time = time.time() - start_time
            
            results = {
                'execution_time': round(execution_time, 2),
                'critical_recommendations': len(critical_recommendations),
                'critical_alerts': len(critical_alerts),
                'status': 'SUCCESS'
            }
            
            logger.info(f"✅ Быстрая проверка завершена за {execution_time:.1f} сек")
            logger.info(f"   🚨 Критических товаров: {len(critical_recommendations)}")
            logger.info(f"   📢 Алертов создано: {len(critical_alerts)}")
            
            return results
            
        except Exception as e:
            logger.error(f"Ошибка быстрой проверки: {e}")
            return {'error': str(e), 'status': 'ERROR'}
    
    def export_recommendations_to_file(self, filename: str, format: str = 'json') -> bool:
        """
        Экспорт рекомендаций в файл.
        
        Args:
            filename: Имя файла для экспорта
            format: Формат файла ('json', 'csv')
            
        Returns:
            True если экспорт прошел успешно
        """
        try:
            # Получаем последние рекомендации
            recommendations = self.recommender.get_critical_recommendations(limit=1000)
            
            if not recommendations:
                logger.warning("Нет рекомендаций для экспорта")
                return False
            
            if format.lower() == 'json':
                # Экспорт в JSON
                recommendations_data = []
                for rec in recommendations:
                    rec_dict = {
                        'sku': rec.sku,
                        'product_name': rec.product_name,
                        'priority_level': rec.priority_level.value,
                        'current_stock': rec.current_stock,
                        'recommended_order_quantity': rec.recommended_order_quantity,
                        'recommended_order_value': rec.recommended_order_value,
                        'days_until_stockout': rec.days_until_stockout,
                        'urgency_score': rec.urgency_score
                    }
                    recommendations_data.append(rec_dict)
                
                with open(filename, 'w', encoding='utf-8') as f:
                    json.dump(recommendations_data, f, ensure_ascii=False, indent=2)
                
            elif format.lower() == 'csv':
                # Экспорт в CSV
                import csv
                
                with open(filename, 'w', newline='', encoding='utf-8') as f:
                    writer = csv.writer(f)
                    
                    # Заголовки
                    writer.writerow([
                        'SKU', 'Название товара', 'Приоритет', 'Текущий остаток',
                        'Рекомендуемый заказ', 'Стоимость заказа', 'Дней до исчерпания',
                        'Оценка срочности'
                    ])
                    
                    # Данные
                    for rec in recommendations:
                        writer.writerow([
                            rec.sku,
                            rec.product_name,
                            rec.priority_level.value,
                            rec.current_stock,
                            rec.recommended_order_quantity,
                            rec.recommended_order_value or 0,
                            rec.days_until_stockout or 'Н/Д',
                            rec.urgency_score
                        ])
            
            logger.info(f"✅ Экспортировано {len(recommendations)} рекомендаций в {filename}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка экспорта рекомендаций: {e}")
            return False
    
    def close(self):
        """Закрыть все соединения."""
        if self.inventory_analyzer:
            self.inventory_analyzer.close()
        if self.sales_calculator:
            self.sales_calculator.close()
        if self.recommender:
            self.recommender.close()
        if self.alert_manager:
            self.alert_manager.close()
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для запуска оркестратора."""
    parser = argparse.ArgumentParser(description='Система пополнения склада')
    parser.add_argument('--mode', choices=['full', 'quick', 'export'], default='full',
                       help='Режим работы: full (полный анализ), quick (быстрая проверка), export (экспорт)')
    parser.add_argument('--source', type=str, help='Источник данных для анализа')
    parser.add_argument('--no-save', action='store_true', help='Не сохранять результаты в БД')
    parser.add_argument('--no-alerts', action='store_true', help='Не отправлять алерты')
    parser.add_argument('--export-file', type=str, help='Файл для экспорта рекомендаций')
    parser.add_argument('--export-format', choices=['json', 'csv'], default='json',
                       help='Формат экспорта')
    
    args = parser.parse_args()
    
    orchestrator = None
    try:
        # Создаем оркестратор
        orchestrator = ReplenishmentOrchestrator()
        
        if args.mode == 'full':
            # Полный анализ
            results = orchestrator.run_full_analysis(
                source=args.source,
                save_to_db=not args.no_save,
                send_alerts=not args.no_alerts
            )
            
            print("\n" + "="*80)
            print("📊 ИТОГОВЫЙ ОТЧЕТ ПО АНАЛИЗУ ПОПОЛНЕНИЯ СКЛАДА")
            print("="*80)
            
            if 'error' not in results:
                summary = results['analysis_summary']
                print(f"📅 Дата анализа: {summary['execution_date']}")
                print(f"⏱️  Время выполнения: {summary['total_duration_seconds']:.1f} сек")
                print(f"📦 Товаров проанализировано: {summary['products_analyzed']}")
                print(f"🎯 Рекомендаций сгенерировано: {summary['recommendations_generated']}")
                print(f"🚨 Алертов создано: {summary['alerts_created']}")
                print(f"❌ Ошибок: {summary['errors_count']}")
                print(f"⚠️  Предупреждений: {summary['warnings_count']}")
                print(f"✅ Статус: {results['status']}")
                
                # Показываем топ рекомендации
                top_recs = results['recommendations']['top_recommendations']
                if top_recs:
                    print(f"\n🔝 ТОП-{len(top_recs)} КРИТИЧЕСКИХ РЕКОМЕНДАЦИЙ:")
                    for i, rec in enumerate(top_recs, 1):
                        print(f"{i:2d}. {rec['sku']} - {rec['product_name'][:40]}")
                        print(f"     Приоритет: {rec['priority_level']} | Остаток: {rec['current_stock']} шт | Заказать: {rec['recommended_order_quantity']} шт")
            else:
                print(f"❌ Ошибка выполнения: {results['error']}")
        
        elif args.mode == 'quick':
            # Быстрая проверка
            results = orchestrator.run_quick_check()
            
            print("\n⚡ РЕЗУЛЬТАТЫ БЫСТРОЙ ПРОВЕРКИ:")
            print("="*50)
            print(f"⏱️  Время выполнения: {results.get('execution_time', 0)} сек")
            print(f"🚨 Критических товаров: {results.get('critical_recommendations', 0)}")
            print(f"📢 Алертов создано: {results.get('critical_alerts', 0)}")
            print(f"✅ Статус: {results.get('status', 'UNKNOWN')}")
        
        elif args.mode == 'export':
            # Экспорт рекомендаций
            if not args.export_file:
                print("❌ Не указан файл для экспорта (--export-file)")
                return
            
            success = orchestrator.export_recommendations_to_file(
                args.export_file, args.export_format
            )
            
            if success:
                print(f"✅ Рекомендации экспортированы в {args.export_file}")
            else:
                print(f"❌ Ошибка экспорта в {args.export_file}")
        
        print("\n🎉 Работа оркестратора завершена!")
        
    except KeyboardInterrupt:
        logger.info("Работа прервана пользователем")
        print("\n⏹️  Работа прервана пользователем")
        
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}")
        print(f"\n❌ Критическая ошибка: {e}")
        
    finally:
        if orchestrator:
            orchestrator.close()


if __name__ == "__main__":
    main()