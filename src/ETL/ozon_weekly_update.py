#!/usr/bin/env python3
"""
Ozon Analytics Weekly Update System
Система еженедельного обновления аналитических данных Ozon

Выполняет инкрементальное обновление данных воронки продаж, 
демографических данных и рекламных кампаний из API Ozon.

Автор: Manhattan System
Версия: 1.0
"""

import os
import sys
import json
import logging
import smtplib
import traceback
from datetime import datetime, timedelta, date
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from typing import Dict, List, Optional, Tuple
import mysql.connector
from mysql.connector import Error
import requests
import time

class OzonWeeklyUpdater:
    """Класс для еженедельного обновления данных Ozon"""
    
    def __init__(self, config_file: str = 'config.py'):
        """
        Инициализация обновлятора
        
        Args:
            config_file: путь к файлу конфигурации
        """
        self.config = self._load_config(config_file)
        self.logger = self._setup_logging()
        self.db_connection = None
        self.update_stats = {
            'start_time': None,
            'end_time': None,
            'funnel_records_updated': 0,
            'demographics_records_updated': 0,
            'campaigns_records_updated': 0,
            'errors': [],
            'warnings': []
        }
        
    def _load_config(self, config_file: str) -> Dict:
        """Загрузка конфигурации из файла"""
        try:
            # Импортируем конфигурацию из Python файла
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
                'ozon_api': {
                    'base_url': 'https://api-seller.ozon.ru',
                    'client_id': getattr(config_module, 'OZON_CLIENT_ID', ''),
                    'api_key': getattr(config_module, 'OZON_API_KEY', ''),
                    'rate_limit_delay': 1.0  # секунда между запросами
                },
                'email': {
                    'smtp_server': getattr(config_module, 'SMTP_SERVER', 'localhost'),
                    'smtp_port': getattr(config_module, 'SMTP_PORT', 587),
                    'smtp_user': getattr(config_module, 'SMTP_USER', ''),
                    'smtp_password': getattr(config_module, 'SMTP_PASSWORD', ''),
                    'from_email': getattr(config_module, 'FROM_EMAIL', 'noreply@zavodprostavok.ru'),
                    'to_emails': getattr(config_module, 'ADMIN_EMAILS', ['admin@zavodprostavok.ru'])
                },
                'update': {
                    'lookback_days': 14,  # Сколько дней назад обновлять
                    'batch_size': 100,    # Размер пакета для обработки
                    'max_retries': 3,     # Максимальное количество повторов
                    'retry_delay': 5      # Задержка между повторами (секунды)
                }
            }
        except Exception as e:
            # Базовая конфигурация если файл не найден
            print(f"Предупреждение: Не удалось загрузить конфигурацию из {config_file}: {e}")
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
            'ozon_api': {
                'base_url': 'https://api-seller.ozon.ru',
                'client_id': os.getenv('OZON_CLIENT_ID', ''),
                'api_key': os.getenv('OZON_API_KEY', ''),
                'rate_limit_delay': 1.0
            },
            'email': {
                'smtp_server': 'localhost',
                'smtp_port': 587,
                'smtp_user': '',
                'smtp_password': '',
                'from_email': 'noreply@zavodprostavok.ru',
                'to_emails': ['admin@zavodprostavok.ru']
            },
            'update': {
                'lookback_days': 14,
                'batch_size': 100,
                'max_retries': 3,
                'retry_delay': 5
            }
        }
    
    def _setup_logging(self) -> logging.Logger:
        """Настройка системы логирования"""
        # Создаем директорию для логов если не существует
        log_dir = 'logs'
        if not os.path.exists(log_dir):
            os.makedirs(log_dir)
        
        # Настраиваем логгер
        logger = logging.getLogger('ozon_weekly_updater')
        logger.setLevel(logging.INFO)
        
        # Очищаем существующие обработчики
        logger.handlers.clear()
        
        # Файловый обработчик
        log_filename = os.path.join(log_dir, f'ozon_update_{datetime.now().strftime("%Y%m%d_%H%M%S")}.log')
        file_handler = logging.FileHandler(log_filename, encoding='utf-8')
        file_handler.setLevel(logging.INFO)
        
        # Консольный обработчик
        console_handler = logging.StreamHandler()
        console_handler.setLevel(logging.INFO)
        
        # Форматтер
        formatter = logging.Formatter(
            '%(asctime)s - %(name)s - %(levelname)s - %(message)s',
            datefmt='%Y-%m-%d %H:%M:%S'
        )
        file_handler.setFormatter(formatter)
        console_handler.setFormatter(formatter)
        
        logger.addHandler(file_handler)
        logger.addHandler(console_handler)
        
        return logger
    
    def connect_database(self) -> bool:
        """
        Подключение к базе данных
        
        Returns:
            bool: True если подключение успешно
        """
        try:
            self.db_connection = mysql.connector.connect(**self.config['database'])
            if self.db_connection.is_connected():
                self.logger.info("Успешное подключение к базе данных")
                return True
        except Error as e:
            self.logger.error(f"Ошибка подключения к базе данных: {e}")
            self.update_stats['errors'].append(f"Database connection error: {e}")
        return False
    
    def disconnect_database(self):
        """Отключение от базы данных"""
        if self.db_connection and self.db_connection.is_connected():
            self.db_connection.close()
            self.logger.info("Отключение от базы данных")
    
    def get_ozon_api_credentials(self) -> Tuple[Optional[str], Optional[str]]:
        """
        Получение учетных данных Ozon API из базы данных
        
        Returns:
            Tuple[client_id, api_key] или (None, None) при ошибке
        """
        try:
            cursor = self.db_connection.cursor()
            cursor.execute("""
                SELECT client_id, api_key_hash 
                FROM ozon_api_settings 
                WHERE is_active = TRUE 
                ORDER BY updated_at DESC 
                LIMIT 1
            """)
            
            result = cursor.fetchone()
            cursor.close()
            
            if result:
                return result[0], result[1]
            else:
                # Используем данные из конфигурации как fallback
                return (
                    self.config['ozon_api']['client_id'],
                    self.config['ozon_api']['api_key']
                )
                
        except Error as e:
            self.logger.error(f"Ошибка получения учетных данных API: {e}")
            self.update_stats['errors'].append(f"API credentials error: {e}")
            return None, None
    
    def get_last_update_dates(self) -> Dict[str, Optional[date]]:
        """
        Получение дат последнего обновления для каждого типа данных
        
        Returns:
            Dict с датами последнего обновления
        """
        last_dates = {
            'funnel': None,
            'demographics': None,
            'campaigns': None
        }
        
        try:
            cursor = self.db_connection.cursor()
            
            # Последнее обновление данных воронки
            cursor.execute("SELECT MAX(date_to) FROM ozon_funnel_data")
            result = cursor.fetchone()
            if result[0]:
                last_dates['funnel'] = result[0]
            
            # Последнее обновление демографических данных
            cursor.execute("SELECT MAX(date_to) FROM ozon_demographics")
            result = cursor.fetchone()
            if result[0]:
                last_dates['demographics'] = result[0]
            
            # Последнее обновление данных кампаний
            cursor.execute("SELECT MAX(date_to) FROM ozon_campaigns")
            result = cursor.fetchone()
            if result[0]:
                last_dates['campaigns'] = result[0]
            
            cursor.close()
            
        except Error as e:
            self.logger.error(f"Ошибка получения дат последнего обновления: {e}")
            self.update_stats['errors'].append(f"Last update dates error: {e}")
        
        return last_dates
    
    def calculate_update_periods(self, last_dates: Dict[str, Optional[date]]) -> List[Tuple[date, date]]:
        """
        Вычисление периодов для обновления данных
        
        Args:
            last_dates: словарь с датами последнего обновления
            
        Returns:
            List периодов (date_from, date_to) для обновления
        """
        today = date.today()
        lookback_days = self.config['update']['lookback_days']
        
        # Определяем самую раннюю дату для обновления
        earliest_last_date = None
        for data_type, last_date in last_dates.items():
            if last_date:
                if earliest_last_date is None or last_date < earliest_last_date:
                    earliest_last_date = last_date
        
        # Если данных нет, начинаем с lookback_days назад
        if earliest_last_date is None:
            start_date = today - timedelta(days=lookback_days)
        else:
            # Начинаем с даты последнего обновления
            start_date = earliest_last_date
        
        # Создаем периоды по неделям
        periods = []
        current_date = start_date
        
        while current_date < today:
            period_end = min(current_date + timedelta(days=6), today - timedelta(days=1))
            if current_date <= period_end:
                periods.append((current_date, period_end))
            current_date = period_end + timedelta(days=1)
        
        return periods
    
    def authenticate_ozon_api(self, client_id: str, api_key: str) -> Optional[str]:
        """
        Аутентификация в Ozon API
        
        Args:
            client_id: Client ID
            api_key: API Key
            
        Returns:
            access_token или None при ошибке
        """
        url = f"{self.config['ozon_api']['base_url']}/v1/auth/token"
        
        headers = {
            'Content-Type': 'application/json',
            'Client-Id': client_id,
            'Api-Key': api_key
        }
        
        data = {
            'client_id': client_id,
            'api_key': api_key
        }
        
        try:
            response = requests.post(url, headers=headers, json=data, timeout=30)
            
            if response.status_code == 200:
                result = response.json()
                access_token = result.get('access_token')
                if access_token:
                    self.logger.info("Успешная аутентификация в Ozon API")
                    return access_token
                else:
                    self.logger.error("Токен доступа не получен")
            else:
                self.logger.error(f"Ошибка аутентификации: HTTP {response.status_code}")
                
        except requests.RequestException as e:
            self.logger.error(f"Ошибка запроса аутентификации: {e}")
            self.update_stats['errors'].append(f"Authentication error: {e}")
        
        return None
    
    def update_funnel_data(self, access_token: str, client_id: str, api_key: str, 
                          periods: List[Tuple[date, date]]) -> int:
        """
        Обновление данных воронки продаж
        
        Args:
            access_token: токен доступа
            client_id: Client ID
            api_key: API Key
            periods: периоды для обновления
            
        Returns:
            количество обновленных записей
        """
        updated_records = 0
        url = f"{self.config['ozon_api']['base_url']}/v1/analytics/funnel"
        
        headers = {
            'Content-Type': 'application/json',
            'Client-Id': client_id,
            'Api-Key': api_key,
            'Authorization': f'Bearer {access_token}'
        }
        
        for date_from, date_to in periods:
            try:
                self.logger.info(f"Обновление данных воронки за период {date_from} - {date_to}")
                
                data = {
                    'date_from': date_from.strftime('%Y-%m-%d'),
                    'date_to': date_to.strftime('%Y-%m-%d'),
                    'filters': {}
                }
                
                # Применяем rate limiting
                time.sleep(self.config['ozon_api']['rate_limit_delay'])
                
                response = requests.post(url, headers=headers, json=data, timeout=30)
                
                if response.status_code == 200:
                    api_data = response.json()
                    records_saved = self._save_funnel_data(api_data, date_from, date_to)
                    updated_records += records_saved
                    self.logger.info(f"Сохранено {records_saved} записей воронки")
                    
                elif response.status_code == 401:
                    self.logger.error("Токен доступа истек, требуется повторная аутентификация")
                    break
                    
                else:
                    self.logger.error(f"Ошибка API воронки: HTTP {response.status_code}")
                    self.update_stats['warnings'].append(
                        f"Funnel API error for {date_from}-{date_to}: HTTP {response.status_code}"
                    )
                    
            except requests.RequestException as e:
                self.logger.error(f"Ошибка запроса данных воронки: {e}")
                self.update_stats['errors'].append(f"Funnel request error: {e}")
            except Exception as e:
                self.logger.error(f"Неожиданная ошибка при обновлении воронки: {e}")
                self.update_stats['errors'].append(f"Funnel update error: {e}")
        
        return updated_records
    
    def _save_funnel_data(self, api_data: Dict, date_from: date, date_to: date) -> int:
        """Сохранение данных воронки в базу данных"""
        if not api_data.get('data'):
            return 0
        
        saved_records = 0
        
        try:
            cursor = self.db_connection.cursor()
            
            # Удаляем существующие данные за этот период
            delete_sql = """
                DELETE FROM ozon_funnel_data 
                WHERE date_from = %s AND date_to = %s
            """
            cursor.execute(delete_sql, (date_from, date_to))
            
            # Вставляем новые данные
            insert_sql = """
                INSERT INTO ozon_funnel_data 
                (date_from, date_to, product_id, campaign_id, views, cart_additions, orders,
                 conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            for item in api_data['data']:
                views = max(0, int(item.get('views', 0)))
                cart_additions = max(0, int(item.get('cart_additions', 0)))
                orders = max(0, int(item.get('orders', 0)))
                
                # Рассчитываем конверсии
                conv_view_to_cart = round((cart_additions / views) * 100, 2) if views > 0 else 0.0
                conv_cart_to_order = round((orders / cart_additions) * 100, 2) if cart_additions > 0 else 0.0
                conv_overall = round((orders / views) * 100, 2) if views > 0 else 0.0
                
                cursor.execute(insert_sql, (
                    date_from, date_to,
                    item.get('product_id'),
                    item.get('campaign_id'),
                    views, cart_additions, orders,
                    conv_view_to_cart, conv_cart_to_order, conv_overall,
                    datetime.now()
                ))
                saved_records += 1
            
            self.db_connection.commit()
            cursor.close()
            
        except Error as e:
            self.logger.error(f"Ошибка сохранения данных воронки: {e}")
            self.update_stats['errors'].append(f"Funnel save error: {e}")
            if self.db_connection:
                self.db_connection.rollback()
        
        return saved_records
    
    def update_demographics_data(self, access_token: str, client_id: str, api_key: str,
                               periods: List[Tuple[date, date]]) -> int:
        """Обновление демографических данных"""
        updated_records = 0
        url = f"{self.config['ozon_api']['base_url']}/v1/analytics/demographics"
        
        headers = {
            'Content-Type': 'application/json',
            'Client-Id': client_id,
            'Api-Key': api_key,
            'Authorization': f'Bearer {access_token}'
        }
        
        for date_from, date_to in periods:
            try:
                self.logger.info(f"Обновление демографических данных за период {date_from} - {date_to}")
                
                data = {
                    'date_from': date_from.strftime('%Y-%m-%d'),
                    'date_to': date_to.strftime('%Y-%m-%d'),
                    'filters': {}
                }
                
                time.sleep(self.config['ozon_api']['rate_limit_delay'])
                
                response = requests.post(url, headers=headers, json=data, timeout=30)
                
                if response.status_code == 200:
                    api_data = response.json()
                    records_saved = self._save_demographics_data(api_data, date_from, date_to)
                    updated_records += records_saved
                    self.logger.info(f"Сохранено {records_saved} демографических записей")
                    
                elif response.status_code == 401:
                    self.logger.error("Токен доступа истек")
                    break
                    
                else:
                    self.logger.error(f"Ошибка API демографии: HTTP {response.status_code}")
                    self.update_stats['warnings'].append(
                        f"Demographics API error for {date_from}-{date_to}: HTTP {response.status_code}"
                    )
                    
            except Exception as e:
                self.logger.error(f"Ошибка обновления демографических данных: {e}")
                self.update_stats['errors'].append(f"Demographics update error: {e}")
        
        return updated_records
    
    def _save_demographics_data(self, api_data: Dict, date_from: date, date_to: date) -> int:
        """Сохранение демографических данных в базу данных"""
        if not api_data.get('data'):
            return 0
        
        saved_records = 0
        
        try:
            cursor = self.db_connection.cursor()
            
            # Удаляем существующие данные за этот период
            delete_sql = """
                DELETE FROM ozon_demographics 
                WHERE date_from = %s AND date_to = %s
            """
            cursor.execute(delete_sql, (date_from, date_to))
            
            # Вставляем новые данные
            insert_sql = """
                INSERT INTO ozon_demographics 
                (date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            for item in api_data['data']:
                cursor.execute(insert_sql, (
                    date_from, date_to,
                    item.get('age_group'),
                    item.get('gender'),
                    item.get('region'),
                    max(0, int(item.get('orders_count', 0))),
                    max(0, float(item.get('revenue', 0))),
                    datetime.now()
                ))
                saved_records += 1
            
            self.db_connection.commit()
            cursor.close()
            
        except Error as e:
            self.logger.error(f"Ошибка сохранения демографических данных: {e}")
            self.update_stats['errors'].append(f"Demographics save error: {e}")
            if self.db_connection:
                self.db_connection.rollback()
        
        return saved_records
    
    def run_update(self) -> bool:
        """
        Запуск процесса обновления
        
        Returns:
            bool: True если обновление прошло успешно
        """
        self.update_stats['start_time'] = datetime.now()
        self.logger.info("=== Начало еженедельного обновления данных Ozon ===")
        
        try:
            # Подключение к базе данных
            if not self.connect_database():
                return False
            
            # Получение учетных данных API
            client_id, api_key = self.get_ozon_api_credentials()
            if not client_id or not api_key:
                self.logger.error("Не удалось получить учетные данные Ozon API")
                return False
            
            # Аутентификация
            access_token = self.authenticate_ozon_api(client_id, api_key)
            if not access_token:
                self.logger.error("Не удалось аутентифицироваться в Ozon API")
                return False
            
            # Определение периодов для обновления
            last_dates = self.get_last_update_dates()
            periods = self.calculate_update_periods(last_dates)
            
            self.logger.info(f"Найдено {len(periods)} периодов для обновления")
            for period in periods:
                self.logger.info(f"  - {period[0]} до {period[1]}")
            
            # Обновление данных воронки
            self.logger.info("Начало обновления данных воронки продаж")
            funnel_records = self.update_funnel_data(access_token, client_id, api_key, periods)
            self.update_stats['funnel_records_updated'] = funnel_records
            
            # Обновление демографических данных
            self.logger.info("Начало обновления демографических данных")
            demographics_records = self.update_demographics_data(access_token, client_id, api_key, periods)
            self.update_stats['demographics_records_updated'] = demographics_records
            
            # Обновление данных кампаний (если необходимо)
            # campaigns_records = self.update_campaigns_data(access_token, client_id, api_key, periods)
            # self.update_stats['campaigns_records_updated'] = campaigns_records
            
            self.update_stats['end_time'] = datetime.now()
            
            # Отправка уведомления об успешном обновлении
            self.send_success_notification()
            
            self.logger.info("=== Еженедельное обновление данных Ozon завершено успешно ===")
            return True
            
        except Exception as e:
            self.logger.error(f"Критическая ошибка при обновлении: {e}")
            self.logger.error(traceback.format_exc())
            self.update_stats['errors'].append(f"Critical error: {e}")
            self.update_stats['end_time'] = datetime.now()
            
            # Отправка уведомления об ошибке
            self.send_error_notification()
            return False
            
        finally:
            self.disconnect_database()
    
    def send_success_notification(self):
        """Отправка уведомления об успешном обновлении"""
        try:
            duration = self.update_stats['end_time'] - self.update_stats['start_time']
            
            subject = "✅ Ozon Analytics - Еженедельное обновление выполнено успешно"
            
            body = f"""
Еженедельное обновление аналитических данных Ozon завершено успешно.

📊 СТАТИСТИКА ОБНОВЛЕНИЯ:
• Время начала: {self.update_stats['start_time'].strftime('%Y-%m-%d %H:%M:%S')}
• Время окончания: {self.update_stats['end_time'].strftime('%Y-%m-%d %H:%M:%S')}
• Продолжительность: {duration}

📈 ОБНОВЛЕННЫЕ ДАННЫЕ:
• Данные воронки продаж: {self.update_stats['funnel_records_updated']} записей
• Демографические данные: {self.update_stats['demographics_records_updated']} записей
• Данные кампаний: {self.update_stats['campaigns_records_updated']} записей

⚠️ ПРЕДУПРЕЖДЕНИЯ: {len(self.update_stats['warnings'])}
{chr(10).join(self.update_stats['warnings']) if self.update_stats['warnings'] else 'Нет предупреждений'}

Система: Manhattan Analytics
Сервер: {os.uname().nodename if hasattr(os, 'uname') else 'Unknown'}
            """
            
            self._send_email(subject, body)
            
        except Exception as e:
            self.logger.error(f"Ошибка отправки уведомления об успехе: {e}")
    
    def send_error_notification(self):
        """Отправка уведомления об ошибке"""
        try:
            duration = None
            if self.update_stats['end_time'] and self.update_stats['start_time']:
                duration = self.update_stats['end_time'] - self.update_stats['start_time']
            
            subject = "❌ Ozon Analytics - Ошибка еженедельного обновления"
            
            body = f"""
ВНИМАНИЕ! Произошла ошибка при еженедельном обновлении аналитических данных Ozon.

⏰ ВРЕМЯ:
• Время начала: {self.update_stats['start_time'].strftime('%Y-%m-%d %H:%M:%S') if self.update_stats['start_time'] else 'Не определено'}
• Время ошибки: {self.update_stats['end_time'].strftime('%Y-%m-%d %H:%M:%S') if self.update_stats['end_time'] else 'Не определено'}
• Продолжительность: {duration if duration else 'Не определено'}

❌ ОШИБКИ ({len(self.update_stats['errors'])}):
{chr(10).join(self.update_stats['errors']) if self.update_stats['errors'] else 'Ошибки не зафиксированы'}

⚠️ ПРЕДУПРЕЖДЕНИЯ ({len(self.update_stats['warnings'])}):
{chr(10).join(self.update_stats['warnings']) if self.update_stats['warnings'] else 'Нет предупреждений'}

📊 ЧАСТИЧНО ОБНОВЛЕННЫЕ ДАННЫЕ:
• Данные воронки продаж: {self.update_stats['funnel_records_updated']} записей
• Демографические данные: {self.update_stats['demographics_records_updated']} записей
• Данные кампаний: {self.update_stats['campaigns_records_updated']} записей

Требуется вмешательство администратора для устранения проблемы.

Система: Manhattan Analytics
Сервер: {os.uname().nodename if hasattr(os, 'uname') else 'Unknown'}
            """
            
            self._send_email(subject, body)
            
        except Exception as e:
            self.logger.error(f"Ошибка отправки уведомления об ошибке: {e}")
    
    def _send_email(self, subject: str, body: str):
        """Отправка email уведомления"""
        if not self.config['email']['smtp_user'] or not self.config['email']['to_emails']:
            self.logger.warning("Email настройки не заданы, уведомление не отправлено")
            return
        
        try:
            msg = MIMEMultipart()
            msg['From'] = self.config['email']['from_email']
            msg['To'] = ', '.join(self.config['email']['to_emails'])
            msg['Subject'] = subject
            
            msg.attach(MIMEText(body, 'plain', 'utf-8'))
            
            server = smtplib.SMTP(
                self.config['email']['smtp_server'], 
                self.config['email']['smtp_port']
            )
            
            if self.config['email']['smtp_user']:
                server.starttls()
                server.login(
                    self.config['email']['smtp_user'], 
                    self.config['email']['smtp_password']
                )
            
            server.send_message(msg)
            server.quit()
            
            self.logger.info("Email уведомление отправлено успешно")
            
        except Exception as e:
            self.logger.error(f"Ошибка отправки email: {e}")


def main():
    """Главная функция"""
    updater = OzonWeeklyUpdater()
    
    try:
        success = updater.run_update()
        sys.exit(0 if success else 1)
    except KeyboardInterrupt:
        print("\nОбновление прервано пользователем")
        sys.exit(1)
    except Exception as e:
        print(f"Критическая ошибка: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()