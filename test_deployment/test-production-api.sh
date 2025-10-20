#!/bin/bash

echo "🧪 ПОЛНОЕ ТЕСТИРОВАНИЕ ПРОДАКШН API"
echo "=================================="

API_BASE="http://api.zavodprostavok.ru/api/inventory-v4.php"

echo ""
echo "📊 1. Тестируем action=overview..."
curl -s "$API_BASE?action=overview" | jq '.' | head -20

echo ""
echo "📦 2. Тестируем action=products (первые 3)..."
curl -s "$API_BASE?action=products&limit=3" | jq '.' | head -30

echo ""
echo "🔍 3. Тестируем action=products с поиском..."
curl -s "$API_BASE?action=products&search=596534196&limit=2" | jq '.'

echo ""
echo "⚠️ 4. Тестируем action=critical (критические остатки)..."
curl -s "$API_BASE?action=critical&threshold=10" | jq '.data.stats'

echo ""
echo "📈 5. Тестируем action=marketing (аналитика)..."
curl -s "$API_BASE?action=marketing" | jq '.data.overall_stats'

echo ""
echo "📋 6. Тестируем action=stats (общая статистика)..."
curl -s "$API_BASE?action=stats" | jq '.data.overview'

echo ""
echo "🧪 7. Тестируем action=test (тест API)..."
curl -s "$API_BASE?action=test" | jq '.'

echo ""
echo "🔄 8. Тестируем action=sync (имитация синхронизации)..."
curl -s "$API_BASE?action=sync" | jq '.'

echo ""
echo "✅ ТЕСТИРОВАНИЕ ЗАВЕРШЕНО!"