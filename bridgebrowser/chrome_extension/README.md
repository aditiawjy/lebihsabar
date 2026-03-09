# Live Data Viewer Extension

Ekstensi Chrome sederhana untuk mengakses live data melalui HTTP Server.

## Fitur

### Live Data via HTTP Server
- Mengakses data melalui API Server Python (port 5000)
- Fallback ke akses HTTP langsung jika API offline
- Auto-refresh setiap 3 detik
- Format data yang user-friendly

### API Server Status
- Memeriksa status API endpoints
- Menampilkan informasi server
- Health check untuk monitoring

## Instalasi

1. Buka Chrome dan navigasi ke `chrome://extensions/`
2. Aktifkan "Developer mode" di pojok kanan atas
3. Klik "Load unpacked" dan pilih folder `chrome_extension`
4. Extension akan muncul di toolbar Chrome

## Cara Penggunaan

1. Pastikan API Server berjalan di port 5000:
   ```
   python api_server.py
   ```

2. Pastikan XAMPP Apache berjalan di port 80 (sebagai fallback)

3. Klik icon extension di toolbar Chrome

4. Gunakan tombol:
   - **Load Live Data:** Memuat data live
   - **Refresh Data:** Refresh manual
   - **Check API Status:** Cek status server

## Akses Data

### Primary: API Server (Port 5000)
- URL: `http://localhost:5000/api/live-data`
- Format: JSON dengan metadata
- Update interval: Real-time

### Fallback: Direct HTTP (Port 80)
- URL: `http://localhost/bridgebrowser/live_data.json`
- Format: Raw JSON file
- Update interval: Sesuai scraper

## Troubleshooting

- **API Server offline:** Extension akan otomatis fallback ke HTTP langsung
- **Data tidak update:** Periksa apakah scraper berjalan
- **Extension error:** Reload extension di `chrome://extensions/`
- **CORS error:** Pastikan server mendukung CORS

## Keunggulan HTTP Server Access

1. **Keamanan:** Sesuai kebijakan browser modern
2. **Reliability:** Fallback mechanism tersedia
3. **Performance:** Cache control dan optimasi
4. **Scalability:** Multiple clients bisa akses bersamaan
5. **Monitoring:** Status endpoints untuk debugging