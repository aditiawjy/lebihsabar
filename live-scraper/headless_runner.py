#!/usr/bin/env python3
"""
Headless runner untuk BPVM live-scraper.

Menjalankan Chrome extension yang SUDAH ADA (chrome_extension/) di dalam Chromium
headless yang dikontrol Playwright -- jadi tidak perlu lagi membuka jendela Chrome
manual. Extension auto-start sendiri saat halaman target ter-load, lalu mengirim data
ke api_server.py (port 5000), Telegram, dan endpoint logging PHP seperti biasa.

Pakai:
    python headless_runner.py
    BPVM_URL=https://g943gp.bpvmr7u6.com/live python headless_runner.py   (override URL)

Catatan:
- Extension Manifest V3 hanya bisa di-load lewat persistent context + mode "--headless=new".
- Pastikan api_server.py sudah jalan lebih dulu (start_api_server.bat / start_headless.bat).
"""

import os
import sys
import time
import signal
from datetime import datetime
from pathlib import Path

from playwright.sync_api import sync_playwright

# --- Konfigurasi ---------------------------------------------------------
import shutil

BASE_DIR = Path(__file__).resolve().parent
EXTENSION_DIR = BASE_DIR / "chrome_extension"
PROFILE_ROOT = BASE_DIR / ".chrome-profile"
# Profil unik per proses: hindari lock "user-data-dir already in use" saat
# start/stop berulang (Chromium exit 21 kalau profil dipakai instance lain).
USER_DATA_DIR = PROFILE_ROOT / f"session-{os.getpid()}"
LOG_FILE = BASE_DIR / "headless_runner.log"


def cleanup_stale_profiles():
    """Hapus folder sesi lama (best-effort) supaya tidak menumpuk."""
    try:
        if not PROFILE_ROOT.exists():
            return
        for child in PROFILE_ROOT.iterdir():
            if child.is_dir() and child.name.startswith("session-") and child != USER_DATA_DIR:
                shutil.rmtree(child, ignore_errors=True)
    except Exception:
        pass

# Portable: kalau .playwright ada di folder ini dan PLAYWRIGHT_BROWSERS_PATH belum
# diset, arahkan ke sana supaya Chromium ikut pindah bareng folder (flash disk dll).
# Diperiksa isinya, bukan cuma foldernya: instalasi yang terputus meninggalkan
# .playwright kosong dan itu cukup untuk lolos exists(), lalu Playwright berhenti
# mencari di lokasi bawaannya dan gagal padahal Chromium sudah terpasang di sana.
_local_browsers = BASE_DIR / ".playwright"
if any(_local_browsers.glob("chromium-*")) and not os.environ.get("PLAYWRIGHT_BROWSERS_PATH"):
    os.environ["PLAYWRIGHT_BROWSERS_PATH"] = str(_local_browsers)

TARGET_HOST = "g943gp.bpvmr7u6.com"               # selaras constants.js
TARGET_URL = os.environ.get("BPVM_URL", f"https://{TARGET_HOST}/")

# Reload halaman target tiap 30 menit -> selaras TARGET_TAB_RELOAD_INTERVAL_MS.
RELOAD_INTERVAL_SEC = int(os.environ.get("BPVM_RELOAD_SEC", str(30 * 60)))
# Tiap berapa detik watchdog memeriksa kesehatan halaman.
WATCHDOG_INTERVAL_SEC = 30
PAGE_LOAD_TIMEOUT_MS = 60_000

_stop = False


def log(msg: str) -> None:
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except Exception:
        pass


def _handle_sigint(signum, frame):
    global _stop
    _stop = True
    log("Sinyal stop diterima, menutup...")


def is_target(url: str) -> bool:
    return isinstance(url, str) and TARGET_HOST in url


def wait_for_service_worker(context, timeout_sec=30):
    """Tunggu service worker background extension aktif (bukti extension hidup)."""
    deadline = time.time() + timeout_sec
    while time.time() < deadline:
        workers = context.service_workers
        if workers:
            return workers[0]
        try:
            return context.wait_for_event("serviceworker", timeout=5_000)
        except Exception:
            pass
    return None


def run():
    if not EXTENSION_DIR.exists():
        log(f"FATAL: folder extension tidak ditemukan: {EXTENSION_DIR}")
        sys.exit(1)

    cleanup_stale_profiles()
    USER_DATA_DIR.mkdir(parents=True, exist_ok=True)
    ext_path = str(EXTENSION_DIR)

    log("=" * 60)
    log("BPVM Headless Runner")
    log(f"Extension : {ext_path}")
    log(f"Target URL: {TARGET_URL}")
    log(f"Reload    : tiap {RELOAD_INTERVAL_SEC}s")
    log("=" * 60)

    with sync_playwright() as p:
        context = p.chromium.launch_persistent_context(
            user_data_dir=str(USER_DATA_DIR),
            headless=False,                       # extension butuh non-headless lama
            args=[
                "--headless=new",                 # tetap tanpa jendela terlihat
                f"--disable-extensions-except={ext_path}",
                f"--load-extension={ext_path}",
                "--no-sandbox",
                "--disable-gpu",
                "--mute-audio",
            ],
        )

        sw = wait_for_service_worker(context)
        if sw:
            log("Service worker extension AKTIF.")
        else:
            log("PERINGATAN: service worker belum terdeteksi, lanjut tetap mencoba.")

        # Gunakan satu page sebagai tab target. Extension auto-start saat URL match.
        page = context.pages[0] if context.pages else context.new_page()

        def load_target():
            try:
                log(f"Membuka halaman target...")
                page.goto(TARGET_URL, wait_until="domcontentloaded",
                          timeout=PAGE_LOAD_TIMEOUT_MS)
                log("Halaman target ter-load.")
                return True
            except Exception as e:
                log(f"Gagal load halaman: {e}")
                return False

        load_target()

        def responsive_sleep(total):
            end = time.time() + total
            while time.time() < end and not _stop:
                time.sleep(1)

        last_reload = time.time()
        while not _stop:
            responsive_sleep(WATCHDOG_INTERVAL_SEC)
            if _stop:
                break
            try:
                # Halaman masih di host target?
                if not is_target(page.url):
                    log(f"Halaman keluar dari target ({page.url}), memuat ulang.")
                    load_target()
                    last_reload = time.time()
                    continue

                # Reload terjadwal (selaras siklus extension).
                if time.time() - last_reload >= RELOAD_INTERVAL_SEC:
                    log("Reload terjadwal.")
                    page.reload(wait_until="domcontentloaded",
                                timeout=PAGE_LOAD_TIMEOUT_MS)
                    last_reload = time.time()
            except Exception as e:
                # Page mungkin crash/closed -> coba buat ulang.
                log(f"Watchdog error: {e}; mencoba pulih.")
                try:
                    page = context.new_page()
                    load_target()
                    last_reload = time.time()
                except Exception as e2:
                    log(f"Gagal pulih: {e2}")

        log("Menutup context.")
        try:
            context.close()
        except Exception:
            pass

    # Hapus profil milik sesi ini supaya tidak menumpuk.
    shutil.rmtree(USER_DATA_DIR, ignore_errors=True)


if __name__ == "__main__":
    signal.signal(signal.SIGINT, _handle_sigint)
    signal.signal(signal.SIGTERM, _handle_sigint)
    # Windows: api_server menghentikan proses ini via CTRL_BREAK_EVENT -> SIGBREAK.
    if hasattr(signal, "SIGBREAK"):
        signal.signal(signal.SIGBREAK, _handle_sigint)
    run()
