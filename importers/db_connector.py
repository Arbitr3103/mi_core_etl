"""
Универсальный модуль для подключения к PostgreSQL базе данных.

Используется всеми импортерами для единообразного подключения к БД.
"""

import os
import logging
import psycopg2
from psycopg2 import Error
from psycopg2.extras import RealDictCursor
from dotenv import load_dotenv
from typing import Dict

logger = logging.getLogger(__name__)


def load_config() -> Dict[str, str]:
    """
    Загружает конфигурацию из .env файла.
    
    Returns:
        Dict[str, str]: Словарь с конфигурационными параметрами
    """
    load_dotenv()
    
    config = {
        'DB_HOST': os.getenv('DB_HOST', 'localhost'),
        'DB_USER': os.getenv('DB_USER'),
        'DB_PASSWORD': os.getenv('DB_PASSWORD'),
        'DB_NAME': os.getenv('DB_NAME'),
        'DB_PORT': os.getenv('DB_PORT', '5432'),
    }
    
    # Проверяем обязательные параметры
    missing_params = [key for key, value in config.items() 
                     if not value and key not in ['DB_PORT', 'DB_HOST']]
    if missing_params:
        raise ValueError(f"Отсутствуют обязательные параметры в .env файле: {missing_params}")
    
    logger.info("Конфигурация БД успешно загружена")
    return config


def connect_to_db(dict_cursor=False) -> psycopg2.extensions.connection:
    """
    Устанавливает соединение с базой данных PostgreSQL.
    
    Args:
        dict_cursor (bool): Если True, использует RealDictCursor для возврата словарей
    
    Returns:
        psycopg2.extensions.connection: Объект соединения с базой данных
    """
    config = load_config()
    
    try:
        connection = psycopg2.connect(
            host=config['DB_HOST'],
            port=config['DB_PORT'],
            user=config['DB_USER'],
            password=config['DB_PASSWORD'],
            dbname=config['DB_NAME'],
            connect_timeout=10
        )
        
        # Устанавливаем autocommit для совместимости с MySQL кодом
        connection.autocommit = True
        
        logger.info(f"✅ Успешное подключение к PostgreSQL БД: {config['DB_NAME']}")
        return connection
        
    except Error as e:
        logger.error(f"❌ Ошибка подключения к PostgreSQL: {e}")
        raise


def get_cursor(connection, dict_cursor=False):
    """
    Создает курсор для выполнения SQL запросов.
    
    Args:
        connection: Подключение к БД
        dict_cursor (bool): Если True, возвращает RealDictCursor
    
    Returns:
        Курсор для выполнения запросов
    """
    if dict_cursor:
        return connection.cursor(cursor_factory=RealDictCursor)
    return connection.cursor()
