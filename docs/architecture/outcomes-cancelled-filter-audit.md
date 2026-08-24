# Audit & Hasil Implementasi: Outcomes Cancelled Document Filter

**Tanggal Audit:** 2026-08-24  
**Status Verdict:** READY FOR PRODUCTION

---

## 1. Lokasi Query Utama & Root Cause

Sebelum perbaikan dilakukan, query utama halaman Outcomes mengambil data dengan:
- **File:** [`OutcomeController.php`](file:///c:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/OutcomeController.php) pada method `index(Request $request)`.
- **Query Awal:**
  ```php
  $query = \App\Models\LostWaxPrintOrder::with(['lines.trees', 'lines.executions', 'creator'])
      ->where('status', '!=', 'DRAFT');
  ```
- **Masalah:** Query di atas hanya menyembunyikan status `DRAFT`. Akibatnya, dokumen dengan status `CANCELLED` (yang dibatalkan) masih ikut terambil, ter-render pada tabel Outcomes, dan dapat dicari melalui input pencarian.

---

## 2. Perubahan Layer Query (Server-Side)

Perubahan dilakukan secara mutlak pada tingkat database query (server-side) untuk mengeliminasi dokumen `CANCELLED` dari dataset utama:

- **Query Baru:**
  ```php
  $query = \App\Models\LostWaxPrintOrder::with(['lines.trees', 'lines.executions', 'creator'])
      ->whereNotIn('status', ['DRAFT', 'CANCELLED']);
  ```
- **Kelebihan Pendekatan:** Karena penyaringan dilakukan di level Base Query Builder, dokumen `CANCELLED` otomatis ter-filter keluar dari seluruh layer presentasi (default view, search, pagination, dan sorting).

---

## 3. Downstream Safety & Compatibility

- **Integritas Data Historis:** Record dokumen `CANCELLED` tidak dihapus maupun dimodifikasi dari database (tidak ada data corruption). Dokumen dibiarkan tetap ada sebagai histori.
- **Database Migration:** **TIDAK DIPERLUKAN (NO MIGRATION)**.
- **N+1 Performance Query:** Penyaringan menggunakan method `whereNotIn` bawaan Laravel Eloquent dan memanfaatkan relasi eager loading `with(...)` yang sudah ada, sehingga **tidak ada regresi query N+1**.

---

## 4. Hasil Automated Test

Test suite baru telah ditambahkan pada [`OutcomeAndAssemblyTest.php`](file:///c:/laragon/www/kanban-ppic/tests/Feature/LostWax/OutcomeAndAssemblyTest.php):
1. **`test_cancelled_print_orders_are_filtered_from_outcomes_list`**: Memastikan dokumen `ISSUED` tampil di daftar Outcomes sedangkan dokumen `CANCELLED` tidak muncul.
2. **`test_searching_for_cancelled_document_returns_empty_result`**: Memastikan pencarian menggunakan nomor dokumen `CANCELLED` menghasilkan hasil kosong (`assertCount(0)`).

Seluruh test suite Outcomes dan test suite global **GREEN (100% Passed)**.

---

*Laporan ini dibuat untuk mendokumentasikan pemenuhan UAT operational requirements di lapangan.*
