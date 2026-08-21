# KANBAN PPIC — PRINT AGENT OFFLINE INSTALLATION GUIDE

Panduan ini berisi langkah-langkah instalasi dan pengetesan Windows Print Agent pada komputer operator Fitting yang **TIDAK MEMILIKI AKSES INTERNET** (Offline).

---

## Persyaratan Awal (Prerequisites)
1. **Python**: Sudah terinstal di PC operator Fitting (Disarankan versi 3.13 ke atas).
2. **Driver Printer**: Driver printer Windows untuk **TSC TE244** sudah terinstal dan berfungsi dengan baik.
3. **Nama Printer**: Pastikan nama printer di control panel dicatat secara tepat (contoh: `TSC TE244`).

---

## Langkah-Langkah Deployment & Pengetesan

### 1. Salin Folder ke PC Fitting
* Salin seluruh isi folder `print-agent` dari repositori ini ke Flashdisk (USB).
* Pastikan folder `wheels/` yang berisi paket `.whl` offline ikut tersalin.
* Hubungkan Flashdisk ke PC Fitting, lalu salin folder `print-agent` tersebut ke lokasi harddisk lokal (contoh: `C:\Kaizen-Print-Agent`).

### 2. Jalankan CMD & Instal Dependensi
* Buka Command Prompt (CMD) di PC Fitting, lalu arahkan ke folder instalasi:
  ```cmd
  cd /d C:\Kaizen-Print-Agent
  ```
* Jalankan berkas installer dependensi offline:
  ```cmd
  install-dependencies.bat
  ```
* Installer akan memeriksa instalasi Python, memverifikasi ketersediaan folder `wheels/`, memasang semua paket secara offline tanpa internet, dan memverifikasi modul `requests` dan `win32print`.
* Pastikan status akhir yang ditampilkan adalah: **`STATUS: SUCCESS`**.

### 3. Konfigurasi Agen
* Buka berkas `config.json` menggunakan Notepad:
  ```json
  {
    "server_url": "http://10.88.8.46:6002/api",
    "machine_id": "FITTING-PRINT-01",
    "printer_name": "TSC TE244",
    "poll_interval": 2,
    "api_token": "peroniks_print_token_2026"
  }
  ```
* Sesuaikan nilai `"printer_name"` dengan nama printer TSC Anda di Windows.
* Sesuaikan `"api_token"` jika ada perubahan token keamanan di server Kanban PPIC.

### 4. Uji Coba Printer Lokal (Tanpa Koneksi Server)
* Jalankan script uji printer:
  ```cmd
  python test-printer.py
  ```
* **Hasil yang diharapkan**: Printer TSC TE244 akan mencetak satu label bertuliskan `"TEST PRINT OK"`.
* Jika gagal, periksa nama printer pada `config.json` dan pastikan printer berstatus "Ready/Online" di Control Panel Windows.

### 5. Verifikasi Konektivitas ke Kanban PPIC
* Uji koneksi jaringan ke server Kanban PPIC menggunakan perintah ping di CMD:
  ```cmd
  ping 10.88.8.46
  ```
* Pastikan mendapatkan respon balik (reply) dari server.

### 6. Jalankan Print Agent
* Double-click berkas `start-agent.bat` atau jalankan dari CMD.
* Konsol akan menampilkan status aktif polling ke server target.

### 7. Uji Cetak 1 Label (Single Test)
* Buka aplikasi web Kanban PPIC (`http://10.88.8.46:6002`) dari browser.
* Buka modul Rangkaian (Lost Wax), pilih salah satu Rangkaian, lalu klik tombol **Thermal** (atau **Cetak Thermal 90x50**).
* Pastikan printer TSC mencetak label dengan ukuran, tata letak, dan barcode yang presisi.
* Pindai barcode tersebut menggunakan hand-scanner operator untuk memastikan data terbaca dengan benar.

### 8. Cetak Massal (Bulk Printing)
* Setelah pengetesan 1 label berhasil, lakukan pengujian cetak bulk dengan memilih beberapa Rangkaian dan mengklik **Cetak Thermal Terpilih**.
