# Dokumentasi Sistem Robotiku ERP

Selamat datang di repositori dokumentasi teknis **Robotiku ERP Backend**. Direktori ini berfungsi sebagai pusat referensi arsitektur, spesifikasi API, panduan fitur, analisis performa, dan rencana pengembangan sistem.

---

## Peta Direktori Dokumentasi

```text
docs/
├── README.md                      # Indeks utama dokumentasi (dokumen ini)
│
├── architecture/                  # Arsitektur sistem & panduan pengembang
│   └── onboarding.md              # Tech stack, request lifecycle, dan konvensi proyek
│
├── features/                      # Spesifikasi fitur & panduan implementasi teknis
│   ├── media-serving.md           # Serving bukti pembayaran, setoran, & media (Phase 1)
│   ├── finance-reconciliation.md  # Rekonsiliasi finansial & dashboard pendapatan (Phase 2)
│   ├── student-lifecycle.md       # Siklus hidup siswa: status, biodata, & visibilitas (Phase 3)
│   ├── cloud-storage.md           # Cloud object storage & presigned URL strategy
│   ├── manual-sessions.md         # Manajemen sesi manual & handle pending kelas (Phase 4)
│   └── finance-session-trigger.md # Trigger invoice setelah target sesi kontrak (Phase 5)
│
├── api/                           # Spesifikasi & kontrak REST API
│   └── landing/                   # Dokumentasi API Landing CMS
│       ├── API_LANDING_SIMPEL.md  # Ringkasan REST API landing page
│       ├── LANDING_CMS_API.md     # Spesifikasi lengkap API CMS landing
│       ├── openapi.yaml           # Spesifikasi OpenAPI / Swagger
│       └── Robotiku-Landing.postman_collection.json # Postman collection
│
├── performance/                   # Benchmarking, profiling, & optimasi database
│   ├── walkthrough.md             # Walkthrough teknis optimasi query & indeks
│   ├── baseline-before.md         # Data baseline performa sebelum optimasi
│   └── comparison-report.md       # Laporan perbandingan performa (Before vs After)
│
└── roadmap/                       # Catatan rapat, kebutuhan backlog, & matriks masalah
    └── problems-meeting-notes.md  # Catatan rapat & matriks prioritas perbaikan
```

---

## Ringkasan Modul Dokumentasi

### 1. [Architecture & Onboarding](architecture/onboarding.md)
Panduan wajib untuk pengembang baru:
- Ikhtisar teknologi: PHP 8.3, Laravel 13, Sanctum, Eloquent ORM, MySQL/SQLite.
- Diagram alur permintaan (*Request Lifecycle*).
- Peta direktori kode sumber (`app/Http/Controllers`, `app/Services`, `routes/api.php`).
- Aturan validasi, otorisasi role, dan konvensi branch/commit git.

### 2. [Features: Media Serving (Phase 1)](features/media-serving.md)
Dokumentasi teknis seputar serving media dan bukti bayar:
- Solusi masalah *forced download* menjadi *inline preview*.
- Opsi query `?download=1` untuk kebutuhan pengunduhan paksa.
- Hak akses multi-tenant: isolasi data antar Admin Sekolah (`SchoolAdmin`) dan Admin Keuangan.
- Penjelasan integrasi komponen frontend (`ProofView`, `AuthImage`, modal verifikasi).

### 3. [Features: Rekonsiliasi Finansial & Dashboard Pendapatan (Phase 2)](features/finance-reconciliation.md)
Spesifikasi teknis penyelesaian rekonsiliasi dan analitik omzet:
- Siklus hidup transaksi: pembaruan status `SchoolSettlement`, `invoices` ('lunas'), `payments` ('diverifikasi'), dan aktivasi verifikasi `students`.
- Idempotency guard & pencegahan *race condition* verifikasi ganda.
- Formula agregasi pendapatan bersih (`net_amount`) pada `KeuanganDashboardService`.
- Anti-join `whereNotExists` guna mengeliminasi resiko *double-counting* antara invoice dan setoran sekolah.
- Agregasi tren bulanan multi-driver kompatibel SQLite (CI/testing) & MySQL (production).

