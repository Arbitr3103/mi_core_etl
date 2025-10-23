#!/usr/bin/env python3
"""
Ozon Analytics Update Monitor
Система мониторинга еженедельного обновления данных Ozon

Предоставляет функции для:
- Проверки статуса последнего обновления
- Мониторинга производительности
- Анализа логов
- Отправки алертов

Автор: Manhattan System
Версия: 1.0
"""

import os
import sys
import json
import logging
import argparse
from datetime import datetime, timedelta, date
from typing import Dict, List, Optional, Tuple
import mysql.connector
from mysql.connector import Error
import glob
import re

class OzonUpdateMonitor:
    """Класс для мониторинга системы обновления Ozon"""
    
    def __init__(self, config_file: str = 'config.py'):
        """
        Инициализация монитора
        
        Args:
            config_file: путь к файлу конфигурации
        """
        self.config = self._load_config(config_file)
        self.logger = self._setup_logging()
        self.db_connection = None
        
    def _load_config(self, config_file: str) -> Dict:
        """Загрузка конфигурации из файла"""
        try:
            sys.path.append(os.path.dirname(os.path.abspath(config_file)))
            config_module = __import__(os.path.splitext(os.path.basename(config_file))[0])
            
            return {
                'database': {
                    'host': getattr(config_module, 'DB_HOST', 'localhost'),
                    'user': getattr(config_module, 'DB_USER', 'root'),
                    'password': getattr(config_module, 'DB_PASSWORD', ''),
                    'database': getattr(config_module, 'DB_NAME', 'manhattan'),
                    'port': getattr(config_module, 'DB_PORT', 3306)
                },
                'monitoring': {
                    'log_dir': getattr(config_module, 'LOG_DIR', 'logs'),
                    'alert_threshold_hours': 168,  # 7 дней
                    'performance_threshold_minutes': 30
                }
            }
        except Exception as e:
            print(f"Предупреждение: Не удалось загрузить конфигурацию: {e}")
            return self._get_default_config()
    
    def _get_default_config(self) -> Dict:
        """Возвращает базовую конфигурацию"""
        return {
            'database': {
                'host': 'localhost',
                'user': 'root',
                'password': '',
                'database': 'manhattan',
                'port': 3306
            },
            'monitoring': {
                'log_dir': 'logs',
                'alert_threshold_hours': 168,
                'performance_threshold_minutes': 30
            }
        }
    
    def _setup_logging(self) -> logging.Logger:
        """Настройка системы логирования"""
        logger = logging.getLogger('ozon_monitor')
        logger.setLevel(logging.INFO)
        
        if not logger.handlers:
            handler = logging.StreamHandler()
            formatter = logging.Formatter(
                '%(asctime)s - %(name)s - %(levelname)s - %(message)s'
            )
            handler.setFormatter(formatter)
            logger.addHandler(handler)
        
        return logger
    
    def connect_database(self) -> bool:
        """Подключение к базе данных"""
        try:
            self.db_connection = mysql.connector.connect(**self.config['database'])
            if self.db_connection.is_connected():
                return True
        except Error as e:
            self.logger.error(f"Ошибка подключения к базе данных: {e}")
        return False
    
    def disconnect_database(self):
        """Отключение от базы данных"""
        if self.db_connection and self.db_connection.is_connected():
            self.db_connection.close()
    
    def get_last_update_status(self) -> Dict:
        """
        Получение статуса последнего обновления
        
        Returns:
            Dict со статусом обновления
        """
        status = {
            'funnel_data': {'last_update': None, 'records_count': 0},
            'demographics': {'last_update': None, 'records_count': 0},
            'campaigns': {'last_update': None, 'records_count': 0},
            'overall_status': 'unknown'
        }
        
        if not self.connect_database():
            status['overall_status'] = 'database_error'
            return status
        
        try:
            cursor = self.db_connection.cursor()
            
            # Статус данных воронки
            cursor.execute("""
                SELECT MAX(cached_at) as last_update, COUNT(*) as records_count
                FROM ozon_funnel_data
                WHERE cached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            """)
            result = cursor.fetchone()
            if result:
                status['funnel_data']['last_update'] = result[0]
                status['funnel_data']['records_count'] = result[1]
            
            # Статус демографических данных
            cursor.execute("""
                SELECT MAX(cached_at) as last_update, COUNT(*) as records_count
                FROM ozon_demographics
                WHERE cached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            """)
            result = cursor.fetchone()
            if result:
                status['demographics']['last_update'] = result[0]
                status['demographics']['records_count'] = result[1]
            
            # Статус данных кампаний
            cursor.execute("""
                SELECT MAX(cached_at) as last_update, COUNT(*) as records_count
                FROM ozon_campaigns
                WHERE cached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            """)
            result = cursor.fetchone()
            if result:
                status['campaigns']['last_update'] = result[0]
                status['campaigns']['records_count'] = result[1]
            
            cursor.close()
            
            # Определяем общий статус
            now = datetime.now()
            threshold = now - timedelta(hours=self.config['monitoring']['alert_threshold_hours'])
            
            recent_updates = []
            for data_type in ['funnel_data', 'demographics', 'campaigns']:
                last_update = status[data_type]['last_update']
                if last_update and last_update > threshold:
                    recent_updates.append(data_type)
            
            if len(recent_updates) >= 2:  # Минимум 2 типа данных обновлены
                status['overall_status'] = 'healthy'
            elif len(recent_updates) >= 1:
                status['overall_status'] = 'warning'
            else:
                status['overall_status'] = 'critical'
                
        except Error as e:
            self.logger.error(f"Ошибка получения статуса: {e}")
            status['overall_status'] = 'error'
        finally:
            self.disconnect_database()
        
        return status
    
    def analyze_logs(self, days: int = 7) -> Dict:
        """
        Анализ логов за указанный период
        
        Args:
            days: количество дней для анализа
            
        Returns:
            Dict с результатами анализа
        """
        log_dir = self.config['monitoring']['log_dir']
        analysis = {
            'total_runs': 0,
            'successful_runs': 0,
            'failed_runs': 0,
            'warnings_count': 0,
            'errors_count': 0,
            'average_duration': None,
            'last_run': None,
            'performance_issues': []
        }
        
        if not os.path.exists(log_dir):
            self.logger.warning(f"Директория логов не найдена: {log_dir}")
            return analysis
        
        # Поиск файлов логов за указанный период
        cutoff_date = datetime.now() - timedelta(days=days)
        log_files = []
        
        for log_file in glob.glob(os.path.join(log_dir, "ozon_update_*.log")):
            try:
                # Извлекаем дату из имени файла
                filename = os.path.basename(log_file)
                date_match = re.search(r'ozon_update_(\d{8})_\d{6}\.log', filename)
                if date_match:
                    file_date = datetime.strptime(date_match.group(1), '%Y%m%d')
                    if file_date >= cutoff_date:
                        log_files.append(log_file)
            except Exception as e:
                self.logger.warning(f"Ошибка обработки файла лога {log_file}: {e}")
        
        # Анализ каждого файла лога
        for log_file in log_files:
            try:
                run_analysis = self._analyze_single_log(log_file)
                analysis['total_runs'] += 1
                
                if run_analysis['success']:
                    analysis['successful_runs'] += 1
                else:
                    analysis['failed_runs'] += 1
                
                analysis['warnings_count'] += run_analysis['warnings']
                analysis['errors_count'] += run_analysis['errors']
                
                if run_analysis['duration']:
                    if analysis['average_duration'] is None:
                        analysis['average_duration'] = run_analysis['duration']
                    else:
                        analysis['average_duration'] = (
                            analysis['average_duration'] + run_analysis['duration']
                        ) / 2
                
                if run_analysis['start_time']:
                    if analysis['last_run'] is None or run_analysis['start_time'] > analysis['last_run']:
                        analysis['last_run'] = run_analysis['start_time']
                
                # Проверка производительности
                threshold_minutes = self.config['monitoring']['performance_threshold_minutes']
                if run_analysis['duration'] and run_analysis['duration'] > threshold_minutes * 60:
                    analysis['performance_issues'].append({
                        'file': log_file,
                        'duration': run_analysis['duration'],
                        'start_time': run_analysis['start_time']
                    })
                    
            except Exception as e:
                self.logger.error(f"Ошибка анализа лога {log_file}: {e}")
        
        return analysis
    
    def _analyze_single_log(self, log_file: str) -> Dict:
        """Анализ одного файла лога"""
        analysis = {
            'success': False,
            'warnings': 0,
            'errors': 0,
            'duration': None,
            'start_time': None,
            'end_time': None
        }
        
        try:
            with open(log_file, 'r', encoding='utf-8') as f:
                content = f.read()
                
                # Поиск маркеров успеха/ошибки
                if 'ОБНОВЛЕНИЕ ЗАВЕРШЕНО УСПЕШНО' in content:
                    analysis['success'] = True
                elif 'ОБНОВЛЕНИЕ ЗАВЕРШЕНО С ОШИБКОЙ' in content:
                    analysis['success'] = False
                
                # Подсчет предупреждений и ошибок
                analysis['warnings'] = content.count('WARNING')
                analysis['errors'] = content.count('ERROR')
                
                # Поиск времени начала и окончания
                start_match = re.search(r'=== Начало еженедельного обновления данных Ozon ===', content)
                end_match = re.search(r'=== Еженедельное обновление данных Ozon завершено', content)
                
                if start_match and end_match:
                    # Попытка извлечь временные метки
                    lines = content.split('\n')
                    for i, line in enumerate(lines):
                        if '=== Начало еженедельного обновления' in line:
                            timestamp_match = re.search(r'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})', line)
                            if timestamp_match:
                                analysis['start_time'] = datetime.strptime(
                                    timestamp_match.group(1), '%Y-%m-%d %H:%M:%S'
                                )
                            break
                    
                    for i, line in enumerate(lines):
                        if '=== Еженедельное обновление данных Ozon завершено' in line:
                            timestamp_match = re.search(r'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})', line)
                            if timestamp_match:
                                analysis['end_time'] = datetime.strptime(
                                    timestamp_match.group(1), '%Y-%m-%d %H:%M:%S'
                                )
                            break
                    
                    # Вычисление продолжительности
                    if analysis['start_time'] and analysis['end_time']:
                        duration = analysis['end_time'] - analysis['start_time']
                        analysis['duration'] = duration.total_seconds()
                        
        except Exception as e:
            self.logger.error(f"Ошибка чтения файла лога {log_file}: {e}")
        
        return analysis
    
    def generate_report(self, format: str = 'text') -> str:
        """
        Генерация отчета о состоянии системы
        
        Args:
            format: формат отчета ('text', 'json')
            
        Returns:
            Отчет в указанном формате
        """
        # Получаем данные для отчета
        status = self.get_last_update_status()
        log_analysis = self.analyze_logs(days=7)
        
        if format == 'json':
            report_data = {
                'timestamp': datetime.now().isoformat(),
                'status': status,
                'log_analysis': log_analysis
            }
            return json.dumps(report_data, indent=2, default=str, ensure_ascii=False)
        
        # Текстовый отчет
        report = []
        report.append("=" * 60)
        report.append("ОТЧЕТ О СОСТОЯНИИ СИСТЕМЫ OZON ANALYTICS")
        report.append("=" * 60)
        report.append(f"Время генерации: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        report.append("")
        
        # Общий статус
        status_emoji = {
            'healthy': '✅',
            'warning': '⚠️',
            'critical': '❌',
            'error': '💥',
            'unknown': '❓'
        }
        
        overall_status = status['overall_status']
        report.append(f"ОБЩИЙ СТАТУС: {status_emoji.get(overall_status, '❓')} {overall_status.upper()}")
        report.append("")
        
        # Статус данных
        report.append("СТАТУС ДАННЫХ:")
        for data_type, data_info in status.items():
            if data_type == 'overall_status':
                continue
                
            last_update = data_info['last_update']
            records_count = data_info['records_count']
            
            if last_update:
                time_ago = datetime.now() - last_update
                hours_ago = int(time_ago.total_seconds() / 3600)
                report.append(f"  {data_type}: {records_count} записей, обновлено {hours_ago}ч назад")
            else:
                report.append(f"  {data_type}: Нет данных")
        
        report.append("")
        
        # Анализ логов
        report.append("АНАЛИЗ ЛОГОВ (за 7 дней):")
        report.append(f"  Всего запусков: {log_analysis['total_runs']}")
        report.append(f"  Успешных: {log_analysis['successful_runs']}")
        report.append(f"  Неудачных: {log_analysis['failed_runs']}")
        report.append(f"  Предупреждений: {log_analysis['warnings_count']}")
        report.append(f"  Ошибок: {log_analysis['errors_count']}")
        
        if log_analysis['average_duration']:
            avg_minutes = int(log_analysis['average_duration'] / 60)
            report.append(f"  Средняя продолжительность: {avg_minutes} минут")
        
        if log_analysis['last_run']:
            report.append(f"  Последний запуск: {log_analysis['last_run'].strftime('%Y-%m-%d %H:%M:%S')}")
        
        # Проблемы производительности
        if log_analysis['performance_issues']:
            report.append("")
            report.append("ПРОБЛЕМЫ ПРОИЗВОДИТЕЛЬНОСТИ:")
            for issue in log_analysis['performance_issues']:
                duration_minutes = int(issue['duration'] / 60)
                report.append(f"  {issue['start_time']}: {duration_minutes} минут")
        
        report.append("")
        report.append("=" * 60)
        
        return "\n".join(report)
    
    def check_alerts(self) -> List[Dict]:
        """
        Проверка условий для алертов
        
        Returns:
            List алертов
        """
        alerts = []
        
        # Проверка статуса обновления
        status = self.get_last_update_status()
        
        if status['overall_status'] == 'critical':
            alerts.append({
                'level': 'critical',
                'message': 'Данные Ozon не обновлялись более 7 дней',
                'timestamp': datetime.now()
            })
        elif status['overall_status'] == 'warning':
            alerts.append({
                'level': 'warning',
                'message': 'Частичное обновление данных Ozon',
                'timestamp': datetime.now()
            })
        
        # Проверка логов на ошибки
        log_analysis = self.analyze_logs(days=1)  # За последний день
        
        if log_analysis['failed_runs'] > 0:
            alerts.append({
                'level': 'error',
                'message': f'Обнаружено {log_analysis["failed_runs"]} неудачных запусков за последний день',
                'timestamp': datetime.now()
            })
        
        if log_analysis['errors_count'] > 10:
            alerts.append({
                'level': 'warning',
                'message': f'Высокое количество ошибок в логах: {log_analysis["errors_count"]}',
                'timestamp': datetime.now()
            })
        
        # Проверка производительности
        if log_analysis['performance_issues']:
            alerts.append({
                'level': 'warning',
                'message': f'Обнаружено {len(log_analysis["performance_issues"])} проблем производительности',
                'timestamp': datetime.now()
            })
        
        return alerts


def main():
    """Главная функция"""
    parser = argparse.ArgumentParser(description='Мониторинг системы обновления Ozon Analytics')
    parser.add_argument('--status', action='store_true', help='Показать статус системы')
    parser.add_argument('--report', choices=['text', 'json'], help='Сгенерировать отчет')
    parser.add_argument('--alerts', action='store_true', help='Проверить алерты')
    parser.add_argument('--logs', type=int, default=7, help='Анализировать логи за N дней')
    
    args = parser.parse_args()
    
    monitor = OzonUpdateMonitor()
    
    if args.status:
        status = monitor.get_last_update_status()
        print(f"Общий статус: {status['overall_status']}")
        for data_type, info in status.items():
            if data_type != 'overall_status':
                print(f"{data_type}: {info['records_count']} записей, последнее обновление: {info['last_update']}")
    
    elif args.report:
        report = monitor.generate_report(format=args.report)
        print(report)
    
    elif args.alerts:
        alerts = monitor.check_alerts()
        if alerts:
            print("АКТИВНЫЕ АЛЕРТЫ:")
            for alert in alerts:
                print(f"[{alert['level'].upper()}] {alert['message']}")
        else:
            print("Алертов нет")
    
    else:
        # По умолчанию показываем краткий статус
        status = monitor.get_last_update_status()
        print(f"Статус системы Ozon Analytics: {status['overall_status']}")


if __name__ == "__main__":
    main()