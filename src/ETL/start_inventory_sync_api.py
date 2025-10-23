#!/usr/bin/env python3
"""
Скрипт запуска API управления синхронизацией остатков.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

def main():
    """Главная функция запуска API."""
    print("🚀 Запуск API управления синхронизацией остатков")
    print("=" * 60)
    
    try:
        # Импортируем и запускаем API
        from inventory_sync_api import app, api_instance
        
        print("📋 Проверка подключения к базе данных...")
        if api_instance.connect_to_database():
            print("✅ Подключение к базе данных установлено")
        else:
            print("❌ Не удалось подключиться к базе данных")
            print("   Проверьте настройки подключения в config.py")
        
        print("\n📋 Доступные endpoints:")
        print("   GET  /                  - Веб-интерфейс управления")
        print("   GET  /logs              - Страница логов")
        print("   GET  /api/sync/status   - Статус синхронизации")
        print("   GET  /api/sync/reports  - Отчеты о синхронизации")
        print("   POST /api/sync/trigger  - Запуск принудительной синхронизации")
        print("   GET  /api/sync/health   - Проверка состояния системы")
        print("   GET  /api/sync/logs     - Логи синхронизации")
        
        print("\n🌐 Сервер будет доступен по адресу: http://localhost:5001")
        print("🛑 Для остановки нажмите Ctrl+C")
        print("=" * 60)
        
        # Запускаем сервер
        app.run(
            host='0.0.0.0',
            port=5001,
            debug=False,  # Отключаем debug в продакшн режиме
            threaded=True
        )
        
    except ImportError as e:
        print(f"❌ Ошибка импорта модулей: {e}")
        print("   Убедитесь, что все зависимости установлены:")
        print("   pip install flask flask-cors requests")
        sys.exit(1)
        
    except KeyboardInterrupt:
        print("\n🛑 Сервер остановлен пользователем")
        
    except Exception as e:
        print(f"❌ Критическая ошибка: {e}")
        sys.exit(1)


if __name__ == '__main__':
    main()