import sys
import json
import os

# Safe import checking for pywin32 dependency
try:
    import win32print
except ModuleNotFoundError:
    print("=" * 60)
    print("ERROR: Dependensi 'pywin32' (win32print) belum terpasang!")
    print("Silakan jalankan 'install-dependencies.bat' terlebih dahulu")
    print("untuk memasang dependensi offline secara otomatis.")
    print("=" * 60)
    input("\nTekan Enter untuk keluar...")
    sys.exit(1)

# Default fallback printer
printer_name = "TSC TE244"

# Load printer name from config.json
config_path = os.path.join(os.path.dirname(__file__), 'config.json')
if os.path.exists(config_path):
    try:
        with open(config_path, 'r') as f:
            config = json.load(f)
            printer_name = config.get('printer_name', printer_name)
    except Exception as e:
        print(f"Peringatan: Gagal membaca config.json, menggunakan nama printer default. Error: {e}")

# Sample TSPL payload for 50x90 mm portrait label (matching Kanban PPIC size)
tspl = """SIZE 50 mm, 90 mm
GAP 3 mm, 0
DIRECTION 1,0
REFERENCE 0,0
CLS
TEXT 200,100,"3",0,1,1,2,"TEST PRINT OK"
TEXT 200,200,"3",0,1,1,2,"PRINTER: TSC TE244"
TEXT 200,300,"2",0,1,1,2,"RAW TSPL DIRECT PRINTING"
BARCODE 60,400,"128",100,0,0,2,4,"1234567890"
PRINT 1
"""

print(f"Target Printer: {printer_name}")
print("Mengirimkan RAW TSPL data...")

try:
    hPrinter = win32print.OpenPrinter(printer_name)
    try:
        hJob = win32print.StartDocPrinter(hPrinter, 1, ("TSC Test Print", None, "RAW"))
        win32print.StartPagePrinter(hPrinter)
        
        # Use cp437 encoding for maximum thermal printer compatibility
        win32print.WritePrinter(hPrinter, tspl.encode('cp437'))
        
        win32print.EndPagePrinter(hPrinter)
        win32print.EndDocPrinter(hPrinter)
        print("SUKSES: Perintah RAW TSPL telah dikirim ke Windows Spooler.")
    finally:
         win32print.ClosePrinter(hPrinter)
except Exception as e:
    print(f"GAGAL: {e}")
