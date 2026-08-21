import time
import json
import requests
import win32print
import hashlib
import os

# Load config
config_path = os.path.join(os.path.dirname(__file__), 'config.json')
with open(config_path, 'r') as f:
    CONFIG = json.load(f)

SERVER_URL = CONFIG['server_url']
MACHINE_ID = CONFIG['machine_id']
PRINTER_NAME = CONFIG['printer_name']
POLL_INTERVAL = CONFIG['poll_interval']
API_TOKEN = CONFIG['api_token']
LOG_FILE = "agent.log"

# Setup Headers
HEADERS = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Accept": "application/json"
}

def log(msg):
    """Logs message to console and file with timestamp (Local Time for Log Visibility)."""
    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{timestamp}] {msg}"
    print(line)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception as e:
        print(f"Failed to write to log file: {e}")

def run_recovery():
    """Trigger server-side recovery for stale processing jobs."""
    try:
        log("[QUEUE] Menjalankan startup recovery...")
        resp = requests.post(f"{SERVER_URL}/print-jobs/recover", headers=HEADERS, timeout=10)
        if resp.status_code == 200:
            count = resp.json().get('recovered_count', 0)
            log(f"[QUEUE] Startup recovery selesai: {count} job di-reset ke pending.")
        else:
            log(f"[QUEUE] Startup recovery gagal: HTTP {resp.status_code} - {resp.text[:100]}")
    except Exception as e:
        log(f"[QUEUE] Startup recovery error: {e}")

def print_raw(data):
    """Mengirim byte perintah TSPL mentah langsung ke Windows Print Spooler."""
    try:
        log(f"Membuka printer: {PRINTER_NAME}")
        hPrinter = win32print.OpenPrinter(PRINTER_NAME)
        try:
            log(f"Mengirim RAW TSPL ke printer...")
            hJob = win32print.StartDocPrinter(hPrinter, 1, ("TSC Industrial Print", None, "RAW"))
            win32print.StartPagePrinter(hPrinter)
            
            # Gunakan encoding cp437 (Code Page 437) untuk kompatibilitas printer thermal maksimal
            win32print.WritePrinter(hPrinter, data.encode('cp437'))
            
            win32print.EndPagePrinter(hPrinter)
            win32print.EndDocPrinter(hPrinter)
            log(f"Spooling RAW berhasil diselesaikan.")
        finally:
            win32print.ClosePrinter(hPrinter)
        return True
    except Exception as e:
        return str(e)

def process_jobs():
    log(f"Agent Aktif. Machine: {MACHINE_ID}, Printer Target: {PRINTER_NAME}")
    log(f"URL Polling Server: {SERVER_URL}")

    # Validasi eksistensi printer di sistem operasi Windows sebelum mulai loop
    try:
        printers = [p[2] for p in win32print.EnumPrinters(2)]
        if PRINTER_NAME not in printers:
            log(f"ERROR CRITICAL: Printer '{PRINTER_NAME}' tidak ditemukan di Windows ini.")
            log(f"Daftar printer terdeteksi: {', '.join(printers)}")
            exit(1)
    except Exception as e:
        log(f"Gagal mendeteksi printer terpasang: {e}")
        exit(1)

    # Jalankan recovery job macet saat startup
    run_recovery()
    
    while True:
        try:
            # 1. Claim job antrean dari server
            resp = requests.post(f"{SERVER_URL}/print-jobs/claim", json={
                "machine_id": MACHINE_ID,
                "printer_name": PRINTER_NAME
            }, headers=HEADERS, timeout=10)

            if resp.status_code == 200:
                job = resp.json()
                job_id = job['id']
                payload = job['payload_tspl']
                expected_hash = job['payload_hash']

                log(f"Job berhasil diklaim: ID {job_id}")

                # 2. Verifikasi Integritas Data (SHA256)
                actual_hash = hashlib.sha256(payload.encode('utf-8')).hexdigest()
                if actual_hash != expected_hash:
                    error_msg = f"Integritas gagal (Hash mismatch). Harap cek jaringan. ID: {job_id}"
                    requests.post(f"{SERVER_URL}/print-jobs/{job_id}/failed", json={"error": error_msg}, headers=HEADERS, timeout=10)
                    log(error_msg)
                    continue

                # 3. Kirim ke printer
                result = print_raw(payload)

                if result is True:
                    # 4. Laporkan sukses
                    requests.post(f"{SERVER_URL}/print-jobs/{job_id}/complete", headers=HEADERS, timeout=10)
                    log(f"Job {job_id} berhasil dicetak.")
                else:
                    # Laporkan gagal beserta log errornya
                    requests.post(f"{SERVER_URL}/print-jobs/{job_id}/failed", json={"error": result}, headers=HEADERS, timeout=10)
                    log(f"Job {job_id} GAGAL dicetak: {result}")

            elif resp.status_code == 204:
                # HTTP 204: Tidak ada antrean pending
                pass
            elif resp.status_code == 401:
                log("Error Server: HTTP 401 Unauthorized. Harap cek token API di config.json.")
                time.sleep(10) # Backoff if unauthorized
            else:
                log(f"Error Server: HTTP {resp.status_code}. Response: {resp.text[:100]}")

        except Exception as e:
            log(f"Koneksi/Polling Error: {e}")

        time.sleep(POLL_INTERVAL)

if __name__ == "__main__":
    process_jobs()
