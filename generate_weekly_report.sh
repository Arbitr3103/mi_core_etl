#!/bin/bash
"""
Weekly Inventory Report Generator
Генератор еженедельных отчетов по синхронизации остатков

Использование:
  ./generate_weekly_report.sh

Для добавления в crontab:
  # Каждый понедельник в 08:00
  0 8 * * 1 /path/to/generate_weekly_report.sh

Автор: Inventory Reporting System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
REPORTS_DIR="$SCRIPT_DIR/reports"
REPORT_LOG="$LOG_DIR/weekly_report.log"

# Функция логирования
log() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$REPORT_LOG"
}

# Создание необходимых директорий
mkdir -p "$LOG_DIR" "$REPORTS_DIR"

log "INFO" "=== ГЕНЕРАЦИЯ ЕЖЕНЕДЕЛЬНОГО ОТЧЕТА ==="

# Проверка Python окружения
PYTHON_CMD=""
if command -v python3 &> /dev/null; then
    PYTHON_CMD="python3"
elif command -v python &> /dev/null; then
    PYTHON_CMD="python"
else
    log "ERROR" "Python не найден в системе"
    exit 1
fi

# Установка переменных окружения
export PYTHONPATH="$SCRIPT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8

# Создание Python скрипта для генерации отчета
REPORT_SCRIPT=$(mktemp)
cat > "$REPORT_SCRIPT" << 'EOF'
#!/usr/bin/env python3
import sys
import os
import json
from datetime import datetime, timedelta
sys.path.append(os.path.dirname(__file__))

def generate_weekly_report():
    """Генерация еженедельного отчета по синхронизации остатков."""
    try:
        from importers.ozon_importer import connect_to_db
        
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Период отчета (последние 7 дней)
        end_date = datetime.now()
        start_date = end_date - timedelta(days=7)
        
        report_data = {
            'report_period': {
                'start_date': start_date.isoformat(),
                'end_date': end_date.isoformat(),
                'days': 7
            },
            'sync_statistics': {},
            'inventory_statistics': {},
            'error_analysis': {},
            'performance_metrics': {},
            'recommendations': []
        }
        
        # 1. Статистика синхронизаций за неделю
        cursor.execute("""
            SELECT 
                source,
                COUNT(*) as total_syncs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_syncs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_syncs,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_syncs,
                AVG(duration_seconds) as avg_duration,
                MIN(duration_seconds) as min_duration,
                MAX(duration_seconds) as max_duration,
                SUM(records_processed) as total_processed,
                SUM(records_updated) as total_updated,
                AVG(records_processed) as avg_processed
            FROM sync_logs 
            WHERE sync_type = 'inventory' 
            AND started_at >= %s 
            AND started_at <= %s
            GROUP BY source
        """, (start_date, end_date))
        
        sync_stats = cursor.fetchall()
        
        for stat in sync_stats:
            source = stat['source']
            success_rate = (stat['successful_syncs'] / stat['total_syncs'] * 100) if stat['total_syncs'] > 0 else 0
            
            report_data['sync_statistics'][source] = {
                'total_syncs': stat['total_syncs'],
                'successful_syncs': stat['successful_syncs'],
                'failed_syncs': stat['failed_syncs'],
                'partial_syncs': stat['partial_syncs'],
                'success_rate': round(success_rate, 2),
                'avg_duration': round(float(stat['avg_duration'] or 0), 2),
                'min_duration': stat['min_duration'],
                'max_duration': stat['max_duration'],
                'total_processed': stat['total_processed'],
                'total_updated': stat['total_updated'],
                'avg_processed': round(float(stat['avg_processed'] or 0), 2)
            }
        
        # 2. Текущая статистика остатков
        cursor.execute("""
            SELECT 
                source,
                COUNT(*) as total_records,
                COUNT(DISTINCT product_id) as unique_products,
                SUM(quantity_present) as total_present,
                SUM(quantity_reserved) as total_reserved,
                AVG(quantity_present) as avg_present,
                MAX(last_sync_at) as last_sync,
                MIN(last_sync_at) as oldest_sync
            FROM inventory_data
            GROUP BY source
        """)
        
        inventory_stats = cursor.fetchall()
        
        for stat in inventory_stats:
            source = stat['source']
            available_stock = (stat['total_present'] or 0) - (stat['total_reserved'] or 0)
            
            report_data['inventory_statistics'][source] = {
                'total_records': stat['total_records'],
                'unique_products': stat['unique_products'],
                'total_present': stat['total_present'],
                'total_reserved': stat['total_reserved'],
                'available_stock': max(0, available_stock),
                'avg_present': round(float(stat['avg_present'] or 0), 2),
                'last_sync': stat['last_sync'].isoformat() if stat['last_sync'] else None,
                'oldest_sync': stat['oldest_sync'].isoformat() if stat['oldest_sync'] else None
            }
        
        # 3. Анализ ошибок за неделю
        cursor.execute("""
            SELECT 
                source,
                error_message,
                COUNT(*) as error_count,
                MIN(started_at) as first_occurrence,
                MAX(started_at) as last_occurrence
            FROM sync_logs 
            WHERE sync_type = 'inventory' 
            AND status = 'failed'
            AND started_at >= %s 
            AND started_at <= %s
            AND error_message IS NOT NULL
            GROUP BY source, error_message
            ORDER BY error_count DESC
        """, (start_date, end_date))
        
        error_stats = cursor.fetchall()
        
        for source in ['Ozon', 'Wildberries']:
            report_data['error_analysis'][source] = []
        
        for error in error_stats:
            source = error['source']
            if source not in report_data['error_analysis']:
                report_data['error_analysis'][source] = []
            
            report_data['error_analysis'][source].append({
                'error_message': error['error_message'],
                'count': error['error_count'],
                'first_occurrence': error['first_occurrence'].isoformat(),
                'last_occurrence': error['last_occurrence'].isoformat()
            })
        
        # 4. Метрики производительности
        cursor.execute("""
            SELECT 
                DATE(started_at) as sync_date,
                source,
                COUNT(*) as syncs_per_day,
                AVG(duration_seconds) as avg_duration_day,
                SUM(records_processed) as records_per_day
            FROM sync_logs 
            WHERE sync_type = 'inventory' 
            AND started_at >= %s 
            AND started_at <= %s
            GROUP BY DATE(started_at), source
            ORDER BY sync_date DESC
        """, (start_date, end_date))
        
        daily_stats = cursor.fetchall()
        
        report_data['performance_metrics']['daily_breakdown'] = []
        for stat in daily_stats:
            report_data['performance_metrics']['daily_breakdown'].append({
                'date': stat['sync_date'].isoformat(),
                'source': stat['source'],
                'syncs_count': stat['syncs_per_day'],
                'avg_duration': round(float(stat['avg_duration_day'] or 0), 2),
                'records_processed': stat['records_per_day']
            })
        
        # 5. Топ товаров по остаткам
        cursor.execute("""
            SELECT 
                dp.sku_ozon,
                dp.sku_wb,
                dp.product_name,
                id.source,
                id.quantity_present,
                id.quantity_reserved,
                id.last_sync_at
            FROM inventory_data id
            JOIN dim_products dp ON id.product_id = dp.id
            WHERE id.quantity_present > 0
            ORDER BY id.quantity_present DESC
            LIMIT 20
        """)
        
        top_products = cursor.fetchall()
        
        report_data['top_products'] = []
        for product in top_products:
            report_data['top_products'].append({
                'sku_ozon': product['sku_ozon'],
                'sku_wb': product['sku_wb'],
                'product_name': product['product_name'],
                'source': product['source'],
                'quantity_present': product['quantity_present'],
                'quantity_reserved': product['quantity_reserved'],
                'available': product['quantity_present'] - (product['quantity_reserved'] or 0),
                'last_sync': product['last_sync_at'].isoformat() if product['last_sync_at'] else None
            })
        
        # 6. Генерация рекомендаций
        recommendations = []
        
        # Анализ успешности синхронизации
        for source, stats in report_data['sync_statistics'].items():
            if stats['success_rate'] < 90:
                recommendations.append({
                    'type': 'sync_reliability',
                    'priority': 'high' if stats['success_rate'] < 70 else 'medium',
                    'message': f"Низкий процент успешности синхронизации {source}: {stats['success_rate']}%",
                    'suggestion': f"Проверить настройки API и стабильность подключения для {source}"
                })
        
        # Анализ производительности
        for source, stats in report_data['sync_statistics'].items():
            if stats['avg_duration'] > 300:  # Более 5 минут
                recommendations.append({
                    'type': 'performance',
                    'priority': 'medium',
                    'message': f"Долгое время синхронизации {source}: {stats['avg_duration']}с",
                    'suggestion': f"Оптимизировать запросы к API или увеличить timeout для {source}"
                })
        
        # Анализ актуальности данных
        for source, stats in report_data['inventory_statistics'].items():
            if stats['last_sync']:
                last_sync = datetime.fromisoformat(stats['last_sync'].replace('Z', '+00:00'))
                hours_since_sync = (datetime.now() - last_sync.replace(tzinfo=None)).total_seconds() / 3600
                
                if hours_since_sync > 12:
                    recommendations.append({
                        'type': 'data_freshness',
                        'priority': 'high',
                        'message': f"Устаревшие данные {source}: {hours_since_sync:.1f} часов назад",
                        'suggestion': f"Проверить работу cron задач для {source}"
                    })
        
        # Анализ количества товаров
        for source, stats in report_data['inventory_statistics'].items():
            if stats['unique_products'] < 10:
                recommendations.append({
                    'type': 'data_completeness',
                    'priority': 'high',
                    'message': f"Мало товаров в {source}: {stats['unique_products']}",
                    'suggestion': f"Проверить корректность маппинга SKU для {source}"
                })
        
        report_data['recommendations'] = recommendations
        
        cursor.close()
        connection.close()
        
        return report_data
        
    except Exception as e:
        return {
            'error': str(e),
            'generated_at': datetime.now().isoformat()
        }

if __name__ == "__main__":
    report = generate_weekly_report()
    print(json.dumps(report, indent=2, default=str, ensure_ascii=False))
EOF

# Выполнение генерации отчета
cd "$SCRIPT_DIR"
REPORT_DATA=$($PYTHON_CMD "$REPORT_SCRIPT" 2>/dev/null)
REPORT_EXIT_CODE=$?

# Удаление временного скрипта
rm -f "$REPORT_SCRIPT"

if [ $REPORT_EXIT_CODE -ne 0 ]; then
    log "ERROR" "Ошибка генерации отчета"
    exit 1
fi

# Сохранение JSON отчета
REPORT_DATE=$(date +%Y%m%d)
JSON_REPORT="$REPORTS_DIR/weekly_report_${REPORT_DATE}.json"
echo "$REPORT_DATA" > "$JSON_REPORT"

log "INFO" "JSON отчет сохранен: $JSON_REPORT"

# Генерация текстового отчета
TEXT_REPORT="$REPORTS_DIR/weekly_report_${REPORT_DATE}.txt"

cat > "$TEXT_REPORT" << 'EOF'
================================================================================
                    ЕЖЕНЕДЕЛЬНЫЙ ОТЧЕТ ПО СИНХРОНИЗАЦИИ ОСТАТКОВ
================================================================================
EOF

echo "Дата генерации: $(date)" >> "$TEXT_REPORT"
echo "Период отчета: $(date -d '7 days ago' '+%Y-%m-%d') - $(date '+%Y-%m-%d')" >> "$TEXT_REPORT"
echo "" >> "$TEXT_REPORT"

# Парсинг и форматирование отчета
if command -v jq &> /dev/null; then
    # Используем jq для красивого форматирования
    
    echo "=== СТАТИСТИКА СИНХРОНИЗАЦИЙ ===" >> "$TEXT_REPORT"
    echo "$REPORT_DATA" | jq -r '
        .sync_statistics | to_entries[] | 
        "
Источник: \(.key)
  Всего синхронизаций: \(.value.total_syncs)
  Успешных: \(.value.successful_syncs)
  Неудачных: \(.value.failed_syncs)
  Частичных: \(.value.partial_syncs)
  Процент успеха: \(.value.success_rate)%
  Среднее время: \(.value.avg_duration)с
  Обработано записей: \(.value.total_processed)
"' >> "$TEXT_REPORT"
    
    echo "=== ТЕКУЩИЕ ОСТАТКИ ===" >> "$TEXT_REPORT"
    echo "$REPORT_DATA" | jq -r '
        .inventory_statistics | to_entries[] | 
        "
Источник: \(.key)
  Всего записей: \(.value.total_records)
  Уникальных товаров: \(.value.unique_products)
  Общий остаток: \(.value.total_present)
  Зарезервировано: \(.value.total_reserved)
  Доступно: \(.value.available_stock)
  Последняя синхронизация: \(.value.last_sync // "Нет данных")
"' >> "$TEXT_REPORT"
    
    echo "=== АНАЛИЗ ОШИБОК ===" >> "$TEXT_REPORT"
    ERROR_COUNT=$(echo "$REPORT_DATA" | jq -r '[.error_analysis[] | length] | add // 0')
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo "$REPORT_DATA" | jq -r '
            .error_analysis | to_entries[] | select(.value | length > 0) |
            "
Источник: \(.key)" as $source |
            .value[] | 
            "  Ошибка: \(.error_message)
  Количество: \(.count)
  Первое появление: \(.first_occurrence)
  Последнее появление: \(.last_occurrence)
"' >> "$TEXT_REPORT"
    else
        echo "Ошибок за отчетный период не обнаружено." >> "$TEXT_REPORT"
    fi
    
    echo "=== РЕКОМЕНДАЦИИ ===" >> "$TEXT_REPORT"
    RECOMMENDATIONS_COUNT=$(echo "$REPORT_DATA" | jq -r '.recommendations | length')
    if [ "$RECOMMENDATIONS_COUNT" -gt 0 ]; then
        echo "$REPORT_DATA" | jq -r '
            .recommendations[] | 
            "
Приоритет: \(.priority | ascii_upcase)
Проблема: \(.message)
Рекомендация: \(.suggestion)
"' >> "$TEXT_REPORT"
    else
        echo "Все системы работают в штатном режиме. Рекомендаций нет." >> "$TEXT_REPORT"
    fi
    
    echo "=== ТОП-10 ТОВАРОВ ПО ОСТАТКАМ ===" >> "$TEXT_REPORT"
    echo "$REPORT_DATA" | jq -r '
        .top_products[:10][] | 
        "SKU: \(.sku_ozon // .sku_wb // "N/A") | \(.product_name // "Без названия") | \(.source) | Остаток: \(.quantity_present) | Доступно: \(.available)"' >> "$TEXT_REPORT"
    
else
    # Простое форматирование без jq
    echo "=== ОБЩАЯ ИНФОРМАЦИЯ ===" >> "$TEXT_REPORT"
    echo "Отчет сгенерирован успешно." >> "$TEXT_REPORT"
    echo "Подробные данные доступны в JSON файле: $JSON_REPORT" >> "$TEXT_REPORT"
fi

echo "" >> "$TEXT_REPORT"
echo "================================================================================" >> "$TEXT_REPORT"
echo "Конец отчета" >> "$TEXT_REPORT"

log "INFO" "Текстовый отчет сохранен: $TEXT_REPORT"

# Генерация HTML отчета (опционально)
HTML_REPORT="$REPORTS_DIR/weekly_report_${REPORT_DATE}.html"

cat > "$HTML_REPORT" << 'EOF'
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Еженедельный отчет по синхронизации остатков</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background-color: #f0f0f0; padding: 20px; border-radius: 5px; }
        .section { margin: 20px 0; }
        .stats-table { border-collapse: collapse; width: 100%; }
        .stats-table th, .stats-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .stats-table th { background-color: #f2f2f2; }
        .success { color: green; }
        .warning { color: orange; }
        .error { color: red; }
        .recommendation { background-color: #fff3cd; padding: 10px; margin: 5px 0; border-radius: 3px; }
        .high-priority { border-left: 5px solid red; }
        .medium-priority { border-left: 5px solid orange; }
        .low-priority { border-left: 5px solid green; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Еженедельный отчет по синхронизации остатков</h1>
        <p>Дата генерации: <strong>$(date)</strong></p>
        <p>Период: <strong>$(date -d '7 days ago' '+%Y-%m-%d') - $(date '+%Y-%m-%d')</strong></p>
    </div>
    
    <div class="section">
        <h2>Статистика синхронизаций</h2>
        <p>Подробная статистика доступна в JSON файле.</p>
    </div>
    
    <div class="section">
        <h2>Текущие остатки</h2>
        <p>Информация о текущем состоянии остатков товаров.</p>
    </div>
    
    <div class="section">
        <h2>Рекомендации</h2>
        <p>Рекомендации по улучшению работы системы.</p>
    </div>
    
    <div class="section">
        <h2>Файлы отчета</h2>
        <ul>
            <li><a href="weekly_report_${REPORT_DATE}.json">JSON отчет</a></li>
            <li><a href="weekly_report_${REPORT_DATE}.txt">Текстовый отчет</a></li>
        </ul>
    </div>
</body>
</html>
EOF

log "INFO" "HTML отчет сохранен: $HTML_REPORT"

# Отправка уведомления о готовности отчета
log "INFO" "Еженедельный отчет готов:"
log "INFO" "  - JSON: $JSON_REPORT"
log "INFO" "  - Текст: $TEXT_REPORT"
log "INFO" "  - HTML: $HTML_REPORT"

# Очистка старых отчетов (старше 90 дней)
OLD_REPORTS=$(find "$REPORTS_DIR" -name "weekly_report_*" -mtime +90 -type f 2>/dev/null | wc -l)
if [ "$OLD_REPORTS" -gt 0 ]; then
    find "$REPORTS_DIR" -name "weekly_report_*" -mtime +90 -type f -delete 2>/dev/null
    log "INFO" "Удалено старых отчетов: $OLD_REPORTS"
fi

# Проверка размера директории отчетов
REPORTS_SIZE=$(du -sh "$REPORTS_DIR" 2>/dev/null | cut -f1)
log "INFO" "Размер директории отчетов: $REPORTS_SIZE"

log "INFO" "=== ГЕНЕРАЦИЯ ОТЧЕТА ЗАВЕРШЕНА ==="

exit 0