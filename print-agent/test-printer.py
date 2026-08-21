import json
import os
import win32print

# Default fallback
printer_name = "TSC TE244"

# Try loading from config.json
config_path = os.path.join(os.path.dirname(__file__), 'config.json')
if os.path.exists(config_path):
    try:
        with open(config_path, 'r') as f:
            config = json.load(f)
            printer_name = config.get('printer_name', printer_name)
    except Exception as e:
        print(f"Warning: Could not read config.json, using default printer name. Error: {e}")

# Sample TSPL payload for 50x90 mm portrait label (matching Kaizen Tracker size)
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

print(f"Targeting Printer: {printer_name}")
print("Attempting to send RAW TSPL document...")

try:
    hPrinter = win32print.OpenPrinter(printer_name)
    try:
        hJob = win32print.StartDocPrinter(hPrinter, 1, ("TSC Test Print", None, "RAW"))
        win32print.StartPagePrinter(hPrinter)
        
        # cp437 encoding matches the production agent for maximum compatibility
        win32print.WritePrinter(hPrinter, tspl.encode('cp437'))
        
        win32print.EndPagePrinter(hPrinter)
        win32print.EndDocPrinter(hPrinter)
        print("SUCCESS: RAW TSPL job sent to Windows Spooler successfully.")
    finally:
         win32print.ClosePrinter(hPrinter)
except Exception as e:
    print(f"FAILED: {e}")
