# Plan Item Close / Tutup Rencana Fix Report

**Date:** 2026-08-22  
**Status:** BUG FIXED — ALL TESTS PASS

---

## 1. Root Cause Analysis
Di halaman `/lost-wax/print-orders/plans`, seluruh daftar rencana produksi dibungkus dalam form seleksi utama dengan tag `<form id="create-order-form" method="GET" action="{{ route('lost-wax.print-orders.create') }}">`. 
Namun, di dalam baris tabel (kolom Aksi), tombol "Tutup" dan "Buka" dibuat menggunakan nested tag `<form action="{{ route('lost-wax.print-orders.store') }}" method="POST">`.

Menurut standar HTML5, **nested forms tidak didukung oleh browser** (browser mengabaikan tag `<form>` terdalam). Akibatnya:
- Ketika user menekan tombol "Tutup" per baris, browser memproses submit tersebut sebagai submit pada form terluar (`create-order-form`) yang mengarah ke route `create` Print Order.
- Inilah yang menyebabkan user malah dialihkan ke halaman "Informasi Dokumen Perintah Cetak" (form pembuatan perintah cetak) alih-alih menutup item rencana.

---

## 2. Existing Behavior vs Expected Business Behavior

### Existing Behavior (Buggy):
- Menekan tombol "Tutup" di baris rencana produksi melakukan submit form seleksi luar ke `lost-wax/print-orders/create` (GET).
- Rencana produksi tidak ditutup secara persisten di database.
- Tidak ada fitur untuk menutup beberapa rencana produksi terpilih sekaligus (bulk close).

### Expected Business Behavior (Fixed):
- Menekan tombol "Tutup" per baris langsung mengubah status item tersebut menjadi `is_closed = true` secara persisten tanpa memicu pembuatan Print Order, Print Order Line, execution, maupun perubahan quantity.
- User dapat memilih beberapa item (checklist) dan melakukan bulk action "Tutup Terpilih" untuk menutup beberapa rencana sekaligus dalam satu transaksi database yang aman.
- Item rencana yang sudah berstatus `CLOSED/DITUTUP` tidak lagi muncul dalam daftar filter "Aktif", tidak bisa dicentang, dan tidak bisa diproses ke form pembuatan Print Order baru (akan direject oleh controller validation jika dipaksa).
- Aksi single close dan bulk close dilengkapi dengan dialog konfirmasi (`confirm()`).

---

## 3. Files Changed
1. **View:**
   - [`resources/views/lost-wax/print-orders/plans.blade.php`](file:///C:/laragon/www/kanban-ppic/resources/views/lost-wax/print-orders/plans.blade.php)
     - Mengganti nested forms di kolom "Aksi" dengan button HTML biasa yang memicu fungsi JavaScript `submitSingleAction()`.
     - Menambahkan form helper tersembunyi `single-action-form` dan `bulk-close-form` di luar form seleksi utama untuk menangani POST request secara aman tanpa melanggar struktur HTML.
     - Menambahkan tombol **Tutup Terpilih** di samping tombol **Buat Perintah Cetak** di bawah tabel untuk mendukung bulk close.
     - Menambahkan JavaScript logic untuk menangani bulk close, mengisi array `plan_ids[]` secara dinamis dari checkbox yang dicentang, dan mengirim request.
2. **Controller:**
   - [`app/Http/Controllers/LostWax/PrintOrderController.php`](file:///C:/laragon/www/kanban-ppic/app/Http/Controllers/LostWax/PrintOrderController.php)
     - Menambahkan penanganan aksi `bulk_close_plans` di dalam `store()`. Aksi ini memvalidasi input array plan terpilih, melakukan otorisasi data scope, dan memperbarui status `is_closed => true` di dalam `DB::transaction()` secara aman.

---

## 4. Database Impact
- Perubahan status bersifat logis dan non-destructive. Hanya meng-update kolom `is_closed = true` pada tabel `production_plans`.
- Tidak ada penghapusan baris data (`DELETE`), tidak mengubah quantity, tidak memodifikasi `lost_wax_print_orders`, `lost_wax_print_order_lines`, maupun execution history / tree.

---

## 5. Authorization & Security
- Otorisasi di level route diproteksi oleh middleware `permission:access_planning`.
- Otorisasi di level data row diverifikasi di controller menggunakan Spatie role/permission check (`ppic` / `admin` role) dan pencocokan `product_scope` user dengan `product_scope` plan yang ditargetkan. Operator dilarang keras melakukan penutupan plan (403 Forbidden).

---

## 6. Test Results
- **PrintOrderTest:** [`tests/Feature/LostWax/PrintOrderTest.php`](file:///C:/laragon/www/kanban-ppic/tests/Feature/LostWax/PrintOrderTest.php)
  - Menambahkan test case `test_bulk_close_and_single_close_workflow_safety` untuk memverifikasi workflow tutup/buka rencana produksi aktif, pengecualian dari list aktif, keberadaan di list closed, dan pencegahan pembuatan Print Order terhadap item closed.
- **RbacScopeTest:** [`tests/Feature/LostWax/RbacScopeTest.php`](file:///C:/laragon/www/kanban-ppic/tests/Feature/LostWax/RbacScopeTest.php)
  - Menambahkan test case `test_planning_close_authorization_and_product_scope_safety` untuk memverifikasi penolakan akses tutup plan bagi user tanpa permission (`spv`), user PPIC scope berbeda, dan keberhasilan penutupan untuk scope yang cocok.
- **Suite Verdict:** **215 tests passed** (1385 assertions) — *ALL TESTS PASS (100% GREEN)*.

---

## 7. Backward Compatibility
- Tidak merubah arsitektur Phase 1-5.
- Logika query plans untuk workflow orders existing tetap berjalan aman dan tidak terganggu.
