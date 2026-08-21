import win32print

PRINTER_NAME = "TSC TE244"

tspl = """
SIZE 60 mm,40 mm
GAP 2 mm,0 mm
CLS
TEXT 100,100,"3",0,1,1,"TEST PRINT OK"
PRINT 1
"""

hPrinter = win32print.OpenPrinter(PRINTER_NAME)

try:
    hJob = win32print.StartDocPrinter(hPrinter, 1, ("Test", None, "RAW"))
    win32print.StartPagePrinter(hPrinter)

    win32print.WritePrinter(hPrinter, tspl.encode('cp437'))

    win32print.EndPagePrinter(hPrinter)
    win32print.EndDocPrinter(hPrinter)

    print("SUCCESS")

finally:
    win32print.ClosePrinter(hPrinter)
