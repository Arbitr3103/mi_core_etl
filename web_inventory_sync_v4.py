#!/usr/bin/env python3
"""
Веб-версия сервиса синхронизации остатков с интеграцией Ozon v4 API.
Специально настроена для работы через веб-интерфейс.
"""

import os
import sys
import json
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(__file__))

# Принудительно устанавливаем правильные переменные окружения
os.environ['DB_HOST'] = '127.0.0.1'
os.environ['DB_NAME'] = 'mi_core'
os.environ['DB_USER'] = 'v_admin'
os.environ['DB_PASSWORD'] = 'Arbitr09102022!'

try:
    from inventory_sync_service_v4 import InventorySyncServiceV4
except ImportError as e:
    print(json.dumps({
        "success": False,
        "error": f"Import error: {e}",
        "timestamp": datetime.now().isoformat()
    }))
    sys.exit(1)

def run_sync():
    """Запуск синхронизации через веб-интерфейс."""
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        result = service.sync_ozon_inventory_v4()
        
        output = {
            "success": True,
            "source": result.source,
            "status": result.status.value,
            "records_processed": result.records_processed,
            "records_inserted": result.records_inserted,
            "records_failed": result.records_failed,
            "duration_seconds": result.duration_seconds,
            "api_requests_count": result.api_requests_count,
            "error_message": result.error_message,
            "timestamp": result.completed_at.isoformat() if result.completed_at else None
        }
        print(json.dumps(output))
        
    except Exception as e:
        output = {
            "success": False,
            "error": str(e),
            "timestamp": datetime.now().isoformat()
        }
        print(json.dumps(output))
        
    finally:
        service.close_database_connection()

def test_api():
    """Тест API без БД."""
    service = InventorySyncServiceV4()
    
    try:
        # Тест API без БД
        result = service.get_ozon_stocks_v4_old(limit=5)
        
        output = {
            "success": True,
            "api_working": True,
            "items_received": result["total_items"],
            "has_next": result["has_next"],
            "cursor_present": bool(result["last_id"]),
            "message": "v4 API работает корректно"
        }
        print(json.dumps(output))
        
    except Exception as e:
        output = {
            "success": False,
            "api_working": False,
            "error": str(e),
            "message": "v4 API недоступен"
        }
        print(json.dumps(output))

if __name__ == "__main__":
    action = sys.argv[1] if len(sys.argv) > 1 else 'sync'
    
    if action == 'sync':
        run_sync()
    elif action == 'test':
        test_api()
    else:
        print(json.dumps({
            "success": False,
            "error": f"Unknown action: {action}",
            "timestamp": datetime.now().isoformat()
        }))