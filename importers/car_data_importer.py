#!/usr/bin/env python3
"""
Модуль импорта и обновления данных по автомобилям из BaseBuy.ru API.

Функции:
- Первоначальная загрузка данных из MySQL дампа
- Автоматическое обновление через API BaseBuy.ru
- Управление версиями данных
"""

import os
import sys
import requests
import logging
from datetime import datetime
from typing import Optional, Dict, Any, List
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

class CarDataImporter:
    """Класс для импорта и обновления автомобильных данных."""
    
    def __init__(self):
        """Инициализация импортера."""
        self.api_key = os.getenv('BASEBUY_API_KEY')
        self.base_url = 'https://basebuy.ru/api'  # Предполагаемый базовый URL API
        
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
            logger.warning("BASEBUY_API_KEY не найден в переменных окружения")
    
    def connect_to_db(self):
        """Создает подключение к базе данных."""
        try:
            connection = mysql.connector.connect(**self.db_config)
            return connection
        except Error as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            raise
    
    def get_system_setting(self, key: str) -> Optional[str]:
        """Получает значение системной настройки."""
        try:
            connection = self.connect_to_db()
            cursor = connection.cursor()
            
            cursor.execute(
                "SELECT setting_value FROM system_settings WHERE setting_key = %s",
                (key,)
            )
            result = cursor.fetchone()
            
            cursor.close()
            connection.close()
            
            return result[0] if result else None
            
        except Error as e:
            logger.error(f"Ошибка получения настройки {key}: {e}")
            return None
    
    def set_system_setting(self, key: str, value: str, description: str = None):
        """Устанавливает значение системной настройки."""
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
            """, (key, value, description))
            
            connection.commit()
            cursor.close()
            connection.close()
            
            logger.info(f"Настройка {key} обновлена")
            
        except Error as e:
            logger.error(f"Ошибка установки настройки {key}: {e}")
            raise
    
    def check_for_updates(self) -> Dict[str, Any]:
        """
        Проверяет наличие обновлений в BaseBuy API.
        
        Returns:
            Dict с информацией об обновлениях:
            {
                'has_updates': bool,
                'current_version': str,
                'latest_version': str,
                'download_url': str (если есть обновления)
            }
        """
        if not self.api_key:
            logger.error("API ключ BaseBuy не настроен")
            return {'has_updates': False, 'error': 'API ключ не настроен'}
        
        try:
            # Получаем текущую версию из БД
            current_version = self.get_system_setting('basebuy_data_version') or '0'
            
            # Запрос к API для получения информации о последней версии
            headers = {
                'Authorization': f'Bearer {self.api_key}',
                'Content-Type': 'application/json'
            }
            
            # TODO: Уточнить реальный endpoint API BaseBuy
            response = requests.get(
                f'{self.base_url}/version',
                headers=headers,
                timeout=30
            )
            
            if response.status_code == 200:
                data = response.json()
                latest_version = data.get('version', '0')
                
                result = {
                    'has_updates': latest_version != current_version,
                    'current_version': current_version,
                    'latest_version': latest_version
                }
                
                if result['has_updates']:
                    result['download_url'] = data.get('download_url')
                    logger.info(f"Найдено обновление: {current_version} -> {latest_version}")
                else:
                    logger.info(f"Данные актуальны, версия: {current_version}")
                
                return result
                
            else:
                logger.error(f"Ошибка API: {response.status_code} - {response.text}")
                return {'has_updates': False, 'error': f'API error: {response.status_code}'}
                
        except requests.RequestException as e:
            logger.error(f"Ошибка запроса к API: {e}")
            return {'has_updates': False, 'error': str(e)}
        except Exception as e:
            logger.error(f"Неожиданная ошибка при проверке обновлений: {e}")
            return {'has_updates': False, 'error': str(e)}
    
    def apply_updates(self, download_url: str, new_version: str) -> bool:
        """
        Применяет обновления из файла.
        
        Args:
            download_url: URL для скачивания файла обновлений
            new_version: Новая версия данных
            
        Returns:
            bool: True если обновление прошло успешно
        """
        try:
            logger.info(f"Начинаем применение обновлений до версии {new_version}")
            
            # Скачиваем файл обновлений
            headers = {
                'Authorization': f'Bearer {self.api_key}'
            }
            
            response = requests.get(download_url, headers=headers, timeout=300)
            response.raise_for_status()
            
            # Сохраняем файл временно
            temp_file = f'/tmp/basebuy_update_{new_version}.sql'
            with open(temp_file, 'wb') as f:
                f.write(response.content)
            
            logger.info(f"Файл обновлений скачан: {temp_file}")
            
            # TODO: Реализовать парсинг и применение SQL команд
            # Это самая критичная часть - нужно очень аккуратно применять изменения
            success = self._apply_sql_updates(temp_file)
            
            if success:
                # Обновляем версию в системных настройках
                self.set_system_setting(
                    'basebuy_data_version',
                    new_version,
                    f'Версия данных BaseBuy, обновлено {datetime.now().isoformat()}'
                )
                
                logger.info(f"Обновление до версии {new_version} успешно применено")
                
                # Удаляем временный файл
                os.remove(temp_file)
                
                return True
            else:
                logger.error("Ошибка при применении обновлений")
                return False
                
        except Exception as e:
            logger.error(f"Ошибка при применении обновлений: {e}")
            return False
    
    def _apply_sql_updates(self, sql_file: str) -> bool:
        """
        Применяет SQL команды из файла обновлений.
        
        ВНИМАНИЕ: Это критичная функция, требует тщательной реализации!
        """
        try:
            # TODO: Реализовать безопасное применение SQL команд
            # Нужно:
            # 1. Парсить SQL файл
            # 2. Валидировать команды (только разрешенные операции)
            # 3. Применять в транзакции с возможностью отката
            # 4. Логировать все изменения
            
            logger.warning("_apply_sql_updates еще не реализована - требует детального анализа структуры обновлений")
            return False
            
        except Exception as e:
            logger.error(f"Ошибка применения SQL обновлений: {e}")
            return False
    
    def run_daily_update(self):
        """Запускает ежедневную проверку и применение обновлений."""
        logger.info("🚀 Запуск ежедневного обновления автомобильных данных")
        
        try:
            # Проверяем наличие обновлений
            update_info = self.check_for_updates()
            
            if update_info.get('error'):
                logger.error(f"Ошибка при проверке обновлений: {update_info['error']}")
                return False
            
            if not update_info.get('has_updates'):
                logger.info("Обновления не требуются")
                return True
            
            # Применяем обновления
            success = self.apply_updates(
                update_info['download_url'],
                update_info['latest_version']
            )
            
            if success:
                logger.info("✅ Ежедневное обновление завершено успешно")
            else:
                logger.error("❌ Ошибка при ежедневном обновлении")
            
            return success
            
        except Exception as e:
            logger.error(f"Критическая ошибка при ежедневном обновлении: {e}")
            return False


def main():
    """Главная функция для тестирования."""
    importer = CarDataImporter()
    
    # Тестируем проверку обновлений
    update_info = importer.check_for_updates()
    print(f"Информация об обновлениях: {update_info}")


if __name__ == "__main__":
    main()
