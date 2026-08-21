# KANBAN PPIC — PRINT AGENT OFFLINE INSTALLATION GUIDE

Panduan ini berisi langkah-langkah instalasi, konfigurasi, dan pengetesan Windows Print Agent pada komputer operator Fitting yang **TIDAK MEMILIKI AKSES INTERNET** (Offline).

> [!WARNING]
> **PENTING:** Folder `WMS-PRINT-AGENT` yang berada di dalam folder ini hanyalah backup / referensi lama sistem WMS.
> **JANGAN MENJALANKAN FILE DI WMS-PRINT-AGENT UNTUK KANBAN PPIC.** Gunakan hanya berkas yang berada langsung di root folder `print-agent/`.

---

## 1. Persyaratan Sistem (Prerequisites)
* **Sistem Operasi**: Windows 10 atau Windows 11 (64-bit).
* **Python**: Python 3.13.x (64-bit) wajib terpasang.
* **Driver Printer**: Driver Windows resmi untuk **TSC TE244** harus sudah terpasang dan berstatus "Ready/Online".
* **Konektivitas Jaringan**: Komputer Fitting harus terhubung dalam jaringan LAN ke server database Kanban PPIC di alamat IP **`10.88.8.46`**.

---

## 2. Langkah-Langkah Deployment & Instalasi

### Langkah A: Instalasi Python 3.13 (Offline)
Jika Python belum terpasang di PC Fitting:
1. Unduh installer resmi **Python 3.13.x 64-bit Windows Installer** (berkas `.exe`) dari komputer lain yang memiliki internet.
2. Salin installer tersebut ke PC Fitting menggunakan USB Flashdisk.
3. Jalankan installer dan **PENTING:** Centang opsi **"Add python.exe to PATH"** sebelum mengklik tombol **"Install Now"**.

### Langkah B: Salin Folder Agen ke PC Fitting
1. Salin seluruh isi folder `print-agent` dari repositori ini ke USB Flashdisk.
2. Pastikan folder `wheels/` yang berisi berkas `.whl` offline ikut tersalin secara utuh.
3. Salin folder tersebut dari Flashdisk ke harddisk lokal PC operator Fitting (disarankan ke lokasi `C:\print-agent`).

### Langkah C: Jalankan Diagnostik Lingkungan
Sebelum memasang apa pun, Anda dapat memeriksa kesiapan sistem dengan mendobel-klik berkas:
```text
check-environment.bat
```
Script ini akan menampilkan informasi versi Windows, versi Python, arsitektur Python (wajib 64-bit), serta mendeteksi semua printer Windows yang terpasang.

### Langkah D: Instalasi Dependensi secara Offline
1. Buka folder `C:\print-agent`.
2. Dobel-klik berkas:
   ```text
   install-dependencies.bat
   ```
3. Script secara otomatis akan:
   - Memverifikasi bahwa Python yang terpasang adalah versi **3.13.x (64-bit)**.
   - Memasang semua pustaka dari folder `wheels/` lokal tanpa memerlukan koneksi internet.
   - Memverifikasi import pustaka `requests` dan `win32print`.
4. Jika berhasil, konsol akan menampilkan pesan **`STATUS: SUCCESS`**.

---

## 3. Konfigurasi & Pengetesan

### Langkah E: Konfigurasi `config.json`
Buka berkas `config.json` menggunakan Notepad dan sesuaikan parameternya:
```json
{
  "server_url": "http://10.88.8.46:6002/api",
  "machine_id": "FITTING-PRINT-01",
  "printer_name": "TSC TE244",
  "poll_interval": 2,
  "api_token": "peroniks_print_token_2026"
}
```
* **`server_url`**: Gunakan `http://10.88.8.46:6002/api`.
* **`printer_name`**: Ganti dengan nama printer TSC Anda secara persis seperti yang tertulis di Control Panel Windows.
* **`api_token`**: Samakan dengan token otentikasi server Kanban PPIC.

### Langkah F: Uji Coba Printer Lokal (Tanpa Server)
Jalankan perintah uji printer di CMD atau dobel-klik berkas:
```cmd
python test-printer.py
```
* **Hasil**: Printer TSC TE244 akan langsung mencetak satu label bertuliskan **"TEST PRINT OK"**.
* Jika muncul error dependensi belum terpasang, pastikan Anda telah menjalankan `install-dependencies.bat` dengan sukses.

### Langkah G: Verifikasi Koneksi ke Server
Uji konektivitas jaringan ke server Kanban PPIC melalui CMD:
```cmd
ping 10.88.8.46
```
Pastikan mendapatkan respon "Reply from...".

### Langkah H: Jalankan Agent
Dobel-klik berkas:
```text
start-agent.bat
```
Agent akan berjalan dalam loop untuk melakukan polling job pencetakan ke server. Jika terjadi crash atau error jaringan, jendela konsol akan tetap terbuka (tidak menutup otomatis) berkat penanganan error internal dan jeda.

### Langkah I: Uji Coba Cetak Web (Single & Bulk)
1. Buka browser di PC operator, akses web **Kanban PPIC**: `http://10.88.8.46:6002/login`.
2. Klik tombol **Thermal** pada salah satu baris Rangkaian. Printer harus mencetak 1 label thermal secara instan.
3. Centang beberapa Rangkaian, lalu klik tombol **Cetak Thermal Terpilih** untuk memvalidasi pencetakan bulk.
