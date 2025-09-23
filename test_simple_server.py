#!/usr/bin/env python3
"""
Простой тестовый HTTP сервер для проверки работы.
"""

import json
from http.server import HTTPServer, BaseHTTPRequestHandler

class TestHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/api/health':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            
            response = {
                'status': 'healthy',
                'timestamp': '2025-09-23T14:50:00Z',
                'components': {
                    'database': True,
                    'recommender': True,
                    'alert_manager': True,
                    'reporting_engine': True
                }
            }
            
            self.wfile.write(json.dumps(response, ensure_ascii=False).encode('utf-8'))
        else:
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.end_headers()
            
            html = """
            <!DOCTYPE html>
            <html>
            <head>
                <title>Тестовый сервер</title>
                <meta charset="utf-8">
            </head>
            <body>
                <h1>🧪 Тестовый сервер работает!</h1>
                <p><a href="/api/health">Проверить здоровье API</a></p>
            </body>
            </html>
            """
            
            self.wfile.write(html.encode('utf-8'))

def main():
    server = HTTPServer(('localhost', 8001), TestHandler)
    print("🧪 Тестовый сервер запущен на http://localhost:8001")
    print("   Проверка здоровья: http://localhost:8001/api/health")
    print("⏹️  Для остановки нажмите Ctrl+C")
    
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n🛑 Сервер остановлен")
        server.shutdown()

if __name__ == '__main__':
    main()