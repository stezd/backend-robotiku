# Trigger Invoice Setelah Target Sesi Kontrak Selesai (Phase 5)

> **Status:** Implemented  
> **Versi:** 1.0.0  
> **Tanggal:** 30 September 2026

---

## Ringkasan

Fitur ini menghubungkan progres sesi kelas dengan penagihan secara otomatis. Ketika jumlah sesi kelas yang diselesaikan mencapai kelipatan `meetings_per_period` (default: 4), sistem secara otomatis men-generate `BillingMonth` dan `Invoice` untuk **semua murid aktif** di kelas tersebut — termasuk yang izin atau sakit pada sesi-sesi sebelumnya.

---

## Arsitektur

```
SessionController::end()
        │
        ▼
BillingCycleService::triggerPeriodCompletionForClass($kelas, $session)
        │
        ├─ Hitung total sesi berstatus 'ended' di kelas
        ├─ Cek apakah kelipatan meetings_per_period tercapai
        │
        ├─ TIDAK → return [] (belum saatnya)
        │
        └─ YA → Iterasi seluruh murid aktif:
            │
            ├─ generateInvoiceForStudent($student, $nextPeriod, $totalPeriods)
            │   ├─ Cek kuota (period_quota / total_periods) → nonaktifkan jika terlampaui
            │   ├─ Cek idempotensi (cycle_number guard)
            │   ├─ Deteksi self_managed → status 'lunas' / 'belum_bayar'
            │   └─ DB::transaction → BillingMonth + Invoice
            │
            └─ Return array Invoice[]
```

---

## Titik Integrasi

### Endpoint Pemicu

**`POST /api/v1/sesi/{session}/selesai`** — `SessionController::end()`

Dipanggil saat trainer menyelesaikan sesi kelas (baik sesi langsung maupun manual). Setelah status sesi diubah menjadi `'ended'`, method `triggerPeriodCompletionForClass()` dievaluasi secara otomatis.

### Method Service

**`BillingCycleService::triggerPeriodCompletionForClass(Kelas $kelas, Session $session): array`**

| Parameter | Tipe | Deskripsi |
|---|---|---|
| `$kelas` | `Kelas` | Objek kelas yang sesinya baru selesai |
| `$session` | `Session` | Sesi yang baru saja diselesaikan |
| **Return** | `Invoice[]` | Array invoice yang baru dibuat (bisa kosong) |

---

## Logika Bisnis

### 1. Pemicu Capaian Target Sesi

```php
$endedCount = Session::where('class_id', $kelas->id)->where('status', 'ended')->count();
$trigger = ($endedCount > 0 && $endedCount % $perPeriod === 0);
```

- Target dihitung dari **jumlah total sesi `ended`**, bukan kehadiran murid.
- Berlaku untuk seluruh murid aktif di kelas — izin dan sakit tetap ditagih.

### 2. Idempotensi (Cycle Number Guard)

```php
if (BillingMonth::where('student_id', $student->id)
    ->where('cycle_number', $nextPeriod)
    ->exists()
) {
    return null; // sudah pernah ditagih
}
```

Pemanggilan berulang (misal: absensi diedit ulang) tidak menghasilkan invoice duplikat.

### 3. Dukungan Skema Self-Managed

| Skema | `School::self_managed` | Status Invoice | Notifikasi SPP |
|---|---|---|---|
| Mandiri / Direct | `false` / `null` | `'belum_bayar'` | ✅ Ya |
| Self-Managed | `true` | `'lunas'` | ❌ Tidak |

Sekolah `self_managed` mengelola pembayaran kolektif, sehingga invoice langsung dianggap lunas.

### 4. Batas Kuota Kontrak & Auto-Nonaktif

```php
$quotaLimit = $student->period_quota ?? $kelas->total_periods;
if ($quotaLimit !== null && $nextPeriod > $quotaLimit) {
    $student->update(['status' => 'nonaktif']);
    StudentStatusLog::create([...]);
    return null;
}
```

- `period_quota` per-murid (dari MoU) mengoverride `total_periods` kelas.
- Murid yang melebihi kuota otomatis dinonaktifkan dengan riwayat tercatat di `student_status_logs`.

### 5. Atomisitas (ACID)

Pembuatan `BillingMonth` dan `Invoice` dibungkus dalam `DB::transaction()`. Jika terjadi error di tengah proses, seluruh operasi di-rollback.

---

## Skema Database yang Terlibat

| Tabel | Kolom Kunci | Peran |
|---|---|---|
| `class_sessions` | `class_id`, `status` | Menghitung sesi `ended` per kelas |
| `classes` | `meetings_per_period`, `total_periods` | Konfigurasi kuota sesi |
| `students` | `period_quota`, `status`, `school_id` | Kuota per-murid, eligibilitas |
| `schools` | `self_managed`, `price_per_cycle` | Skema pembayaran |
| `billing_months` | `student_id`, `cycle_number` | Idempotensi guard |
| `invoices` | `student_id`, `billing_month_id`, `status` | Tagihan yang dihasilkan |
| `student_status_logs` | `student_id`, `old_status`, `new_status` | Audit trail nonaktif otomatis |

---

## Test Coverage

| # | Skenario | Test Method | Tipe |
|---|---|---|---|
| 1 | 4 sesi ended → invoice untuk semua murid aktif | `test_period_completion_generates_invoices_for_all_active_students` | Feature |
| 2 | Murid izin/sakit tetap ditagih | `test_absent_students_still_billed_on_period_completion` | Feature |
| 3 | Self-managed → invoice 'lunas' | `test_self_managed_school_creates_lunas_invoice` | Feature |
| 4 | Non-self-managed → invoice 'belum_bayar' | `test_non_self_managed_school_creates_belum_bayar_invoice` | Feature |
| 5 | Kuota habis → nonaktif, tanpa invoice | `test_quota_exceeded_deactivates_student_no_invoice` | Feature |
| 6 | Pemanggilan berulang → tidak dobel | `test_idempotent_trigger_no_duplicate_invoices` | Feature |
| 7 | Belum kelipatan → tidak generate | `test_non_multiple_session_count_no_invoice` | Feature |
| 8 | Murid nonaktif/cuti tidak ditagih | `test_inactive_students_not_billed_on_period_completion` | Feature |

```bash
php artisan test tests/Feature/Bayar/BillingCycleTest.php
```

---

## Hubungan dengan Fitur Sebelumnya

- **Phase 4 (Sesi Manual):** Sesi manual (`is_manual=true`) juga memicu evaluasi billing saat diselesaikan.
- **Phase 3 (Student Lifecycle):** Auto-nonaktif menggunakan audit trail `student_status_logs` yang sama.
- **Phase 2 (Rekonsiliasi):** Invoice yang dihasilkan terintegrasi dengan dashboard keuangan dan rekonsiliasi setoran sekolah.
- **`onAttendance()`:** Method existing tetap dipertahankan sebagai trigger per-murid saat absensi hadir. `triggerPeriodCompletionForClass()` adalah trigger class-level yang baru.
