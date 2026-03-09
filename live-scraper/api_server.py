#!/usr/bin/env python3
"""
API Server untuk Live Scraper
Menerima data dari Chrome Extension dan kirim ke Telegram
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
from datetime import datetime
import json
from telegram_notifier import TelegramNotifier

app = Flask(__name__)
CORS(app)

# Initialize Telegram notifier
notifier = TelegramNotifier()

# Store last matches
last_matches = []

@app.route('/api/live-data', methods=['POST'])
def receive_live_data():
    """Terima data dari Chrome Extension"""
    try:
        data = request.get_json() or {}
        matches = data.get('matches', [])
        
        # Simpan data
        global last_matches
        last_matches = matches
        
        # Kirim notifikasi untuk match baru atau update
        for match in matches:
            # Kirim update biasa
            notifier.send_match_update(match)
            
            # Cek dan kirim alert khusus untuk 0-0 di babak pertama
            notifier.check_and_alert_first_half_zero_zero(match)
        
        return jsonify({
            'success': True,
            'message': f'Received {len(matches)} matches',
            'timestamp': datetime.now().isoformat()
        })
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/api/live-data', methods=['GET'])
def get_live_data():
    """Ambil data match terakhir"""
    return jsonify({
        'matches': last_matches,
        'count': len(last_matches),
        'timestamp': datetime.now().isoformat()
    })

@app.route('/api/test-telegram', methods=['POST'])
def test_telegram():
    """Test kirim pesan ke Telegram"""
    success = notifier.send_test_message()
    return jsonify({
        'success': success,
        'message': 'Test message sent' if success else 'Failed to send'
    })

@app.route('/api/status', methods=['GET'])
def get_status():
    """Cek status server"""
    return jsonify({
        'status': 'online',
        'timestamp': datetime.now().isoformat(),
        'matches_count': len(last_matches)
    })

if __name__ == '__main__':
    print("=" * 60)
    print("Live Scraper API Server")
    print("=" * 60)
    print("\nEndpoints:")
    print("  POST /api/live-data     - Kirim data dari extension")
    print("  GET  /api/live-data     - Ambil data match")
    print("  POST /api/test-telegram - Test Telegram")
    print("  GET  /api/status        - Cek status")
    print("\nServer running on http://127.0.0.1:5000")
    print("=" * 60)
    
    # Test Telegram saat startup
    print("\nTesting Telegram...")
    notifier.send_test_message()
    
    app.run(host='127.0.0.1', port=5000, debug=False)
