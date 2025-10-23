#!/usr/bin/env python3
"""
Скрипт безопасной миграции к реальным данным
Заменяет тестовые данные реальными с возможностью отката
"""

import mysql.connector
import sys
import os
from datetime import datetime
from typing import Tuple, Optional

class SafeMigration:
    def __init__(self):
        self.conn = None
        self.cursor = None
        
    def connect_to_db(self) -> bool:
        """Подключение к базе данных"""
        try:
            self.conn = mysql.connector.connect(
                host='localhost',
                user='v_admin',
                password='Arbitr09102022!',
                database='mi_core',
                autocommit=False
            )
            self.cursor = self.conn.cursor()
            print("✅ Подключение к базе данных установлено")
            return True
        except Exception as e:
            print(f"❌ Ошибка подключения к БД: {e}")
            return False
    
    def create_test_backup(self) -> bool:
        """Создает резервную копию тестовых данных"""
        try:
            # Удаляем старую резервную копию если есть
            self.cursor.execute("DROP TABLE IF EXISTS inventory_data_test_backup")
            
            # Создаем резервную копию тестовых данных
            self.cursor.execute("""
                CREATE TABLE inventory_data_test_backup AS 
                SELECT * FROM inventory_data WHERE sku LIKE 'TEST%'
            """)
            
            # Проверяем количество скопированных записей
            self.cursor.execute("SELECT COUNT(*) FROM inventory_data_test_backup")
            backup_count = self.cursor.fetchone()[0]
            
            print(f"✅ Резервная копия тестовых данных создана ({backup_count} записей)")
            return True
            
        except Exception as e:
            print(f"❌ Ошибка создания резервной копии: {e}")
            return False
    
    def check_real_data_availability(self) -> Tuple[bool, dict]:
        """Проверяет наличие реальных данных"""
        try:
            # Проверяем товары
            self.cursor.execute("SELECT COUNT(*) FROM dim_products")
            products_count = self.cursor.fetchone()[0]
            
            # Проверяем реальные остатки (не тестовые)
            self.cursor.execute("SELECT COUNT(*) FROM inventory_data WHERE sku NOT LIKE 'TEST%'")
            real_inventory_count = self.cursor.fetchone()[0]
            
            # Проверяем заказы
            self.cursor.execute("SELECT COUNT(*) FROM fact_orders")
            orders_count = self.cursor.fetchone()[0]
            
            stats = {
                'products': products_count,
                'inventory': real_inventory_count,
                'orders': orders_count
            }
            
            print(f"📊 Статистика реальных данных:")
            print(f"   - Товары: {products_count}")
            print(f"   - Остатки: {real_inventory_count}")
            print(f"   - Заказы: {orders_count}")
            
            # Считаем готовность к миграции
            ready = products_count > 0 and real_inventory_count > 0
            
            return ready, stats
            
        except Exception as e:
            print(f"❌ Ошибка проверки данных: {e}")
            return False, {}
    
    def remove_test_data(self) -> bool:
        """Удаляет тестовые данные"""
        try:
            # Удаляем тестовые остатки
            self.cursor.execute("DELETE FROM inventory_data WHERE sku LIKE 'TEST%'")
            deleted_count = self.cursor.rowcount
            
            print(f"🗑️  Удалено {deleted_count} тестовых записей из inventory_data")
            return True
            
        except Exception as e:
            print(f"❌ Ошибка удаления тестовых данных: {e}")
            return False
    
    def restore_test_data(self) -> bool:
        """Восстанавливает тестовые данные из резервной копии"""
        try:
            # Проверяем наличие резервной копии
            self.cursor.execute("SELECT COUNT(*) FROM inventory_data_test_backup")
            backup_count = self.cursor.fetchone()[0]
            
            if backup_count == 0:
                print("❌ Резервная копия тестовых данных пуста")
                return False
            
            # Восстанавливаем данные
            self.cursor.execute("INSERT INTO inventory_data SELECT * FROM inventory_data_test_backup")
            restored_count = self.cursor.rowcount
            
            print(f"🔄 Восстановлено {restored_count} тестовых записей")
            return True
            
        except Exception as e:
            print(f"❌ Ошибка восстановления тестовых данных: {e}")
            return False
    
    def verify_migration(self) -> bool:
        """Проверяет успешность миграции"""
        try:
            # Проверяем, что тестовых данных нет
            self.cursor.execute("SELECT COUNT(*) FROM inventory_data WHERE sku LIKE 'TEST%'")
            test_count = self.cursor.fetchone()[0]
            
            # Проверяем, что реальные данные есть
            self.cursor.execute("SELECT COUNT(*) FROM inventory_data WHERE sku NOT LIKE 'TEST%'")
            real_count = self.cursor.fetchone()[0]
            
            print(f"🔍 Проверка миграции:")
            print(f"   - Тестовых записей: {test_count}")
            print(f"   - Реальных записей: {real_count}")
            
            success = test_count == 0 and real_count > 0
            
            if success:
                print("✅ Миграция прошла успешно!")
            else:
                print("❌ Миграция не завершена корректно")
            
            return success
            
        except Exception as e:
            print(f"❌ Ошибка проверки миграции: {e}")
            return False
    
    def migrate(self) -> bool:
        """Выполняет полную миграцию"""
        print("🔄 Начинаем безопасную миграцию к реальным данным...")
        print("=" * 60)
        
        try:
            # 1. Подключаемся к БД
            if not self.connect_to_db():
                return False
            
            # 2. Создаем резервную копию тестовых данных
            if not self.create_test_backup():
                return False
            
            # 3. Проверяем наличие реальных данных
            ready, stats = self.check_real_data_availability()
            
            if not ready:
                print("\n⚠️  Система не готова к миграции:")
                if stats.get('products', 0) == 0:
                    print("   - Нет товаров в dim_products")
                    print("   - Запустите: python importers/ozon_importer.py --action=products")
                    print("   - Запустите: python importers/wb_importer.py --action=products")
                
                if stats.get('inventory', 0) == 0:
                    print("   - Нет реальных остатков в inventory_data")
                    print("   - Запустите: python inventory_sync_service.py --full-sync")
                
                print("\n🔄 Миграция отменена. Исправьте проблемы и повторите попытку.")
                return False
            
            # 4. Удаляем тестовые данные
            if not self.remove_test_data():
                print("🔄 Восстанавливаем тестовые данные...")
                self.restore_test_data()
                self.conn.rollback()
                return False
            
            # 5. Проверяем результат
            if not self.verify_migration():
                print("🔄 Восстанавливаем тестовые данные...")
                self.restore_test_data()
                self.conn.rollback()
                return False
            
            # 6. Подтверждаем изменения
            self.conn.commit()
            
            print("\n" + "=" * 60)
            print("🎉 Миграция завершена успешно!")
            print("📊 Дашборд теперь работает с реальными данными")
            print("🌐 Проверьте: https://www.market-mi.ru")
            
            return True
            
        except Exception as e:
            print(f"❌ Критическая ошибка миграции: {e}")
            if self.conn:
                print("🔄 Откатываем изменения...")
                self.conn.rollback()
            return False
        
        finally:
            if self.conn:
                self.conn.close()
    
    def rollback(self) -> bool:
        """Откатывает миграцию - возвращает тестовые данные"""
        print("🔄 Откат к тестовым данным...")
        
        try:
            if not self.connect_to_db():
                return False
            
            # Удаляем все данные из inventory_data
            self.cursor.execute("DELETE FROM inventory_data")
            
            # Восстанавливаем тестовые данные
            if self.restore_test_data():
                self.conn.commit()
                print("✅ Откат выполнен успешно. Тестовые данные восстановлены.")
                return True
            else:
                self.conn.rollback()
                return False
                
        except Exception as e:
            print(f"❌ Ошибка отката: {e}")
            if self.conn:
                self.conn.rollback()
            return False
        finally:
            if self.conn:
                self.conn.close()

def main():
    """Основная функция"""
    migration = SafeMigration()
    
    # Проверяем аргументы командной строки
    if len(sys.argv) > 1 and sys.argv[1] == '--rollback':
        success = migration.rollback()
    else:
        success = migration.migrate()
    
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()