### 4. [Features: Siklus Hidup Siswa (Phase 3)](features/student-lifecycle.md)
Spesifikasi teknis siklus hidup status, edit biodata, dan visibilitas murid:
- Harmonisasi transisi status siswa: normalisasi input `'berhenti'` menjadi `'nonaktif'` dan guard status `'lulus'` khusus Super Admin.
- Audit trail *immutable*: pencatatan riwayat perubahan status pada tabel `student_status_logs` (*insert-only*).
- Service terpusat `StudentBiodataService`: sinkronisasi atomik data profil murid dan kontak orang tua (`parents`).
- Endpoint pembaruan biodata untuk Admin (`/siswa/{student}`) dan Sekolah (`/sekolah/murid/{student}`) dengan pengamanan *multi-tenant isolation*.
- Filter visibilitas dinamis `verification_status` (`verified`, `unverified`, `all`) untuk memunculkan pendaftar baru yang belum bayar/diverifikasi.

### 5. [Features: Cloud Object Storage & Presigned URL](features/cloud-storage.md)
Arsitektur penyimpanan berkas fleksibel (Dual Storage):
- Dukungan penuh multi-provider: Local Storage, AWS S3, MinIO (self-hosted), dan Cloudflare R2 (zero egress fees).
- Strategi **Presigned Temporary URL**: Otorisasi dilakukan di server backend, pengunduhan/penayangan berkas dialihkan via `302 Redirect` langsung ke Object Storage CDN untuk menghemat resource CPU dan bandwidth server PHP.
- Layanan terpusat `MediaStorage`: Abstraksi `store`, `storeWebp`, `delete`, dan `response` yang transparan.
- Mekanisme fallback otomatis ke disk lokal saat migrasi cloud untuk mencegah error 404 pada berkas lama.
- Konversi WebP dalam memori (*in-memory buffer*) tanpa ketergantungan path filesystem lokal.

### 6. [Features: Sesi Manual & Pending Kelas (Phase 4)](features/manual-sessions.md)
Spesifikasi teknis pembuatan sesi manual retroaktif dan penanganan jadwal kelas tertunda:
- Endpoint `POST /api/v1/sesi/manual`: Pembuatan sesi retroaktif tanpa validasi geofence GPS dan foto selfie.
- Skema database fleksibel: kolom koordinat GPS dan foto awal menjadi *nullable* pada tabel `class_sessions`.
- Nomor pekan dinamis berbasis kuota kontrak sekolah (`meetings_per_period`).
- Pengisian presensi susulan yang dapat diedit kapan saja walau sesi berstatus selesai (`ended`).
- Integrasi dialog modal `ManualDialog` dan badge pembeda tipe sesi pada frontend.

### 7. [Features: Trigger Invoice Setelah Target Sesi Kontrak (Phase 5)](features/finance-session-trigger.md)
Spesifikasi teknis otomasi penagihan berbasis capaian sesi kelas:
- Method `BillingCycleService::triggerPeriodCompletionForClass()`: evaluasi otomatis saat sesi diselesaikan.
- Integrasi pada `SessionController::end()` untuk sesi langsung dan manual.
- Dukungan skema `self_managed` (invoice otomatis berstatus `'lunas'`) dan mandiri (`'belum_bayar'`).
- Idempotensi guard `cycle_number` dan atomisitas `DB::transaction()`.
- Auto-nonaktif murid saat kuota kontrak (`period_quota` / `total_periods`) terlampaui.
- 12 test skenario PHPUnit mencakup periode selesai, izin/sakit, self-managed, kuota, dan idempotensi.

### 8. [REST API Specs](api/landing/API_LANDING_SIMPEL.md)
Katalog endpoint API untuk integrasi frontend dan layanan pihak ketiga:
- [API Landing Ringkas](api/landing/API_LANDING_SIMPEL.md) & [Spesifikasi CMS Lengkap](api/landing/LANDING_CMS_API.md).
- Format standar respons envelope JSON `{ status, data, message }`.
- [OpenAPI YAML](api/landing/openapi.yaml) & [Koleksi Postman](api/landing/Robotiku-Landing.postman_collection.json).

### 9. [Performance & Optimasi Query](performance/walkthrough.md)
Analisis dan benchmark efisiensi backend:
- [Walkthrough Optimasi](performance/walkthrough.md): Penambahan indeks gabungan pada tagihan, sekolah, dan registrasi.
- [Evaluasi Baseline Awal](performance/baseline-before.md): Identifikasi *full table scan* dan *filesort*.
- [Laporan Komparasi](performance/comparison-report.md): Pengurangan *latency* dan eliminasi *bottleneck* I/O database.

### 10. [Roadmap & Logbook Masalah](roadmap/problems-meeting-notes.md)
Pelacakan kebutuhan bisnis dan catatan rapat operasional:
- Matriks prioritas fitur (Urgent, High, Backlog, Resolved).
- Catatan kebutuhan sesi manual, multi-skema pembayaran sekolah, dan siklus hidup siswa.
