# Laporan Evaluasi Baseline Performa (Before Optimization)

Dokumen ini mencatat metrik performa sebelum dilakukan migrasi indeks dan refactoring kode backend, sebagai acuan pembanding objektif (*Before vs After*).

---

## 1. Metrik Kinerja Aplikasi (Application-Level Baseline)

Hasil benchmarking dijalankan dengan 5 iterasi per endpoint menggunakan controller runtime aktual:

| Endpoint Target | Response Time Rata-rata (ms) | Range (Min - Max ms) | Memory Allocation (KB) | Total Query DB per Request |
|---|:---:|:---:|:---:|:---:|
| **`ParentDashboard@index`** (`GET /api/v1/ortu/dashboard`) | **3.22 ms** | 2.99 - 3.79 ms | 16.12 KB | **6 queries** |
| **`BillingReminder@index`** (`GET /api/v1/bayar/billing-reminder`) | **9.58 ms** | 8.98 - 10.10 ms | 53.93 KB | **5 queries** |
| **`CanvasSchool@index`** (`GET /api/v1/canvas/schools`) | **3.35 ms** | 3.16 - 3.68 ms | 12.35 KB | **3 queries** |

---

## 2. Analisis Rencana Eksekusi MySQL (Database-Level Baseline)

Melalui `EXPLAIN` query, ditemukan indikator performa buruk di level database yang menjadi akar masalah kelambatan saat volume data membesar (*scaling bottleneck*):

### A. Query Tagihan (`invoices`)
```sql
EXPLAIN select * from invoices where status in ('belum_bayar', 'menunggu_verifikasi') order by due_date is null, due_date asc limit 20 offset 0;
```
* **Hasil EXPLAIN:**
  - `type`: **`ALL`** (Full Table Scan!)
  - `key`: **`NULL`** (Tidak ada indeks yang bisa dipakai)
  - `rows`: **984 rows** dipindai seluruhnya
  - `Extra`: **`Using where; Using filesort`** (Database harus menyortir di disk/memori sementara setiap kali endpoint dipanggil)

### B. Query Sekolah / Pipeline (`schools`)
```sql
EXPLAIN select * from schools order by updated_at desc limit 15 offset 0;
```
* **Hasil EXPLAIN:**
  - `type`: **`ALL`** (Full Table Scan)
  - `key`: **`NULL`**
  - `Extra`: **`Using filesort`**

### C. Redundant Queries di `ParentDashboardController`
Dalam 1 kali pemanggilan `ParentDashboard@index`, database dipanggil 6 kali, dengan 2 query membaca tabel `attendances` secara terpisah:
1. `SELECT COUNT(*) WHERE student_id = ? AND status = 'hadir'`
2. `SELECT status, COUNT(*) WHERE student_id = ? GROUP BY status`

---

## 3. Target Metrik Setelah Optimasi (Success Criteria)

| Metrik | Baseline (Before) | Target (After) | Indikator Keberhasilan |
|---|---|---|---|
| **Query `invoices` scan type** | `type: ALL` (Full Table Scan) | `type: range` / `ref` | Menggunakan composite index `(status, due_date)` tanpa `filesort` |
| **Query `schools` sort type** | `Using filesort` | `Using index` | Menggunakan index `(updated_at)` / `(pipeline_status, updated_at)` |
| **Query count `ParentDashboard`** | 6 queries | 5 queries | Menggabungkan perhitungan absensi menjadi 1 query agregat |
| **Query count Setting Lookup** | 1 query DB per lookup | 0 query DB (Cache hit) | Nilai dibaca dari memory cache |
| **Latency `BillingReminder`** | 9.58 ms | $\le$ 5.00 ms | Peningkatan throughput ~40-50% |
