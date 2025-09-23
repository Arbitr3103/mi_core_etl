#!/usr/bin/env python3
"""
Тестирование инициализации компонентов системы пополнения.
"""

import sys
import os
import traceback

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

def test_component_init():
    """Тестируем инициализацию каждого компонента отдельно."""
    
    print("🧪 ТЕСТИРОВАНИЕ ИНИЦИАЛИЗАЦИИ КОМПОНЕНТОВ")
    print("=" * 50)
    
    # Тест 1: ReplenishmentRecommender
    print("\n1️⃣ Тестирование ReplenishmentRecommender...")
    try:
        from replenishment_recommender import ReplenishmentRecommender
        recommender = ReplenishmentRecommender()
        print("   ✅ ReplenishmentRecommender инициализирован")
    except Exception as e:
        print(f"   ❌ Ошибка ReplenishmentRecommender: {e}")
        traceback.print_exc()
    
    # Тест 2: AlertManager
    print("\n2️⃣ Тестирование AlertManager...")
    try:
        from alert_manager import AlertManager
        alert_manager = AlertManager()
        print("   ✅ AlertManager инициализирован")
    except Exception as e:
        print(f"   ❌ Ошибка AlertManager: {e}")
        traceback.print_exc()
    
    # Тест 3: ReportingEngine
    print("\n3️⃣ Тестирование ReportingEngine...")
    try:
        from reporting_engine import ReportingEngine
        reporting_engine = ReportingEngine()
        print("   ✅ ReportingEngine инициализирован")
    except Exception as e:
        print(f"   ❌ Ошибка ReportingEngine: {e}")
        traceback.print_exc()
    
    # Тест 4: ReplenishmentOrchestrator
    print("\n4️⃣ Тестирование ReplenishmentOrchestrator...")
    try:
        from replenishment_orchestrator import ReplenishmentOrchestrator
        orchestrator = ReplenishmentOrchestrator()
        print("   ✅ ReplenishmentOrchestrator инициализирован")
    except Exception as e:
        print(f"   ❌ Ошибка ReplenishmentOrchestrator: {e}")
        traceback.print_exc()
    
    print("\n🏁 Тестирование завершено!")

if __name__ == '__main__':
    test_component_init()