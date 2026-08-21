import time
import json
import requests
import win32print
import hashlib
import os
import datetime

# Load config
config_path = os.path.join(os.path.dirname(__file__), 'config.json')
with open(config_path, 'r') as f:
    CONFIG = json.load(f)

SERVER_URL = CONFIG['server_url']
MACHINE_ID = CONFIG['machine_id']
PRINTER_NAME = CONFIG['printer_name']
POLL_INTERVAL = CONFIG['poll_interval']
LOG_FILE = "agent.log"

# --- ARCHITECTURE POLICY ---
# SERVER (Laravel) = Single source of truth for queue authority and stale job filtering.
# AGENT (Python)  = Dumb execution worker. Responsibility: Claim -> Verify -> Print -> Notify.
# ---------------------------

def log(msg):
    """Logs message to console and file with timestamp (Local Time for Log Visibility)."""
    # We use local time for the log prefix to match the operator's clock
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
        log("[QUEUE] Running startup recovery...")
        resp = requests.post(f"{SERVER_URL}/print-jobs/recover", timeout=10)
        if resp.status_code == 200:
            count = resp.json().get('recovered_count', 0)
            log(f"[QUEUE] Recovery completed: {count} jobs reset.")
        else:
            log(f"[QUEUE] Recovery failed: {resp.status_code}")
    except Exception as e:
        log(f"[QUEUE] Recovery error: {e}")

def print_raw(data):
    """Sends raw bytes to the Windows print spooler."""
    try:
        log(f"Opening printer: {PRINTER_NAME}")
        hPrinter = win32print.OpenPrinter(PRINTER_NAME)
        try:
            log(f"Sending RAW TSPL to printer...")
            hJob = win32print.StartDocPrinter(hPrinter, 1, ("TSC Industrial Print", None, "RAW"))
            win32print.StartPagePrinter(hPrinter)
            # Use cp437 for maximum thermal printer compatibility
            win32print.WritePrinter(hPrinter, data.encode('cp437'))
            win32print.EndPagePrinter(hPrinter)
            win32print.EndDocPrinter(hPrinter)
            log(f"RAW spool completed.")
        finally:
            win32print.ClosePrinter(hPrinter)
        return True
    except Exception as e:
        return str(e)

def process_jobs():
    log(f"Agent started. Machine: {MACHINE_ID}, Printer: {PRINTER_NAME}")
    log(f"Polling URL: {SERVER_URL}")

    # Validate Printer Existence
    try:
        printers = [p[2] for p in win32print.EnumPrinters(2)]
        if PRINTER_NAME not in printers:
            log(f"CRITICAL ERROR: Printer '{PRINTER_NAME}' not found on this machine.")
            log(f"Available printers: {', '.join(printers)}")
            exit(1)
    except Exception as e:
        log(f"Failed to enumerate printers: {e}")
        exit(1)

    # Initial Recovery
    run_recovery()
    
    while True:
        try:
            # 1. Claim job
            resp = requests.post(f"{SERVER_URL}/print-jobs/claim", json={
                "machine_id": MACHINE_ID,
                "printer_name": PRINTER_NAME
            }, timeout=10)

            if resp.status_code == 200:
                job = resp.json()
                job_id = job['id']
                payload = job['payload_tspl']
                expected_hash = job['payload_hash']

                log(f"Job claimed: ID {job_id}")

                # 2. Verify integrity
                actual_hash = hashlib.sha256(payload.encode('utf-8')).hexdigest()
                if actual_hash != expected_hash:
                    error_msg = f"Hash mismatch. Expected {expected_hash}, got {actual_hash}"
                    requests.post(f"{SERVER_URL}/print-jobs/{job_id}/failed", json={"error": error_msg})
                    log(error_msg)
                    continue

                # 3. Execute Print
                result = print_raw(payload)

                if result is True:
                    # 4. Notify completion
                    requests.post(f"{SERVER_URL}/print-jobs/{job_id}/complete")
                    log(f"Job completed: ID {job_id}")
                    log(f"Job {job_id} printed successfully.")
                else:
                    # Notify failure
                    requests.post(f"{SERVER_URL}/print-jobs/{job_id}/failed", json={"error": result})
                    log(f"Job {job_id} failed: {result}")

            elif resp.status_code == 204:
                # No jobs pending
                pass
            else:
                log(f"Server error: {resp.status_code}. Response: {resp.text[:100]}")

        except Exception as e:
            log(f"Poll error: {e}")

        time.sleep(POLL_INTERVAL)

if __name__ == "__main__":
    process_jobs()
