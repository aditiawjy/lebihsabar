#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
API Server untuk Chrome Extension dengan Integrated Scraper
Mengambil data langsung dari 1xBet dan menyajikan melalui REST API
"""

import json
import time
import requests
import os
import re
from flask import Flask, jsonify, request
from flask_cors import CORS
from datetime import datetime
import threading
import logging
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from webdriver_manager.chrome import ChromeDriverManager
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app)  # Enable CORS untuk Chrome Extension

# Global variables untuk menyimpan data
cached_data = {}
last_update = None
update_interval = 0.5  # Update setiap 0.5 detik untuk mengurangi beban

# Global driver instance
driver = None

def setup_chrome_driver():
    """Setup Chrome WebDriver dengan konfigurasi optimal untuk scraping"""
    try:
        chrome_options = Options()
        chrome_options.add_argument('--headless')  # Jalankan tanpa GUI
        chrome_options.add_argument('--no-sandbox')
        chrome_options.add_argument('--disable-dev-shm-usage')
        chrome_options.add_argument('--disable-gpu')
        chrome_options.add_argument('--window-size=1920,1080')
        chrome_options.add_argument('--disable-blink-features=AutomationControlled')
        chrome_options.add_experimental_option("excludeSwitches", ["enable-automation"])
        chrome_options.add_experimental_option('useAutomationExtension', False)
        
        # Anti-detection: Random user agent
        user_agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36'
        ]
        import random
        chrome_options.add_argument(f'--user-agent={random.choice(user_agents)}')
        
        # Setup driver dengan webdriver-manager
        service = Service(ChromeDriverManager().install())
        driver = webdriver.Chrome(service=service, options=chrome_options)
        
        # Hapus properti webdriver untuk menghindari deteksi
        driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
        
        return driver
        
    except Exception as e:
        logger.error(f"Gagal setup Chrome driver: {e}")
        return None

def extract_matches_from_text(text, is_live=False):
    """Ekstrak informasi match dari teks elemen"""
    matches = []
    
    try:
        lines = [line.strip() for line in text.split('\n') if line.strip()]
        logger.info(f"[DEBUG] extract_matches_from_text: Processing {len(lines)} lines, is_live={is_live}")
        
        i = 0
        while i < len(lines):
            line = lines[i]
            
            # Pattern: Liga + waktu/status + tim vs tim + score + odds
            if any(keyword in line.upper() for keyword in ['CHAMPIONS LEAGUE', 'BUNDESLIGA', 'SERIE A', 'LA LIGA', 'PREMIER LEAGUE', 'V-SOCCER']):
                logger.info(f"[DEBUG] Found league match: {line}")
                match_data = {'league': line}
                logger.info(f"[DEBUG] Starting processing for league: {line}")
                
                # Cari waktu dengan pattern yang lebih komprehensif
                time_str = ''
                k = i + 1
                logger.info(f"[DEBUG] Starting time search for league: {line}")
                while k < len(lines) and k < i + 8:  # Perluas pencarian
                    line_check = lines[k]
                    logger.info(f"[DEBUG] Checking line {k} for time: '{line_check}'")
                    # Pattern waktu yang lebih detail + gabungkan menit jika ada pada baris berikutnya
                    if re.match(r'^\d+H$', line_check):
                        candidate_half = line_check
                        minute_found = None
                        m = k + 1
                        # Cari menit dalam beberapa baris ke depan (lewati token non-informatif)
                        while m < len(lines) and m < i + 8:
                            nxt = lines[m].strip()
                            if re.match(r'^\d+\s*[\'’‘]$', nxt) or re.match(r'^\d+\+\d+[\'’‘]$', nxt):
                                minute_found = nxt
                                break
                            # Lewati token yang tidak relevan untuk kombinasi menit
                            if nxt in ['LIVE', 'Time', 'HT', 'FT'] or re.match(r'^\d{1,2}:\d{2}$', nxt):
                                m += 1
                                continue
                            m += 1
                        if minute_found:
                            time_str = f"{candidate_half} {minute_found}"
                            logger.info(f"Found time: {time_str} for league: {line}")
                        else:
                            time_str = candidate_half
                            logger.info(f"Found time: {time_str} for league: {line}")
                        break
                    elif re.match(r'^\d+H \d+[\'’‘]$', line_check):
                        time_str = line_check
                        logger.info(f"Found time: {time_str} for league: {line}")
                        break
                    elif re.match(r'^\d+[\'’‘]$', line_check):
                        time_str = line_check
                        logger.info(f"Found time: {time_str} for league: {line}")
                        break
                    elif re.match(r'^\d+\+\d+[\'’‘]$', line_check):  # Format "90+5'"
                        time_str = line_check
                        logger.info(f"Found time: {time_str} for league: {line}")
                        break
                    elif re.match(r'^\d{1,2}:\d{2}$', line_check):  # Format "20:27"
                        time_str = line_check
                        logger.info(f"Found time: {time_str} for league: {line}")
                        break
                    elif line_check in ['Time', 'LIVE', 'HT', 'FT']:
                        time_str = line_check
                        logger.info(f"Found time: {time_str} for league: {line}")
                        break
                    k += 1
                logger.info(f"[DEBUG] Time search completed. Found: '{time_str}'")
                if time_str:
                    match_data['time'] = time_str
                else:
                    # Log jika tidak menemukan waktu untuk debugging
                    logger.warning(f"No time found for league: {line}. Next lines: {lines[i+1:i+8]}")
                
                logger.info(f"[DEBUG] About to search for teams for league: {line}")
                # Cari tim-tim
                try:
                    team_count = 0
                    j = i + 1
                    logger.info(f"[DEBUG] Searching for teams starting from line {j}")
                    while j < len(lines) and team_count < 2:
                        logger.info(f"[DEBUG] Checking line {j}: '{lines[j]}' (length: {len(lines[j])})") 
                        if '[V]' in lines[j] and len(lines[j]) < 80:
                            if team_count == 0:
                                match_data['team1'] = lines[j]
                                logger.info(f"[DEBUG] Found team1: {lines[j]}")
                            else:
                                match_data['team2'] = lines[j]
                                logger.info(f"[DEBUG] Found team2: {lines[j]}")
                            team_count += 1
                        j += 1
                    logger.info(f"[DEBUG] Total teams found: {team_count}")
                except Exception as e:
                    logger.error(f"[DEBUG] Error during team search: {e}")
                    team_count = 0
                
                # Cari score jika live
                if is_live:
                    for k in range(i + 1, min(j + 5, len(lines))):
                        if re.match(r'\d+\s*:\s*\d+', lines[k]):
                            match_data['score'] = lines[k]
                            break
                
                # Cari odds
                odds = []
                for k in range(i + 1, min(j + 10, len(lines))):
                    if re.match(r'^\d+\.\d+$', lines[k]) and len(odds) < 3:
                        odds.append(lines[k])
                
                if odds:
                    match_data['odds'] = odds
                else:
                    match_data['odds'] = ['1.50', '3.00', '2.50']  # Default odds
                
                match_data['status'] = 'Live' if is_live else 'Upcoming'
                
                if 'team1' in match_data and 'team2' in match_data:
                    matches.append(match_data)
                    logger.info(f"[DEBUG] Match added: {match_data['league']} - {match_data['team1']} vs {match_data['team2']}")
                else:
                    logger.warning(f"[DEBUG] Match not added - missing teams. Data: {match_data}")
                
                i = j
            else:
                i += 1
                
        logger.info(f"[DEBUG] extract_matches_from_text: Extracted {len(matches)} matches total")
        return matches[:10]  # Batasi 10 matches untuk performa
        
    except Exception as e:
        logger.error(f"Error parsing matches: {e}")
        return []

def scrape_live_data():
    """Scrape data langsung dari 1xBet"""
    global driver
    
    try:
        if not driver:
            driver = setup_chrome_driver()
            if not driver:
                return None
        
        url = "https://prod20191-101527338.1x2aaa.com/en/asian-view/today/Virtual-Soccer"
        driver.get(url)
        
        # Tunggu elemen target muncul dengan timeout singkat agar cepat namun andal
        target_selector = ".eventlist_asia_fe_EventListSection_container"
        try:
            WebDriverWait(driver, 3).until(
                EC.presence_of_all_elements_located((By.CSS_SELECTOR, target_selector))
            )
        except Exception:
            # fallback: lanjutkan cek dengan find_elements walaupun mungkin kosong
            logger.warning("Timeout waiting for elements, continuing with fallback")
            pass
        target_elements = driver.find_elements(By.CSS_SELECTOR, target_selector)
        
        if not target_elements:
            logger.warning("Tidak ditemukan elemen target, menggunakan data fallback")
            # Return fallback data dari JSON jika ada
            json_file_path = os.path.join(os.path.dirname(__file__), 'live_data.json')
            if os.path.exists(json_file_path):
                try:
                    with open(json_file_path, 'r', encoding='utf-8') as f:
                        fallback_data = json.load(f)
                    logger.info("Using fallback data from JSON")
                    return fallback_data
                except Exception as e:
                    logger.error(f"Error loading fallback data: {e}")
            return None
            
        live_matches = []
        prematch_matches = []
        total_child_elements = 0
        total_betting_elements = 0
        
        # Batasi jumlah elemen yang diproses untuk mengurangi timeout
        max_elements = min(len(target_elements), 5)  # Maksimal 5 elemen
        logger.info(f"[DEBUG] Starting loop for {max_elements}/{len(target_elements)} target elements")
        
        for i, element in enumerate(target_elements[:max_elements]):
            logger.info(f"[DEBUG] Processing element {i+1}/{max_elements}")
            try:
                # Skip child elements counting untuk performa
                total_child_elements += 1  # Simplified counting
                
                # Simplified betting elements detection
                total_betting_elements += 1
                
                element_text = element.text.strip()
                is_live = "LIVE" in element_text and "PRE-MATCH" not in element_text
                
                logger.info(f"[DEBUG] Element {i+1}: is_live={is_live}, text_length={len(element_text)}")
                
                # Simplified logging untuk performa
                logger.info(f"[DEBUG] Processing element {i+1}: is_live={is_live}, text_length={len(element_text)}")
                
                matches = extract_matches_from_text(element_text, is_live)
                logger.info(f"[DEBUG] Element {i+1}: extracted {len(matches)} matches")
                
                if is_live:
                    live_matches.extend(matches)
                    logger.info(f"[DEBUG] Total live matches so far: {len(live_matches)}")
                else:
                    prematch_matches.extend(matches)
                    logger.info(f"[DEBUG] Total prematch matches so far: {len(prematch_matches)}")
                    
            except Exception as e:
                logger.error(f"Error processing element {i+1}: {e}")
                continue
        
        # Hitung total odds
        all_odds = driver.find_elements(By.CSS_SELECTOR, "[class*='odd']")
        odds_count = len([odd for odd in all_odds if re.match(r'^\d+\.\d+$', odd.text.strip())])
        
        result = {
            "timestamp": datetime.now().isoformat(),
            "target_elements": len(target_elements),
            "child_elements": total_child_elements,
            "betting_elements": total_betting_elements,
            "odds_found": odds_count,
            "live_matches": live_matches[:10],  # Batasi 10 live matches
            "prematch_matches": prematch_matches[:20],  # Batasi 20 prematch
            "status": "success"
        }
        
        # Simpan ke file JSON juga
        save_data_to_json(result)
        
        return result
        
    except Exception as e:
        logger.error(f"Error scraping data: {e}")
        return None

def save_data_to_json(data, filename="live_data.json"):
    """Simpan data ke file JSON"""
    try:
        filepath = os.path.join(os.path.dirname(__file__), filename)
        with open(filepath, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        return True
    except Exception as e:
        logger.error(f"Gagal menyimpan data: {e}")
        return False

def fetch_live_data():
    """Mengambil data dengan scraping langsung atau fallback ke file JSON"""
    try:
        # Coba scraping langsung
        scraped_data = scrape_live_data()
        if scraped_data:
            return scraped_data
        
        # Fallback ke file JSON lokal jika scraping gagal
        json_file_path = os.path.join(os.path.dirname(__file__), 'live_data.json')
        
        if os.path.exists(json_file_path):
            with open(json_file_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            logger.info(f"Loaded data from JSON file: {len(data.get('live_matches', []))} live matches")
            return data
        else:
            logger.warning("live_data.json file not found")
            return None
        
    except Exception as e:
        logger.error(f"Error fetching data: {e}")
        return None

def update_data_continuously():
    """Background thread untuk update data secara kontinyu"""
    global cached_data, last_update
    
    while True:
        try:
            new_data = fetch_live_data()
            
            if new_data:
                cached_data = new_data
                last_update = datetime.now().isoformat()
                logger.info(f"Data updated: {len(cached_data.get('live_matches', []))} live matches")
            
            time.sleep(update_interval)
            
        except Exception as e:
            logger.error(f"Error in update loop: {e}")
            time.sleep(update_interval)

@app.route('/api/live-data', methods=['GET'])
def get_live_data():
    """Endpoint untuk mendapatkan semua data live"""
    try:
        if not cached_data:
            return jsonify({
                'error': 'No data available',
                'message': 'Data belum tersedia atau server offline'
            }), 503
        
        # Buat salinan data dan kompensasi delay waktu pada live_matches
        data_copy = dict(cached_data)
        live_matches_copy = list(data_copy.get('live_matches', []))
        try:
            elapsed_seconds = 0
            if last_update:
                elapsed_seconds = (datetime.now() - datetime.fromisoformat(last_update)).total_seconds()
            if elapsed_seconds > 0 and live_matches_copy:
                adjusted = []
                for m in live_matches_copy:
                    m2 = dict(m)
                    t = str(m2.get('time') or '')
                    mtch = re.match(r'^\s*([12]H)\s+(\d{1,3})\s*(?:[\'’])?\s*$', t)
                    if mtch:
                        half = mtch.group(1)
                        mins = int(mtch.group(2))
                        add = int(elapsed_seconds // 60)
                        if add > 0:
                            m2['time'] = f"{half} {mins + add}'"
                    adjusted.append(m2)
                live_matches_copy = adjusted
        except Exception:
            pass
        data_copy['live_matches'] = live_matches_copy
        
        response_data = {
            'data': data_copy,
            'last_update': last_update,
            'server_time': datetime.now().isoformat(),
            'status': 'success'
        }
        
        return jsonify(response_data)
        
    except Exception as e:
        logger.error(f"Error in get_live_data: {e}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

@app.route('/api/live-matches', methods=['GET'])
def get_live_matches():
    """Endpoint khusus untuk live matches saja"""
    try:
        if not cached_data:
            return jsonify({
                'error': 'No data available',
                'live_matches': []
            }), 503
        
        live_matches = cached_data.get('live_matches', [])
        
        # Kompensasi delay waktu untuk menua menit berdasarkan elapsed time sejak last_update
        try:
            elapsed_seconds = 0
            if last_update:
                elapsed_seconds = (datetime.now() - datetime.fromisoformat(last_update)).total_seconds()
            if elapsed_seconds > 0 and live_matches:
                adjusted = []
                for m in live_matches:
                    m2 = dict(m)
                    t = str(m2.get('time') or '')
                    mtch = re.match(r'^\s*([12]H)\s+(\d{1,3})\s*(?:[\'’])?\s*$', t)
                    if mtch:
                        half = mtch.group(1)
                        mins = int(mtch.group(2))
                        add = int(elapsed_seconds // 60)
                        if add > 0:
                            m2['time'] = f"{half} {mins + add}'"
                    adjusted.append(m2)
                live_matches = adjusted
        except Exception:
            pass
        
        response_data = {
            'live_matches': live_matches,
            'count': len(live_matches),
            'last_update': last_update,
            'server_time': datetime.now().isoformat(),
            'status': 'success'
        }
        
        return jsonify(response_data)
        
    except Exception as e:
        logger.error(f"Error in get_live_matches: {e}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

@app.route('/api/prematch-matches', methods=['GET'])
def get_prematch_matches():
    """Endpoint khusus untuk prematch matches saja"""
    try:
        if not cached_data:
            return jsonify({
                'error': 'No data available',
                'prematch_matches': []
            }), 503
        
        prematch_matches = cached_data.get('prematch_matches', [])
        
        response_data = {
            'prematch_matches': prematch_matches,
            'count': len(prematch_matches),
            'last_update': last_update,
            'server_time': datetime.now().isoformat(),
            'status': 'success'
        }
        
        return jsonify(response_data)
        
    except Exception as e:
        logger.error(f"Error in get_prematch_matches: {e}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

@app.route('/api/status', methods=['GET'])
def get_status():
    """Endpoint untuk status server dan data"""
    try:
        status_data = {
            'server_status': 'running',
            'data_available': bool(cached_data),
            'last_update': last_update,
            'server_time': datetime.now().isoformat(),
            'update_interval': update_interval,
            'data_stats': {
                'live_matches': len(cached_data.get('live_matches', [])),
                'prematch_matches': len(cached_data.get('prematch_matches', [])),
                'total_elements': cached_data.get('child_elements', 0),
                'odds_found': cached_data.get('odds_found', 0)
            } if cached_data else {}
        }
        
        return jsonify(status_data)
        
    except Exception as e:
        logger.error(f"Error in get_status: {e}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({
        'status': 'healthy',
        'timestamp': datetime.now().isoformat()
    })

@app.route('/', methods=['GET'])
def index():
    """Root endpoint dengan informasi API"""
    return jsonify({
        'message': 'Live Data API Server',
        'version': '1.0.0',
        'endpoints': {
            '/api/live-data': 'Get all live data',
            '/api/live-matches': 'Get live matches only',
            '/api/prematch-matches': 'Get prematch matches only',
            '/api/status': 'Get server status',
            '/api/health': 'Health check'
        },
        'server_time': datetime.now().isoformat()
    })

if __name__ == '__main__':
    # Start background thread untuk update data
    update_thread = threading.Thread(target=update_data_continuously, daemon=True)
    update_thread.start()
    
    logger.info("Starting API Server...")
    logger.info("Endpoints available:")
    logger.info("  - http://localhost:5000/api/live-data")
    logger.info("  - http://localhost:5000/api/live-matches")
    logger.info("  - http://localhost:5000/api/prematch-matches")
    logger.info("  - http://localhost:5000/api/status")
    
    # Run Flask server
    app.run(
        host='0.0.0.0',
        port=5000,
        debug=False,
        threaded=True
    )