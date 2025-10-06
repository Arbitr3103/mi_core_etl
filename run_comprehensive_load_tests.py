#!/usr/bin/env python3
"""
Комплексный запуск всех нагрузочных тестов системы синхронизации остатков.

Выполняет:
- Нагрузочное тестирование
- Бенчмарк производительности  
- Стресс-тестирование
- Анализ и сравнение результатов
- Генерацию итогового отчета

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import time
import json
import argparse
from datetime import datetime
from typing import Dict, List, Any, Optional
import logging
import subprocess
import psutil
from pathlib import Path

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from load_test_inventory_sync import LoadTester, LoadTestConfig, save_test_report, print_test_summary
    from performance_benchmark import PerformanceBenchmark, save_benchmark_report, print_benchmark_summary
    from stress_test_inventory_sync import StressTester, StressTestConfig, save_stress_test_report, print_stress_test_summary
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class ComprehensiveTestRunner:
    """Комплексный запуск всех типов нагрузочных тестов."""
    
    def __init__(self, output_dir: str = "test_results"):
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(exist_ok=True)
        self.timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        
        # Результаты тестов
        self.load_test_report: Optional[Dict[str, Any]] = None
        self.benchmark_report: Optional[Dict[str, Any]] = None
        self.stress_test_report: Optional[Dict[str, Any]] = None
        
    def check_system_requirements(self) -> Dict[str, Any]:
        """Проверка системных требований для тестирования."""
        logger.info("🔍 Проверка системных требований...")
        
        # Получаем информацию о системе
        cpu_count = psutil.cpu_count()
        memory_gb = psutil.virtual_memory().total / 1024 / 1024 / 1024
        disk_free_gb = psutil.disk_usage('.').free / 1024 / 1024 / 1024
        
        requirements = {
            'system_info': {
                'cpu_count': cpu_count,
                'memory_gb': memory_gb,
                'disk_free_gb': disk_free_gb,
                'platform': sys.platform
            },
            'requirements_met': True,
            'warnings': [],
            'recommendations': []
        }
        
        # Проверяем минимальные требования
        if cpu_count < 4:
            requirements['warnings'].append(f"Мало CPU ядер: {cpu_count} < 4 рекомендуемых")
            requirements['recommendations'].append("Рекомендуется минимум 4 CPU ядра для полноценного тестирования")
        
        if memory_gb < 8:
            requirements['warnings'].append(f"Мало оперативной памяти: {memory_gb:.1f} ГБ < 8 ГБ рекомендуемых")
            requirements['recommendations'].append("Рекомендуется минимум 8 ГБ RAM для стресс-тестирования")
        
        if disk_free_gb < 2:
            requirements['warnings'].append(f"Мало свободного места: {disk_free_gb:.1f} ГБ < 2 ГБ рекомендуемых")
            requirements['recommendations'].append("Освободите минимум 2 ГБ дискового пространства")
            requirements['requirements_met'] = False
        
        # Проверяем доступность Python модулей
        required_modules = ['psutil', 'asyncio', 'concurrent.futures', 'multiprocessing']
        missing_modules = []
        
        for module in required_modules:
            try:
                __import__(module)
            except ImportError:
                missing_modules.append(module)
        
        if missing_modules:
            requirements['warnings'].append(f"Отсутствуют модули: {', '.join(missing_modules)}")
            requirements['requirements_met'] = False
        
        # Выводим результаты проверки
        if requirements['requirements_met']:
            logger.info("✅ Системные требования выполнены")
        else:
            logger.warning("⚠️ Не все системные требования выполнены")
        
        for warning in requirements['warnings']:
            logger.warning(f"⚠️ {warning}")
        
        return requirements
    
    def run_load_tests(self, config: Optional[LoadTestConfig] = None) -> Dict[str, Any]:
        """Запуск нагрузочных тестов."""
        logger.info("🚀 Запуск нагрузочных тестов...")
        
        if config is None:
            config = LoadTestConfig(
                small_dataset_size=1000,
                medium_dataset_size=5000,
                large_dataset_size=20000,  # Уменьшено для стабильности
                xlarge_dataset_size=50000,
                max_workers=4,
                batch_sizes=[100, 500, 1000, 2000],
                max_memory_mb=1024,
                max_cpu_percent=85.0,
                min_throughput_per_second=50.0
            )
        
        tester = LoadTester(config)
        
        try:
            report = tester.run_full_load_test_suite()
            
            # Сохраняем отчет
            filename = self.output_dir / f"load_test_report_{self.timestamp}.json"
            with open(filename, 'w', encoding='utf-8') as f:
                json.dump(report, f, indent=2, ensure_ascii=False, default=str)
            
            logger.info(f"📄 Отчет нагрузочных тестов сохранен: {filename}")
            return report
            
        except Exception as e:
            logger.error(f"❌ Ошибка нагрузочных тестов: {e}")
            raise
        finally:
            tester.cleanup()
    
    def run_performance_benchmark(self) -> Dict[str, Any]:
        """Запуск бенчмарка производительности."""
        logger.info("🚀 Запуск бенчмарка производительности...")
        
        benchmark = PerformanceBenchmark()
        
        try:
            report = benchmark.run_comprehensive_benchmark()
            
            # Сохраняем отчет
            filename = self.output_dir / f"benchmark_report_{self.timestamp}.json"
            with open(filename, 'w', encoding='utf-8') as f:
                json.dump(report, f, indent=2, ensure_ascii=False, default=str)
            
            logger.info(f"📄 Отчет бенчмарка сохранен: {filename}")
            return report
            
        except Exception as e:
            logger.error(f"❌ Ошибка бенчмарка производительности: {e}")
            raise
        finally:
            benchmark.cleanup()
    
    def run_stress_tests(self, config: Optional[StressTestConfig] = None) -> Dict[str, Any]:
        """Запуск стресс-тестов."""
        logger.info("🚀 Запуск стресс-тестов...")
        
        if config is None:
            config = StressTestConfig(
                max_dataset_size=25000,  # Уменьшено для стабильности
                concurrent_processes=4,
                test_duration_minutes=3,  # Сокращено для демо
                memory_pressure_mb=512,  # 512 МБ
                error_injection_rate=0.05,
                network_failure_rate=0.02,
                memory_leak_simulation=True,
                max_memory_mb=1024,
                max_cpu_percent=90.0
            )
        
        tester = StressTester(config)
        
        try:
            report = tester.run_comprehensive_stress_test()
            
            # Сохраняем отчет
            filename = self.output_dir / f"stress_test_report_{self.timestamp}.json"
            with open(filename, 'w', encoding='utf-8') as f:
                json.dump(report, f, indent=2, ensure_ascii=False, default=str)
            
            logger.info(f"📄 Отчет стресс-тестов сохранен: {filename}")
            return report
            
        except Exception as e:
            logger.error(f"❌ Ошибка стресс-тестов: {e}")
            raise
        finally:
            tester.cleanup()
    
    def analyze_combined_results(self) -> Dict[str, Any]:
        """Анализ объединенных результатов всех тестов."""
        logger.info("📊 Анализ объединенных результатов...")
        
        analysis = {
            'overall_assessment': 'unknown',
            'performance_grade': 'unknown',
            'stability_grade': 'unknown',
            'scalability_grade': 'unknown',
            'key_findings': [],
            'critical_issues': [],
            'optimization_opportunities': [],
            'production_readiness': 'unknown'
        }
        
        # Анализ нагрузочных тестов
        if self.load_test_report:
            load_analysis = self.load_test_report.get('performance_analysis', {})
            
            # Проверяем производительность
            throughput_issues = []
            memory_issues = []
            
            for test_type, stats in load_analysis.items():
                avg_throughput = stats.get('throughput', {}).get('avg', 0)
                max_memory = stats.get('memory_usage', {}).get('max_mb', 0)
                
                if avg_throughput < 100:  # Менее 100 записей/сек
                    throughput_issues.append(f"{test_type}: {avg_throughput:.1f} записей/сек")
                
                if max_memory > 1024:  # Более 1 ГБ
                    memory_issues.append(f"{test_type}: {max_memory:.1f} МБ")
            
            if throughput_issues:
                analysis['critical_issues'].extend([
                    "Низкая производительность:",
                    *[f"  - {issue}" for issue in throughput_issues]
                ])
                analysis['performance_grade'] = 'poor'
            else:
                analysis['performance_grade'] = 'good'
            
            if memory_issues:
                analysis['optimization_opportunities'].extend([
                    "Оптимизация памяти:",
                    *[f"  - {issue}" for issue in memory_issues]
                ])
        
        # Анализ бенчмарка
        if self.benchmark_report:
            benchmark_analysis = self.benchmark_report.get('performance_analysis', {})
            optimal_configs = self.benchmark_report.get('optimal_configurations', {})
            
            # Проверяем масштабируемость
            scalability_factors = []
            for test_type, stats in benchmark_analysis.items():
                scalability_factor = stats.get('scalability_factor', 1.0)
                scalability_factors.append(scalability_factor)
            
            if scalability_factors:
                avg_scalability = sum(scalability_factors) / len(scalability_factors)
                if avg_scalability > 0.8:
                    analysis['scalability_grade'] = 'good'
                elif avg_scalability > 0.6:
                    analysis['scalability_grade'] = 'fair'
                else:
                    analysis['scalability_grade'] = 'poor'
                    analysis['critical_issues'].append(
                        f"Плохая масштабируемость: коэффициент {avg_scalability:.2f}"
                    )
            
            # Добавляем оптимальные конфигурации
            if optimal_configs:
                analysis['key_findings'].append("Оптимальные конфигурации найдены:")
                for config_type, config_data in optimal_configs.items():
                    analysis['key_findings'].append(f"  - {config_type}: {config_data}")
        
        # Анализ стресс-тестов
        if self.stress_test_report:
            stress_analysis = self.stress_test_report.get('stability_analysis', {})
            
            overall_stability = stress_analysis.get('overall_stability', 'unknown')
            critical_issues = stress_analysis.get('critical_issues', [])
            
            if overall_stability == 'stable':
                analysis['stability_grade'] = 'good'
            elif overall_stability == 'degraded':
                analysis['stability_grade'] = 'fair'
            else:
                analysis['stability_grade'] = 'poor'
            
            if critical_issues:
                analysis['critical_issues'].extend([
                    "Проблемы стабильности:",
                    *[f"  - {issue}" for issue in critical_issues]
                ])
        
        # Общая оценка
        grades = [
            analysis['performance_grade'],
            analysis['stability_grade'],
            analysis['scalability_grade']
        ]
        
        if all(grade == 'good' for grade in grades if grade != 'unknown'):
            analysis['overall_assessment'] = 'excellent'
            analysis['production_readiness'] = 'ready'
        elif any(grade == 'poor' for grade in grades):
            analysis['overall_assessment'] = 'needs_improvement'
            analysis['production_readiness'] = 'not_ready'
        else:
            analysis['overall_assessment'] = 'acceptable'
            analysis['production_readiness'] = 'ready_with_monitoring'
        
        return analysis
    
    def generate_comprehensive_report(self) -> Dict[str, Any]:
        """Генерация комплексного отчета."""
        logger.info("📋 Генерация комплексного отчета...")
        
        # Проверяем системные требования
        system_check = self.check_system_requirements()
        
        # Анализируем объединенные результаты
        combined_analysis = self.analyze_combined_results()
        
        # Создаем комплексный отчет
        comprehensive_report = {
            'report_metadata': {
                'generated_at': datetime.now().isoformat(),
                'test_timestamp': self.timestamp,
                'report_version': '1.0',
                'system_info': system_check['system_info']
            },
            'system_requirements_check': system_check,
            'test_results': {
                'load_tests': self.load_test_report,
                'performance_benchmark': self.benchmark_report,
                'stress_tests': self.stress_test_report
            },
            'combined_analysis': combined_analysis,
            'executive_summary': self._generate_executive_summary(combined_analysis),
            'detailed_recommendations': self._generate_detailed_recommendations(combined_analysis)
        }
        
        # Сохраняем комплексный отчет
        filename = self.output_dir / f"comprehensive_report_{self.timestamp}.json"
        with open(filename, 'w', encoding='utf-8') as f:
            json.dump(comprehensive_report, f, indent=2, ensure_ascii=False, default=str)
        
        logger.info(f"📄 Комплексный отчет сохранен: {filename}")
        
        # Генерируем HTML отчет
        self._generate_html_report(comprehensive_report)
        
        return comprehensive_report
    
    def _generate_executive_summary(self, analysis: Dict[str, Any]) -> Dict[str, Any]:
        """Генерация краткого резюме для руководства."""
        return {
            'overall_assessment': analysis['overall_assessment'],
            'production_readiness': analysis['production_readiness'],
            'key_metrics': {
                'performance_grade': analysis['performance_grade'],
                'stability_grade': analysis['stability_grade'],
                'scalability_grade': analysis['scalability_grade']
            },
            'critical_actions_required': len(analysis['critical_issues']) > 0,
            'estimated_capacity': self._estimate_system_capacity(),
            'risk_level': self._assess_risk_level(analysis)
        }
    
    def _estimate_system_capacity(self) -> Dict[str, Any]:
        """Оценка производительности системы."""
        capacity = {
            'max_records_per_hour': 'unknown',
            'concurrent_users': 'unknown',
            'recommended_batch_size': 'unknown'
        }
        
        if self.benchmark_report:
            optimal_configs = self.benchmark_report.get('optimal_configurations', {})
            
            if 'batch_size' in optimal_configs:
                batch_config = optimal_configs['batch_size']
                throughput = batch_config.get('throughput', 0)
                capacity['max_records_per_hour'] = int(throughput * 3600)
                capacity['recommended_batch_size'] = batch_config.get('size', 1000)
        
        return capacity
    
    def _assess_risk_level(self, analysis: Dict[str, Any]) -> str:
        """Оценка уровня риска для продакшена."""
        if analysis['production_readiness'] == 'not_ready':
            return 'high'
        elif analysis['production_readiness'] == 'ready_with_monitoring':
            return 'medium'
        else:
            return 'low'
    
    def _generate_detailed_recommendations(self, analysis: Dict[str, Any]) -> List[str]:
        """Генерация детальных рекомендаций."""
        recommendations = []
        
        # Рекомендации по производительности
        if analysis['performance_grade'] == 'poor':
            recommendations.extend([
                "ПРОИЗВОДИТЕЛЬНОСТЬ - КРИТИЧНО:",
                "- Оптимизировать алгоритмы обработки данных",
                "- Увеличить размер батчей для снижения накладных расходов",
                "- Рассмотреть использование более мощного оборудования",
                "- Реализовать кэширование для часто используемых данных"
            ])
        
        # Рекомендации по стабильности
        if analysis['stability_grade'] == 'poor':
            recommendations.extend([
                "СТАБИЛЬНОСТЬ - КРИТИЧНО:",
                "- Улучшить обработку ошибок и механизмы восстановления",
                "- Добавить circuit breaker для защиты от каскадных сбоев",
                "- Реализовать graceful degradation при высокой нагрузке",
                "- Усилить мониторинг и алертинг"
            ])
        
        # Рекомендации по масштабируемости
        if analysis['scalability_grade'] == 'poor':
            recommendations.extend([
                "МАСШТАБИРУЕМОСТЬ - ТРЕБУЕТ ВНИМАНИЯ:",
                "- Оптимизировать архитектуру для горизонтального масштабирования",
                "- Рассмотреть использование очередей сообщений",
                "- Реализовать шардинг данных",
                "- Добавить автоматическое масштабирование ресурсов"
            ])
        
        # Общие рекомендации
        recommendations.extend([
            "ОБЩИЕ РЕКОМЕНДАЦИИ:",
            "- Регулярно проводить нагрузочное тестирование",
            "- Мониторить ключевые метрики производительности",
            "- Планировать capacity planning на основе роста нагрузки",
            "- Документировать оптимальные конфигурации системы"
        ])
        
        return recommendations
    
    def _generate_html_report(self, report: Dict[str, Any]):
        """Генерация HTML отчета."""
        html_content = f"""
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчет о нагрузочном тестировании - {self.timestamp}</title>
    <style>
        body {{ font-family: Arial, sans-serif; margin: 20px; }}
        .header {{ background-color: #f0f0f0; padding: 20px; border-radius: 5px; }}
        .section {{ margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }}
        .grade-good {{ color: green; font-weight: bold; }}
        .grade-fair {{ color: orange; font-weight: bold; }}
        .grade-poor {{ color: red; font-weight: bold; }}
        .critical {{ background-color: #ffe6e6; padding: 10px; border-left: 4px solid red; }}
        .success {{ background-color: #e6ffe6; padding: 10px; border-left: 4px solid green; }}
        table {{ width: 100%; border-collapse: collapse; }}
        th, td {{ border: 1px solid #ddd; padding: 8px; text-align: left; }}
        th {{ background-color: #f2f2f2; }}
    </style>
</head>
<body>
    <div class="header">
        <h1>Отчет о нагрузочном тестировании системы синхронизации остатков</h1>
        <p><strong>Дата генерации:</strong> {report['report_metadata']['generated_at']}</p>
        <p><strong>Система:</strong> {report['report_metadata']['system_info']['cpu_count']} CPU, 
           {report['report_metadata']['system_info']['memory_gb']:.1f} ГБ RAM</p>
    </div>
    
    <div class="section">
        <h2>Краткое резюме</h2>
        <table>
            <tr><th>Метрика</th><th>Значение</th></tr>
            <tr><td>Общая оценка</td><td class="grade-{report['combined_analysis']['overall_assessment']}">{report['combined_analysis']['overall_assessment']}</td></tr>
            <tr><td>Готовность к продакшену</td><td>{report['combined_analysis']['production_readiness']}</td></tr>
            <tr><td>Производительность</td><td class="grade-{report['combined_analysis']['performance_grade']}">{report['combined_analysis']['performance_grade']}</td></tr>
            <tr><td>Стабильность</td><td class="grade-{report['combined_analysis']['stability_grade']}">{report['combined_analysis']['stability_grade']}</td></tr>
            <tr><td>Масштабируемость</td><td class="grade-{report['combined_analysis']['scalability_grade']}">{report['combined_analysis']['scalability_grade']}</td></tr>
        </table>
    </div>
    
    <div class="section">
        <h2>Ключевые находки</h2>
        <ul>
        {"".join(f"<li>{finding}</li>" for finding in report['combined_analysis']['key_findings'])}
        </ul>
    </div>
    
    <div class="section">
        <h2>Рекомендации</h2>
        <ul>
        {"".join(f"<li>{rec}</li>" for rec in report['detailed_recommendations'])}
        </ul>
    </div>
</body>
</html>
        """
        
        html_filename = self.output_dir / f"comprehensive_report_{self.timestamp}.html"
        with open(html_filename, 'w', encoding='utf-8') as f:
            f.write(html_content)
        
        logger.info(f"📄 HTML отчет сохранен: {html_filename}")
    
    def run_all_tests(self, 
                     load_config: Optional[LoadTestConfig] = None,
                     stress_config: Optional[StressTestConfig] = None) -> Dict[str, Any]:
        """Запуск всех тестов и генерация комплексного отчета."""
        logger.info("🚀 Запуск комплексного тестирования системы")
        
        start_time = datetime.now()
        
        try:
            # Проверяем системные требования
            system_check = self.check_system_requirements()
            if not system_check['requirements_met']:
                logger.error("❌ Системные требования не выполнены")
                return {'error': 'System requirements not met', 'details': system_check}
            
            # Запускаем нагрузочные тесты
            logger.info("=" * 60)
            logger.info("ЭТАП 1: НАГРУЗОЧНОЕ ТЕСТИРОВАНИЕ")
            logger.info("=" * 60)
            self.load_test_report = self.run_load_tests(load_config)
            
            # Запускаем бенчмарк производительности
            logger.info("=" * 60)
            logger.info("ЭТАП 2: БЕНЧМАРК ПРОИЗВОДИТЕЛЬНОСТИ")
            logger.info("=" * 60)
            self.benchmark_report = self.run_performance_benchmark()
            
            # Запускаем стресс-тесты
            logger.info("=" * 60)
            logger.info("ЭТАП 3: СТРЕСС-ТЕСТИРОВАНИЕ")
            logger.info("=" * 60)
            self.stress_test_report = self.run_stress_tests(stress_config)
            
            # Генерируем комплексный отчет
            logger.info("=" * 60)
            logger.info("ЭТАП 4: АНАЛИЗ И ОТЧЕТНОСТЬ")
            logger.info("=" * 60)
            comprehensive_report = self.generate_comprehensive_report()
            
            end_time = datetime.now()
            total_duration = (end_time - start_time).total_seconds() / 60
            
            logger.info(f"✅ Комплексное тестирование завершено за {total_duration:.1f} минут")
            
            return comprehensive_report
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка комплексного тестирования: {e}")
            raise


def print_comprehensive_summary(report: Dict[str, Any]):
    """Вывод краткой сводки комплексного отчета."""
    print("\n" + "="*80)
    print("КОМПЛЕКСНЫЙ ОТЧЕТ О НАГРУЗОЧНОМ ТЕСТИРОВАНИИ")
    print("="*80)
    
    if 'error' in report:
        print(f"❌ ОШИБКА: {report['error']}")
        return
    
    # Краткое резюме
    executive_summary = report.get('executive_summary', {})
    print(f"Общая оценка: {executive_summary.get('overall_assessment', 'unknown').upper()}")
    print(f"Готовность к продакшену: {executive_summary.get('production_readiness', 'unknown').upper()}")
    
    # Оценки по категориям
    key_metrics = executive_summary.get('key_metrics', {})
    print(f"\nОценки:")
    print(f"  Производительность: {key_metrics.get('performance_grade', 'unknown').upper()}")
    print(f"  Стабильность: {key_metrics.get('stability_grade', 'unknown').upper()}")
    print(f"  Масштабируемость: {key_metrics.get('scalability_grade', 'unknown').upper()}")
    
    # Критические проблемы
    combined_analysis = report.get('combined_analysis', {})
    critical_issues = combined_analysis.get('critical_issues', [])
    
    if critical_issues:
        print(f"\n❌ КРИТИЧЕСКИЕ ПРОБЛЕМЫ ({len(critical_issues)}):")
        for issue in critical_issues[:5]:  # Показываем первые 5
            print(f"  - {issue}")
        if len(critical_issues) > 5:
            print(f"  ... и еще {len(critical_issues) - 5} проблем")
    else:
        print(f"\n✅ Критических проблем не обнаружено")
    
    # Рекомендации
    recommendations = report.get('detailed_recommendations', [])
    if recommendations:
        print(f"\nТОП-5 РЕКОМЕНДАЦИЙ:")
        for rec in recommendations[:5]:
            print(f"  - {rec}")
    
    print("\n" + "="*80)


def main():
    """Основная функция."""
    parser = argparse.ArgumentParser(description='Комплексное нагрузочное тестирование системы синхронизации')
    parser.add_argument('--output-dir', default='test_results', help='Директория для сохранения результатов')
    parser.add_argument('--quick', action='store_true', help='Быстрое тестирование с уменьшенными нагрузками')
    parser.add_argument('--load-only', action='store_true', help='Только нагрузочные тесты')
    parser.add_argument('--benchmark-only', action='store_true', help='Только бенчмарк производительности')
    parser.add_argument('--stress-only', action='store_true', help='Только стресс-тесты')
    
    args = parser.parse_args()
    
    # Создаем тестер
    runner = ComprehensiveTestRunner(args.output_dir)
    
    try:
        if args.quick:
            # Быстрая конфигурация для демонстрации
            load_config = LoadTestConfig(
                small_dataset_size=500,
                medium_dataset_size=2000,
                large_dataset_size=5000,
                xlarge_dataset_size=10000,
                batch_sizes=[100, 500, 1000],
                max_memory_mb=512,
                test_duration_minutes=5
            )
            
            stress_config = StressTestConfig(
                max_dataset_size=5000,
                concurrent_processes=2,
                test_duration_minutes=1,
                memory_pressure_mb=256,
                max_memory_mb=512
            )
        else:
            load_config = None
            stress_config = None
        
        # Запускаем выбранные тесты
        if args.load_only:
            report = runner.run_load_tests(load_config)
            print_test_summary(report)
        elif args.benchmark_only:
            report = runner.run_performance_benchmark()
            print_benchmark_summary(report)
        elif args.stress_only:
            report = runner.run_stress_tests(stress_config)
            print_stress_test_summary(report)
        else:
            # Запускаем все тесты
            report = runner.run_all_tests(load_config, stress_config)
            print_comprehensive_summary(report)
        
        logger.info("✅ Тестирование завершено успешно")
        
    except KeyboardInterrupt:
        logger.info("🛑 Тестирование прервано пользователем")
    except Exception as e:
        logger.error(f"❌ Критическая ошибка: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()