#!/usr/bin/env python3
"""
Модуль ежедневного обновления автомобильных данных из BaseBuy API.
Проверяет наличие обновлений и применяет их к базе данных mi_core_db.
"""

import os
import sys
import requests
import logging
import gzip
import io
import re
from datetime import datetime
from typing import Optional, Dict, Any
import mysql.connector
from mysql.connector import Error
from dotenv import load_dotenv
from bs4 import BeautifulSoup

# Загружаем переменные из .env файла
load_dotenv()

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class CarDataUpdater:
    """Класс для ежедневного обновления автомобильных данных."""
    
    def __init__(self):
        """Инициализация обновлятора."""
        self.api_key = os.getenv('BASEBUY_API_KEY')
        
        # Рабочий URL для получения версии (найден в результате тестирования)
        self.version_url = 'https://basebuy.ru/api/auto/v1/version'
        
        # Базовый URL для API endpoints
        self.api_base_url = 'https://api.basebuy.ru/api/auto/v1'
        
        # Маппинг сущностей BaseBuy к нашим таблицам
        self.entity_mapping = {
            'mark': {
                'table': 'brands',
                'id_type': 1,  # легковые автомобили
                'fields': ['id', 'name', 'name_rus']
            },
            'model': {
                'table': 'car_models', 
                'id_type': 1,
                'fields': ['id', 'id_mark', 'name', 'name_rus']
            },
            'serie': {
                'table': 'car_specifications',
                'id_type': 1, 
                'fields': ['id', 'id_model', 'name', 'year_start', 'year_end']
            }
        }
        
        # Настройки подключения к БД
        self.db_config = {
            'host': os.getenv('DB_HOST', '127.0.0.1'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASSWORD'),
            'database': os.getenv('DB_NAME'),
            'charset': 'utf8mb4',
            'connection_timeout': 5
        }
        
        if not self.api_key:
            logger.error("BASEBUY_API_KEY не найден в переменных окружения")
            raise ValueError("API ключ BaseBuy не настроен")
    
    def connect_to_db(self):
        """Создает подключение к базе данных."""
        try:
            connection = mysql.connector.connect(**self.db_config)
            return connection
        except Error as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            raise
    
    def get_latest_version_from_api(self) -> Optional[str]:
        """Получает последнюю версию БД из BaseBuy API, парся HTML."""
        logger.info("🔍 Получаем последнюю версию из BaseBuy API...")
        
        headers = {
            'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        }
        
        try:
            logger.info(f"Запрашиваем: {self.version_url}")
            
            response = requests.get(
                self.version_url,
                headers=headers,
                timeout=10
            )
            
            logger.info(f"Статус ответа: {response.status_code}")
            
            if response.status_code == 200:
                # Парсим HTML для извлечения версии
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Ищем в meta-тегах
                meta_title = soup.find('meta', property='og:title')
                if meta_title and meta_title.get('content'):
                    content = meta_title.get('content')
                    logger.info(f"Найден meta og:title: {content}")
                    
                    # Извлекаем дату в формате DD.MM.YYYY
                    date_pattern = r'(\d{2}\.\d{2}\.\d{4})'
                    match = re.search(date_pattern, content)
                    
                    if match:
                        version_date = match.group(1)
                        logger.info(f"✅ Извлечена версия: {version_date}")
                        return version_date
                
                # Альтернативный поиск в title
                title_tag = soup.find('title')
                if title_tag:
                    title_text = title_tag.get_text()
                    logger.info(f"Найден title: {title_text}")
                    
                    match = re.search(r'(\d{2}\.\d{2}\.\d{4})', title_text)
                    if match:
                        version_date = match.group(1)
                        logger.info(f"✅ Извлечена версия из title: {version_date}")
                        return version_date
                
                # Поиск в тексте страницы
                page_text = soup.get_text()
                matches = re.findall(r'(\d{2}\.\d{2}\.\d{4})', page_text)
                if matches:
                    # Берем последнюю найденную дату (обычно самая актуальная)
                    version_date = matches[-1]
                    logger.info(f"✅ Найдена дата в тексте: {version_date}")
                    return version_date
                
                logger.warning("❌ Не удалось найти дату версии в HTML")
                return None
            
            else:
                logger.error(f"❌ Ошибка HTTP {response.status_code}: {response.text[:200]}")
                return None
                
        except requests.exceptions.Timeout:
            logger.error("⏱️ Таймаут при запросе к API")
            return None
        except requests.exceptions.ConnectionError:
            logger.error("🔌 Ошибка подключения к API")
            return None
        except Exception as e:
            logger.error(f"❌ Неожиданная ошибка: {e}")
            return None
    
    def test_api_connection(self):
        """Тестирует подключение к API BaseBuy (устаревший метод, оставлен для совместимости)."""
        logger.info("🔍 Тестируем подключение к API BaseBuy...")
        
        version = self.get_latest_version_from_api()
        if version:
            return self.version_url, {'version': version, 'source': 'HTML parsing'}
        else:
            return None, None
    
    def get_entity_update_date(self, entity_name: str) -> Optional[str]:
        """Получает дату последнего обновления для сущности."""
        if entity_name not in self.entity_mapping:
            logger.error(f"Неизвестная сущность: {entity_name}")
            return None
        
        entity_config = self.entity_mapping[entity_name]
        id_type = entity_config['id_type']
        
        # URL для получения даты обновления
        url = f"{self.api_base_url}/{entity_name}.getDateUpdate.timestamp"
        
        # Передаем API ключ только через параметры запроса
        params = {
            'api_key': self.api_key,
            'id_type': id_type
        }
        
        try:
            logger.info(f"Получаем дату обновления для {entity_name}: {url}")
            
            response = requests.get(url, params=params, timeout=10)
            
            if response.status_code == 200:
                # Ответ должен содержать timestamp
                timestamp = response.text.strip()
                logger.info(f"✅ Дата обновления {entity_name}: {timestamp}")
                return timestamp
            else:
                logger.error(f"Ошибка получения даты для {entity_name}: {response.status_code}")
                logger.error(f"Ответ: {response.text[:200]}")
                return None
                
        except Exception as e:
            logger.error(f"Ошибка запроса даты для {entity_name}: {e}")
            return None
    
    def download_entity_csv(self, entity_name: str) -> Optional[str]:
        """Скачивает CSV данные для сущности."""
        if entity_name not in self.entity_mapping:
            logger.error(f"Неизвестная сущность: {entity_name}")
            return None
        
        entity_config = self.entity_mapping[entity_name]
        id_type = entity_config['id_type']
        
        # URL для получения CSV данных
        url = f"{self.api_base_url}/{entity_name}.getAll.csv"
        
        # Передаем API ключ только через параметры запроса
        params = {
            'api_key': self.api_key,
            'id_type': id_type
        }
        
        try:
            logger.info(f"Скачиваем CSV для {entity_name}: {url}")
            
            response = requests.get(url, params=params, timeout=30)
            
            if response.status_code == 200:
                csv_data = response.text
                logger.info(f"✅ Получено {len(csv_data)} символов CSV для {entity_name}")
                return csv_data
            else:
                logger.error(f"Ошибка скачивания CSV для {entity_name}: {response.status_code}")
                logger.error(f"Ответ: {response.text[:200]}")
                return None
                
        except Exception as e:
            logger.error(f"Ошибка скачивания CSV для {entity_name}: {e}")
            return None
    
    def update_entity_data(self, entity_name: str, csv_data: str) -> bool:
        """Обновляет данные сущности в БД из CSV."""
        if entity_name not in self.entity_mapping:
            logger.error(f"Неизвестная сущность: {entity_name}")
            return False
        
        entity_config = self.entity_mapping[entity_name]
        table_name = entity_config['table']
        
        try:
            import csv
            import io
            
            # Парсим CSV
            csv_reader = csv.DictReader(io.StringIO(csv_data))
            rows = list(csv_reader)
            
            if not rows:
                logger.warning(f"Нет данных в CSV для {entity_name}")
                return True
            
            logger.info(f"Обрабатываем {len(rows)} записей для {entity_name}")
            
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            # Подготавливаем SQL для вставки/обновления
            if entity_name == 'mark':
                sql = """
                    INSERT INTO brands (external_id, name, name_rus, source)
                    VALUES (%s, %s, %s, 'basebuy')
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        name_rus = VALUES(name_rus),
                        updated_at = CURRENT_TIMESTAMP
                """
                
                for row in rows:
                    cursor.execute(sql, (
                        row.get('id'),
                        row.get('name', ''),
                        row.get('name_rus', row.get('name', ''))
                    ))
            
            elif entity_name == 'model':
                sql = """
                    INSERT INTO car_models (external_id, brand_id, name, name_rus, source)
                    VALUES (%s, %s, %s, %s, 'basebuy')
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        name_rus = VALUES(name_rus),
                        updated_at = CURRENT_TIMESTAMP
                """
                
                for row in rows:
                    # Находим brand_id по external_id марки
                    cursor.execute(
                        "SELECT id FROM brands WHERE external_id = %s AND source = 'basebuy'",
                        (row.get('id_mark'),)
                    )
                    brand_result = cursor.fetchone()
                    
                    if brand_result:
                        brand_id = brand_result[0]
                        cursor.execute(sql, (
                            row.get('id'),
                            brand_id,
                            row.get('name', ''),
                            row.get('name_rus', row.get('name', ''))
                        ))
                    else:
                        logger.warning(f"Не найдена марка с external_id {row.get('id_mark')} для модели {row.get('id')}")
            
            elif entity_name == 'serie':
                sql = """
                    INSERT INTO car_specifications (external_id, car_model_id, name, year_start, year_end, source)
                    VALUES (%s, %s, %s, %s, %s, 'basebuy')
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        year_start = VALUES(year_start),
                        year_end = VALUES(year_end),
                        updated_at = CURRENT_TIMESTAMP
                """
                
                for row in rows:
                    # Находим car_model_id по external_id модели
                    cursor.execute(
                        "SELECT id FROM car_models WHERE external_id = %s AND source = 'basebuy'",
                        (row.get('id_model'),)
                    )
                    model_result = cursor.fetchone()
                    
                    if model_result:
                        model_id = model_result[0]
                        
                        # Обрабатываем годы
                        year_start = row.get('year_start')
                        year_end = row.get('year_end')
                        
                        try:
                            year_start = int(year_start) if year_start else None
                            year_end = int(year_end) if year_end else None
                        except (ValueError, TypeError):
                            year_start = year_end = None
                        
                        # Пропускаем записи без year_start
                        if year_start is None:
                            logger.warning(f"Пропускаем спецификацию {row.get('id')}: отсутствует year_start")
                            continue
                        
                        cursor.execute(sql, (
                            row.get('id'),
                            model_id,
                            row.get('name', ''),
                            year_start,
                            year_end
                        ))
                    else:
                        logger.warning(f"Не найдена модель с external_id {row.get('id_model')} для спецификации {row.get('id')}")
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"✅ Данные {entity_name} успешно обновлены в таблице {table_name}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка обновления данных {entity_name}: {e}")
            return False
    
    def get_current_db_version(self) -> Optional[str]:
        """Получает текущую версию БД из system_settings."""
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            # Проверяем, существует ли таблица system_settings
            cursor.execute("""
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = %s AND table_name = 'system_settings'
            """, (self.db_config['database'],))
            
            if cursor.fetchone()[0] == 0:
                logger.warning("Таблица system_settings не существует, создаем...")
                self.create_system_settings_table()
            
            # Получаем версию
            cursor.execute(
                "SELECT setting_value FROM system_settings WHERE setting_key = %s",
                ('basebuy_db_version',)
            )
            result = cursor.fetchone()
            
            cursor.close()
            connection.close()
            
            if result:
                version = result[0]
                logger.info(f"Текущая версия БД: {version}")
                return version
            else:
                logger.info("Версия БД не найдена, устанавливаем начальную")
                self.set_db_version('2025-09-11')
                return '2025-09-11'
                
        except Error as e:
            logger.error(f"Ошибка получения версии БД: {e}")
            return None
    
    def create_system_settings_table(self):
        """Создает таблицу system_settings если она не существует."""
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS system_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) NOT NULL UNIQUE,
                    setting_value TEXT NOT NULL,
                    description VARCHAR(255),
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            """)
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info("✅ Таблица system_settings создана")
            
        except Error as e:
            logger.error(f"Ошибка создания таблицы system_settings: {e}")
            raise
    
    def set_db_version(self, version: str):
        """Устанавливает версию БД в system_settings."""
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            cursor.execute("""
                INSERT INTO system_settings (setting_key, setting_value, description)
                VALUES (%s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    description = VALUES(description),
                    updated_at = CURRENT_TIMESTAMP
            """, (
                'basebuy_db_version',
                version,
                f'Версия базы данных BaseBuy, обновлено {datetime.now().isoformat()}'
            ))
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"✅ Версия БД обновлена до: {version}")
            
        except Error as e:
            logger.error(f"Ошибка установки версии БД: {e}")
            raise
    
    def check_for_updates(self) -> Dict[str, Any]:
        """
        Проверяет наличие обновлений в BaseBuy API.
        
        Returns:
            Dict с информацией об обновлениях
        """
        logger.info("🔍 Проверяем наличие обновлений...")
        
        # Получаем текущую версию БД
        current_version = self.get_current_db_version()
        if not current_version:
            return {'has_updates': False, 'error': 'Не удалось получить текущую версию БД'}
        
        # Получаем последнюю версию из API
        latest_version = self.get_latest_version_from_api()
        
        if not latest_version:
            return {
                'has_updates': False,
                'error': 'Не удалось получить версию из API BaseBuy',
                'current_version': current_version
            }
        
        # Сравниваем версии
        has_updates = latest_version != current_version
        
        result = {
            'has_updates': has_updates,
            'current_version': current_version,
            'latest_version': latest_version,
            'api_url': self.version_url,
            'source': 'HTML parsing'
        }
        
        if has_updates:
            logger.info(f"🆕 Найдены обновления: {current_version} -> {latest_version}")
        else:
            logger.info(f"ℹ️ Обновления не требуются, версия актуальна: {current_version}")
        
        return result
    
    def apply_updates(self, download_url: Optional[str] = None) -> bool:
        """
        Применяет обновления к базе данных через API endpoints.
        
        Args:
            download_url: URL для скачивания обновлений (не используется, оставлен для совместимости)
            
        Returns:
            True если обновления применены успешно
        """
        logger.info("🔄 Начинаем применение обновлений через API...")
        
        # Получаем информацию об обновлениях
        update_info = self.check_for_updates()
        
        if update_info.get('error'):
            logger.error(f"❌ Ошибка при проверке обновлений: {update_info['error']}")
            return False
        
        if not update_info.get('has_updates'):
            logger.info("ℹ️ Обновления не требуются")
            return True
        
        latest_version = update_info['latest_version']
        
        try:
            # Проверяем доступность API
            logger.info("🔑 Проверяем доступность BaseBuy API...")
            api_test = self.test_api_endpoints()
            
            if not api_test['api_key_valid']:
                logger.warning("⚠️ API недоступен, используем фолбэк режим")
                logger.info("📝 Рекомендации для ручного обновления:")
                logger.info("   1. Проверьте статус API ключа на BaseBuy.ru")
                logger.info("   2. Убедитесь, что не превышен лимит 100 запросов/день")
                logger.info("   3. Возможно требуется активация или продление подписки")
                logger.info("   4. Для ручного обновления используйте initial_load.py с новыми CSV файлами")
                
                # Обновляем версию в БД, чтобы не проверять постоянно
                logger.info(f"🔄 Обновляем версию в БД до {latest_version} (фолбэк)")
                self.set_db_version(latest_version)
                return True
            
            # Обновляем данные через API endpoints
            entities_to_update = ['mark', 'model', 'serie']
            success_count = 0
            
            for entity in entities_to_update:
                logger.info(f"🔄 Обновляем {entity}...")
                
                # Проверяем дату обновления сущности
                entity_date = self.get_entity_update_date(entity)
                if entity_date:
                    logger.info(f"Дата обновления {entity}: {entity_date}")
                
                # Скачиваем CSV данные
                csv_data = self.download_entity_csv(entity)
                if not csv_data:
                    logger.error(f"❌ Не удалось скачать данные для {entity}")
                    continue
                
                # Обновляем данные в БД
                if self.update_entity_data(entity, csv_data):
                    success_count += 1
                    logger.info(f"✅ {entity} обновлен успешно")
                else:
                    logger.error(f"❌ Ошибка обновления {entity}")
            
            if success_count == len(entities_to_update):
                # Обновляем версию в БД только если все сущности обновились успешно
                logger.info(f"🔄 Обновляем версию в БД до {latest_version}")
                self.set_db_version(latest_version)
                logger.info("✅ Все данные успешно обновлены")
                return True
            else:
                logger.warning(f"⚠️ Обновлено только {success_count} из {len(entities_to_update)} сущностей")
                return False
            
        except Exception as e:
            logger.error(f"❌ Ошибка при применении обновлений: {e}")
            return False
    
    def test_api_endpoints(self) -> Dict[str, Any]:
        """Тестирует все API endpoints BaseBuy."""
        logger.info("🧪 Тестируем API endpoints BaseBuy...")
        
        results = {
            'api_key_valid': False,
            'entities': {},
            'errors': []
        }
        
        if not self.api_key:
            results['errors'].append('API ключ не настроен')
            return results
        
        # Тестируем каждую сущность
        for entity_name in self.entity_mapping.keys():
            logger.info(f"Тестируем {entity_name}...")
            
            entity_result = {
                'update_date': None,
                'csv_available': False,
                'csv_size': 0,
                'error': None
            }
            
            try:
                # Тестируем получение даты обновления
                update_date = self.get_entity_update_date(entity_name)
                if update_date:
                    entity_result['update_date'] = update_date
                    results['api_key_valid'] = True
                
                # Тестируем скачивание CSV (только первые 1000 символов для теста)
                csv_data = self.download_entity_csv(entity_name)
                if csv_data:
                    entity_result['csv_available'] = True
                    entity_result['csv_size'] = len(csv_data)
                    entity_result['csv_preview'] = csv_data[:200] + '...' if len(csv_data) > 200 else csv_data
                
            except Exception as e:
                entity_result['error'] = str(e)
            
            results['entities'][entity_name] = entity_result
        
        return results
    
    def run_daily_check(self):
        """Запускает ежедневную проверку обновлений."""
        logger.info("🚀 Запуск ежедневной проверки обновлений автомобильных данных")
        
        try:
            # Проверяем обновления
            update_info = self.check_for_updates()
            
            print("\n📊 РЕЗУЛЬТАТ ПРОВЕРКИ ОБНОВЛЕНИЙ:")
            print("=" * 50)
            
            if update_info.get('error'):
                print(f"❌ Ошибка: {update_info['error']}")
                return False
            
            print(f"🔗 API URL: {update_info.get('api_url', 'Не найден')}")
            print(f"📅 Текущая версия: {update_info.get('current_version', 'Неизвестно')}")
            
            if 'latest_version' in update_info:
                print(f"🆕 Последняя версия: {update_info['latest_version']}")
                
                if update_info.get('has_updates'):
                    print("✅ Доступны обновления!")
                    if 'download_url' in update_info:
                        print(f"📥 URL обновления: {update_info['download_url']}")
                    else:
                        print("⚠️ URL для скачивания обновления не найден")
                else:
                    print("ℹ️ Обновления не требуются")
            else:
                print("📄 Ответ API:")
                print(f"   {update_info.get('api_response', 'Нет данных')}")
            
            return True
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при проверке обновлений: {e}")
            return False


def main():
    """Главная функция для тестирования."""
    try:
        updater = CarDataUpdater()
        success = updater.run_daily_check()
        return 0 if success else 1
        
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}")
        return 1


if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)
