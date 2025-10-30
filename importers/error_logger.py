"""
Comprehensive Error Logger for Python ETL Components

Structured logging with rotation, archiving, and alerting capabilities.

Requirements: 7.1, 7.2, 7.3, 7.4
"""

import os
import json
import gzip
import logging
import traceback
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, Any, Optional, List
import requests
from logging.handlers import RotatingFileHandler
import glob


class StructuredFormatter(logging.Formatter):
    """Custom formatter for structured JSON logging"""
    
    def format(self, record: logging.LogRecord) -> str:
        log_entry = {
            'timestamp': datetime.fromtimestamp(record.created).isoformat(),
            'level': record.levelname,
            'component': record.name,
            'message': record.getMessage(),
            'context': {},
            'runtime': {
                'process_id': os.getpid(),
                'thread_id': record.thread,
                'thread_name': record.threadName
            }
        }
        
        # Add exception info if present
        if record.exc_info:
            log_entry['context']['exception'] = {
                'type': record.exc_info[0].__name__,
                'message': str(record.exc_info[1]),
                'traceback': traceback.format_exception(*record.exc_info)
            }
        
        # Add extra context if present
        if hasattr(record, 'context'):
            log_entry['context'].update(record.context)
        
        # Add trace ID if present
        if hasattr(record, 'trace_id'):
            log_entry['trace_id'] = record.trace_id
        
        return json.dumps(log_entry, ensure_ascii=False)


