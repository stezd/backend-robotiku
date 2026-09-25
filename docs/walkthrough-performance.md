# Walkthrough: Evaluasi & Optimasi Performa Backend Robotiku ERP

Optimasi performa backend telah berhasil diimplementasikan, divalidasi dengan pengujian MySQL execution plan (`EXPLAIN`), dan didokumentasikan lengkap dengan metrik *Before vs After*.

---

## 1. Perubahan yang Telah Diterapkan

### A. Migrasi Index Komposit Database
Dibuat dan dijalankan migrasi:
[`database/migrations/2026_09_25_040518_add_performance_indexes.php`](file:///C:/Users/Fjontoldesk/Documents/Projects/robotiku-erp/backend-robotiku/database/migrations/2026_09_25_040518_add_performance_indexes.php)
- **`invoices`**:
  - `idx_invoices_student_status` (`student_id`, `status`)
  - `idx_invoices_status_due_date` (`status`, `due_date`)
- **`attendances`**:
  - `idx_attendances_student_status` (`student_id`, `status`)
  - `idx_attendances_class_attended` (`class_id`, `attended_at`)
- **`students`**:
  - `idx_students_school_status` (`school_id`, `status`)
  - `idx_students_status_verified` (`status`, `is_verified`)
- **`payments`**:
  - `idx_payments_status_created` (`status`, `created_at`)
- **`schools`**:
  - `idx_schools_pipeline_updated` (`pipeline_status`, `updated_at`)
  - `idx_schools_updated_at` (`updated_at`)

### B. Konsolidasi Query `ParentDashboardController`
Pada [`ParentDashboardController.php`](file:///C:/Users/Fjontoldesk/Documents/Projects/robotiku-erp/backend-robotiku/app/Http/Controllers/Api/V1/Ortu/ParentDashboardController.php):
- Menggabungkan perhitungan `hadir` dan grouping kehadiran menjadi single query `selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')`.
- Mengurangi total query DB dari 6 menjadi 5 queries.

### C. Caching Layer pada Konfigurasi Sistem
Pada [`Setting.php`](file:///C:/Users/Fjontoldesk/Documents/Projects/robotiku-erp/backend-robotiku/app/Models/Setting.php):
- `Setting::get($key)` dibungkus dengan `Cache::remember("setting:{$key}", 3600, ...)` sehingga request berikutnya langsung diambil dari memory cache.
- `Setting::put($key, $val)` dilengkapi `Cache::forget("setting:{$key}")` untuk invalidasi instan saat setting diperbarui.

---

## 2. Hasil Komparasi Metrik (Before vs After)

| Indikator Performa | BEFORE (Baseline) | AFTER (Optimized) | Dampak & Efek |
|---|:---:|:---:|:---:|
| **MySQL Scan `invoices` (student + status)** | `type: ALL` (Full Table Scan) | **`type: ref`** (`idx_invoices_student_status`) | **Mencegah kelambatan saat data invoice membesar** |
| **MySQL Scan `attendances` (student + status)** | Single-key scan | **`type: ref`** (`idx_attendances_student_status`) | **Pencarian status presisi (key_len: 9)** |
| **Jumlah Query DB `ParentDashboard@index`** | **6 queries** | **5 queries** | **Reduksi 16.7% query per request** |
| **Alokasi Memori `ParentDashboard@index`** | 16.12 KB | **14.93 KB** | **Hemat 1.19 KB memori per request** |
| **Rata-rata Latensi `ParentDashboard@index`** | 3.22 ms | **3.04 ms** | **Lebih responsif** |
| **Query DB `Setting::get()` Berulang** | Selalu hit DB disk | **0 DB Query (Cache Hit)** | **I/O disk berkurang** |

---

## 3. Dokumentasi Terkait

- 📄 Laporan Baseline (Before): [`docs/performance-baseline-before.md`](file:///C:/Users/Fjontoldesk/Documents/Projects/robotiku-erp/backend-robotiku/docs/performance-baseline-before.md)
- 📄 Laporan Komparasi (Before vs After): [`docs/performance-comparison-report.md`](file:///C:/Users/Fjontoldesk/Documents/Projects/robotiku-erp/backend-robotiku/docs/performance-comparison-report.md)
