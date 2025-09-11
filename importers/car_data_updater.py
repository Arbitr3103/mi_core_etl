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
from datetime import datetime
from typing import Optional, Dict, Any
import mysql.connector
from mysql.connector import Error
from dotenv import load_dotenv

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
        
        # Возможные варианты базового URL API BaseBuy
        self.possible_base_urls = [
            'https://api.basebuy.ru/api/auto/v1',
            'https://basebuy.ru/api/auto/v1',
            'https://api.basebuy.ru/v1',
            'https://basebuy.ru/api/v1'
        ]
        
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
    
    def test_api_connection(self):
        """Тестирует подключение к API BaseBuy и определяет рабочий URL."""
        logger.info("🔍 Тестируем подключение к API BaseBuy...")
        
        headers = {
            'Authorization': f'Bearer {self.api_key}',
            'Content-Type': 'application/json',
            'User-Agent': 'mi_core_etl/1.0'
        }
        
        # Возможные endpoints для получения версии
        version_endpoints = ['/version', '/info', '/status', '/database/version']
        
        for base_url in self.possible_base_urls:
            logger.info(f"Проверяем базовый URL: {base_url}")
            
            for endpoint in version_endpoints:
                full_url = f"{base_url}{endpoint}"
                
                try:
                    logger.info(f"  Тестируем: {full_url}")
                    
                    response = requests.get(
                        full_url,
                        headers=headers,
                        timeout=10
                    )
                    
                    logger.info(f"  Статус: {response.status_code}")
                    
                    if response.status_code == 200:
                        try:
                            data = response.json()
                            logger.info(f"  ✅ Успешный ответ от {full_url}")
                            logger.info(f"  📄 Данные: {data}")
                            return full_url, data
                        except ValueError:
                            logger.info(f"  📄 Текстовый ответ: {response.text[:200]}")
                            return full_url, response.text
                    
                    elif response.status_code == 401:
                        logger.warning(f"  🔐 Ошибка авторизации (401) - проверьте API ключ")
                    elif response.status_code == 404:
                        logger.debug(f"  ❌ Endpoint не найден (404)")
                    else:
                        logger.info(f"  ⚠️ Код ответа: {response.status_code}")
                        logger.info(f"  📄 Ответ: {response.text[:200]}")
                
                except requests.exceptions.Timeout:
                    logger.warning(f"  ⏱️ Таймаут для {full_url}")
                except requests.exceptions.ConnectionError:
                    logger.warning(f"  🔌 Ошибка подключения к {full_url}")
                except Exception as e:
                    logger.warning(f"  ❌ Ошибка для {full_url}: {e}")
        
        logger.error("❌ Не удалось найти рабочий API endpoint")
        return None, None
    
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
        
        # Тестируем API
        api_url, api_response = self.test_api_connection()
        
        if not api_url:
            return {
                'has_updates': False,
                'error': 'Не удалось подключиться к API BaseBuy',
                'current_version': current_version
            }
        
        # Анализируем ответ API
        result = {
            'has_updates': False,
            'current_version': current_version,
            'api_url': api_url,
            'api_response': api_response
        }
        
        # Если получили JSON с версией
        if isinstance(api_response, dict):
            if 'version' in api_response:
                latest_version = api_response['version']
                result['latest_version'] = latest_version
                result['has_updates'] = latest_version != current_version
                
                if 'update_file_url' in api_response:
                    result['download_url'] = api_response['update_file_url']
                elif 'download_url' in api_response:
                    result['download_url'] = api_response['download_url']
        
        return result
    
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