class ComprehensiveErrorLogger:
    """Comprehensive error logger with rotation and alerting"""
    
    def __init__(self, config: Optional[Dict[str, Any]] = None):
        self.config = config or {}
        self.log_base_path = Path(self.config.get('log_path', '../logs'))
        self.max_log_size = self._parse_size(self.config.get('max_log_size', '50MB'))
        self.max_log_files = self.config.get('max_log_files', 30)
        self.alert_config = self.config.get('alerts', {})
        self.trace_id = None
        
        # Ensure log directories exist
        self._ensure_log_directories()
        
        # Setup loggers
        self.loggers = {}
    
    def _ensure_log_directories(self):
        """Ensure all required log directories exist"""
        directories = [
            self.log_base_path,
            self.log_base_path / 'etl',
            self.log_base_path / 'monitoring',
            self.log_base_path / 'archive'
        ]
        
        for directory in directories:
            directory.mkdir(parents=True, exist_ok=True)
    
    def _parse_size(self, size: str) -> int:
        """Parse size string to bytes"""
        units = {'B': 1, 'KB': 1024, 'MB': 1024**2, 'GB': 1024**3}
        size = size.upper().strip()
        
        for unit, multiplier in units.items():
            if unit in size:
                return int(size.replace(unit, '')) * multiplier
        
        return int(size)
    
    def get_logger(self, component: str) -> logging.Logger:
        """Get or create logger for component"""
        if component in self.loggers:
            return self.loggers[component]
        
        logger = logging.getLogger(component)
        logger.setLevel(logging.DEBUG)
        logger.handlers.clear()
        
        # Create log file path
        log_file = self.log_base_path / 'etl' / f'{component}-{datetime.now().strftime("%Y-%m-%d")}.log'
        
        # Create rotating file handler
        handler = RotatingFileHandler(
            log_file,
            maxBytes=self.max_log_size,
            backupCount=self.max_log_files
        )
        
        # Set structured formatter
        handler.setFormatter(StructuredFormatter())
        logger.addHandler(handler)
        
        # Also add console handler for development
        console_handler = logging.StreamHandler()
        console_handler.setFormatter(logging.Formatter(
            '%(asctime)s - %(name)s - %(levelname)s - %(message)s'
        ))
        logger.addHandler(console_handler)
        
        self.loggers[component] = logger
        return logger
    
    def set_trace_id(self, trace_id: str):
        """Set trace ID for request tracking"""
        self.trace_id = trace_id
    
    def log(self, level: str, message: str, context: Optional[Dict] = None, 
            component: str = 'general', exc_info: bool = False):
        """Log a message with specified level"""
        logger = self.get_logger(component)
        
        # Create log record with context
        extra = {
            'context': context or {},
            'trace_id': self.trace_id or f'trace_{datetime.now().timestamp()}'
        }
        
        # Map level to logging method
        log_method = getattr(logger, level.lower(), logger.info)
        log_method(message, extra=extra, exc_info=exc_info)
        
        # Send alerts for critical levels
        if level.upper() in ['CRITICAL', 'ERROR']:
            self._send_alert(level, message, context, component)
    
    def debug(self, message: str, context: Optional[Dict] = None, component: str = 'general'):
        """Log debug message"""
        self.log('debug', message, context, component)
    
    def info(self, message: str, context: Optional[Dict] = None, component: str = 'general'):
        """Log info message"""
        self.log('info', message, context, component)
    
    def warning(self, message: str, context: Optional[Dict] = None, component: str = 'general'):
        """Log warning message"""
        self.log('warning', message, context, component)
    
    def error(self, message: str, context: Optional[Dict] = None, component: str = 'general', 
              exc_info: bool = True):
        """Log error message"""
        self.log('error', message, context, component, exc_info)
    
    def critical(self, message: str, context: Optional[Dict] = None, component: str = 'general',
                 exc_info: bool = True):
        """Log critical message"""
        self.log('critical', message, context, component, exc_info)
    
    def log_etl_start(self, importer_name: str, context: Optional[Dict] = None):
        """Log ETL process start"""
        self.info(f'ETL process started: {importer_name}', context, importer_name)
    
    def log_etl_end(self, importer_name: str, success: bool, stats: Optional[Dict] = None):
        """Log ETL process end"""
        context = stats or {}
        context['success'] = success
        
        if success:
            self.info(f'ETL process completed: {importer_name}', context, importer_name)
        else:
            self.error(f'ETL process failed: {importer_name}', context, importer_name)
    
    def log_api_call(self, endpoint: str, method: str, duration: float, 
                     status_code: int, component: str = 'api'):
        """Log API call with timing"""
        context = {
            'endpoint': endpoint,
            'method': method,
            'duration_ms': round(duration * 1000, 2),
            'status_code': status_code
        }
        
        if duration > 5.0:
            self.warning(f'Slow API call: {endpoint}', context, component)
        else:
            self.info(f'API call: {endpoint}', context, component)
    
    def log_database_query(self, query: str, duration: float, params: Optional[Dict] = None,
                          component: str = 'database'):
        """Log database query with timing"""
        context = {
            'query': query[:500] + ('...' if len(query) > 500 else ''),
            'duration_ms': round(duration * 1000, 2),
            'params': params
        }
        
        if duration > 1.0:
            self.warning('Slow database query', context, component)
        else:
            self.debug('Database query', context, component)
    
    def _send_alert(self, level: str, message: str, context: Optional[Dict], component: str):
        """Send alert for critical errors"""
        # Send to PHP logging endpoint
        if self.alert_config.get('php_endpoint'):
            self._send_to_php_endpoint(level, message, context, component)
        
        # Send email alert
        if self.alert_config.get('email'):
            self._send_email_alert(level, message, context, component)
        
        # Send Slack alert
        if self.alert_config.get('slack_webhook'):
            self._send_slack_alert(level, message, context, component)
    
    def _send_to_php_endpoint(self, level: str, message: str, context: Optional[Dict], 
                             component: str):
        """Send log to PHP comprehensive logging endpoint"""
        try:
            endpoint = self.alert_config.get('php_endpoint', 
                                            'https://market-mi.ru/api/comprehensive-error-logging.php')
            
            payload = {
                'level': level,
                'message': message,
                'context': context or {},
                'component': component,
                'source': 'python_etl',
                'traceId': self.trace_id
            }
            
            response = requests.post(
                endpoint,
                json=payload,
                headers={'X-Trace-ID': self.trace_id or ''},
                timeout=5
            )
            
            if not response.ok:
                print(f'Failed to send log to PHP endpoint: {response.status_code}')
        
        except Exception as e:
            print(f'Error sending log to PHP endpoint: {e}')
    
    def _send_email_alert(self, level: str, message: str, context: Optional[Dict], 
                         component: str):
        """Send email alert"""
        # Implementation depends on email service configuration
        pass
    
    def _send_slack_alert(self, level: str, message: str, context: Optional[Dict], 
                         component: str):
        """Send Slack alert"""
        try:
            webhook_url = self.alert_config.get('slack_webhook')
            if not webhook_url:
                return
            
            color_map = {
                'CRITICAL': 'danger',
                'ERROR': 'warning',
                'WARNING': 'warning'
            }
            
            payload = {
                'text': f'*[{level}]* {component}',
                'attachments': [{
                    'color': color_map.get(level.upper(), 'danger'),
                    'fields': [
                        {'title': 'Message', 'value': message, 'short': False},
                        {'title': 'Component', 'value': component, 'short': True},
                        {'title': 'Time', 'value': datetime.now().isoformat(), 'short': True}
                    ]
                }]
            }
            
            requests.post(webhook_url, json=payload, timeout=5)
        
        except Exception as e:
            print(f'Error sending Slack alert: {e}')
    
    def archive_old_logs(self, days: int = 30):
        """Archive logs older than specified days"""
        cutoff_date = datetime.now() - timedelta(days=days)
        
        # Find old log files
        log_pattern = str(self.log_base_path / 'etl' / '*.log.*')
        
        for log_file in glob.glob(log_pattern):
            file_path = Path(log_file)
            
            # Check file modification time
            if datetime.fromtimestamp(file_path.stat().st_mtime) < cutoff_date:
                # Compress and move to archive
                archive_path = self.log_base_path / 'archive' / f'{file_path.name}.gz'
                
                with open(file_path, 'rb') as f_in:
                    with gzip.open(archive_path, 'wb') as f_out:
                        f_out.writelines(f_in)
                
                # Remove original file
                file_path.unlink()
    
    def get_log_stats(self, component: Optional[str] = None, days: int = 7) -> Dict:
        """Get log statistics"""
        stats = {
            'total_logs': 0,
            'by_level': {},
            'by_component': {},
            'recent_errors': []
        }
        
        # Scan log files for the specified period
        for i in range(days):
            date = (datetime.now() - timedelta(days=i)).strftime('%Y-%m-%d')
            pattern = str(self.log_base_path / 'etl' / f'*-{date}.log')
            
            for log_file in glob.glob(pattern):
                self._process_log_file_stats(log_file, stats)
        
        return stats
    
    def _process_log_file_stats(self, log_file: str, stats: Dict):
        """Process log file for statistics"""
        try:
            with open(log_file, 'r') as f:
                for line in f:
                    try:
                        entry = json.loads(line)
                        stats['total_logs'] += 1
                        
                        # Count by level
                        level = entry.get('level', 'UNKNOWN')
                        stats['by_level'][level] = stats['by_level'].get(level, 0) + 1
                        
                        # Count by component
                        component = entry.get('component', 'unknown')
                        stats['by_component'][component] = stats['by_component'].get(component, 0) + 1
                        
                        # Collect recent errors
                        if level in ['ERROR', 'CRITICAL']:
                            stats['recent_errors'].append({
                                'timestamp': entry.get('timestamp'),
                                'level': level,
                                'component': component,
                                'message': entry.get('message')
                            })
                    
                    except json.JSONDecodeError:
                        continue
        
        except Exception as e:
            print(f'Error processing log file {log_file}: {e}')
        
        # Keep only last 50 errors
        if len(stats['recent_errors']) > 50:
            stats['recent_errors'] = stats['recent_errors'][-50:]


# Global logger instance
_global_logger = None


def get_logger(component: str = 'general', config: Optional[Dict] = None) -> ComprehensiveErrorLogger:
    """Get global logger instance"""
    global _global_logger
    
    if _global_logger is None:
        _global_logger = ComprehensiveErrorLogger(config)
    
    return _global_logger


# Example usage
if __name__ == '__main__':
    # Initialize logger
    logger = get_logger('test_component')
    
    # Test different log levels
    logger.info('Test info message', {'key': 'value'})
    logger.warning('Test warning message')
    logger.error('Test error message', {'error_code': 500})
    
    # Test ETL logging
    logger.log_etl_start('test_importer', {'source': 'test_api'})
    logger.log_etl_end('test_importer', True, {'records_processed': 100})
    
    # Get statistics
    stats = logger.get_log_stats()
    print(json.dumps(stats, indent=2))
