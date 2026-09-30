# Daftar Masalah & Kebutuhan Pengembangan (Rapat Logbook)

Dokumen ini merangkum inventarisasi masalah, temuan celah (*gap expected vs reality*), dan kebutuhan fitur pada sistem **Siimrobi (Robotiku ERP)** yang dihimpun dari catatan rapat tanggal 23 dan 24 September 2026 beserta status resolusinya.

---

## Rapat: 23 September 2026

### 1. Manajemen Sesi & Kelas (Handle Pending Kelas)
* **Kondisi Saat Ini:**
  - Sesi kelas awalnya hanya dibuat langsung oleh sistem dengan validasi GPS & kamera wajib.
* **Gap & Kebutuhan:**
  - **Pembuatan Sesi Manual:** Harus bisa mengatur/membuat sesi kelas secara manual untuk mengantisipasi kelas pending atau penyesuaian jadwal lapangan.
    - *Status:* **Resolved (Phase 4)** — Diimplementasikan endpoint `POST /api/v1/sesi/manual`, modal "Sesi Manual (Susulan)" pada frontend, bypass verifikasi GPS/swafoto, dan absensi susulan yang dapat diedit kapan saja. Lihat [Dokumentasi Teknis Phase 4](../features/manual-sessions.md).
  - **Adaptasi Kontrak:** Jumlah sesi tidak selalu terpaku 4 sesi (bisa fleksibel sesuai kontrak kerjasama).
    - *Status:* **Resolved (Phase 4)** — Nomor pekan dihitung modular secara dinamis menggunakan nilai `meetings_per_period` dari tabel kelas (`classes`).
  - **Koneksi Sesi ke Penagihan:** Sistem harus menghubungkan progres sesi dengan penagihan. Ketika target sesi tercapai (misal: 4 sesi sesuai kesepakatan kontrak), sistem otomatis men-generate tagihan untuk bulan berikutnya.
    - *Status:* **Resolved (Phase 5)** — Diimplementasikan `BillingCycleService::triggerPeriodCompletionForClass()` yang dipanggil otomatis saat `SessionController::end()`. Mendukung skema `self_managed`, idempotensi `cycle_number`, dan auto-nonaktif kuota. Lihat [Dokumentasi Teknis Phase 5](../features/finance-session-trigger.md).

### 2. Verifikasi Pembayaran & Finansial
* **Bukti Pembayaran Tidak Tampil:** Gambar bukti transfer/pembayaran tidak muncul di halaman verifikasi (diduga kendala konfigurasi storage/bucket atau serving URL).
  - *Status:* **Resolved (Phase 1)** — Diimplementasikan serving biner inline preview, guard multi-tenant, dan opsi `?download=1`. Lihat [Dokumentasi Teknis Phase 1](../features/media-serving.md).
* **Aliran Tagihan ke Finance:** Tagihan yang dikirim oleh pihak sekolah tidak masuk/terekap di dashboard tim finance.
  - *Status:* **Resolved (Phase 2)** — Diimplementasikan rekonsiliasi transaksional berjenjang saat verifikasi setoran sekolah (`SchoolSettlement`), aktivasi invoice & verifikasi siswa, serta formula anti double-counting pada dashboard keuangan. Lihat [Dokumentasi Teknis Phase 2](../features/finance-reconciliation.md).

### 3. Kinerja Sistem (Performance)
* **Latency Tidak Konsisten:** Respons sistem kadang terasa lambat secara sporadis. Diperlukan profiling query database, optimalisasi endpoint, dan caching.
  - *Status:* **Optimized** — Indeks gabungan telah diterapkan pada tabel tagihan, sekolah, dan registrasi. Lihat [Walkthrough Performa](../performance/walkthrough.md).

---

## Rapat: 24 September 2026

### 1. Data Siswa & Siklus Hidup (Student Lifecycle)
* **Filter Murid Baru:** Murid tidak muncul dalam daftar murid jika statusnya belum bayar dan belum diverifikasi.
  - *Status:* **Resolved (Phase 3)** — Query `where('is_verified', true)` diubah menjadi filter dinamis melalui query param `verification_status` (`verified`, `unverified`, `all`) pada Admin dan Portal Sekolah.
* **Fitur Edit Biodata Murid:** Belum tersedia fungsi edit profil/biodata siswa untuk role `sekolah`, `admin`, dan `superadmin`.
  - *Status:* **Resolved (Phase 3)** — Dibuat service terpusat `StudentBiodataService`, form request `UpdateStudentBiodataRequest`, serta endpoint `PUT /api/v1/siswa/{student}` (Admin) dan `PUT /api/v1/sekolah/murid/{student}` (Sekolah dengan isolasi multi-tenant).
* **Transisi Status Siswa:** Terjadi masalah/kendala teknis saat transisi status dari **Cuti $\rightarrow$ Berhenti**.
  - *Status:* **Resolved (Phase 3)** — Harmonisasi validasi `StudentStatusRequest` dan pemetaan otomatis input `'berhenti'` menjadi `'nonaktif'` pada database secara transaksional.
* **Penyederhanaan Label UI:** Label status *"Berhenti"* perlu diganti menjadi *"Nonaktif"* agar lebih representatif.
  - *Status:* **Resolved (Phase 3)** — Standarisasi label dan nilai database menjadi `nonaktif`, dengan backward compatibility untuk input `berhenti`.
* **Audit Trail / Riwayat Status:** Riwayat perubahan status siswa yang bersifat *immutable* belum terimplementasi.
  - *Status:* **Resolved (Phase 3)** — Setiap perubahan status kini wajib dan otomatis tercatat ke tabel `student_status_logs` (menggunakan trait `Immutable`) di dalam transaksi atomik database.
* **Pencarian Nomor HP Orang Tua:** Temuan nomor HP (08969696) yang sebelumnya dilaporkan tidak ditemukan dikonfirmasi **working as intended** (faktor akun *self-managed*).
  - *Detail teknis Phase 3 selengkapnya:* Lihat [Dokumentasi Teknis Phase 3](../features/student-lifecycle.md).

### 2. Skema Pembayaran Sekolah (Multi-scheme Payment)
Terdapat *gap* ekspektasi alur pembayaran antara yang langsung ke Robotiku dengan yang dikelola sekolah:
* **Versi 1 (Direct Payment):** Pembayaran langsung ditujukan ke rekening Robotiku.
* **Versi 2 (Managed by School):** Dikelola oleh pihak sekolah (dapat menggunakan rekening sekolah).
* **Versi 3 (Collective Payment):** Sekolah mengelola pembiayaan secara kolektif, sehingga tagihan pembiayaan **tidak boleh dimunculkan** di portal orang tua (*expose* logika penayangan tagihan).

### 3. Portal Orang Tua (Parent Portal UX)
* **Header Identitas:** Menambahkan informasi **nama sekolah** tepat di bawah sapaan `"Hi, {nama},"` agar orang tua mengetahui konteks sekolah anak yang terdaftar.
  - *Status:* **Resolved** — Backend mengekspos konteks sekolah (`school` dan fallback `school_origin`) pada endpoint parent lookup & progres murid (commit `aceb350`), dan frontend `ParentShell` menampilkan nama sekolah mitra di bawah sapaan orang tua (commit `4df242c`).

### 4. Autentikasi & Sesi
* **Logout Mechanics:** Terdapat kejanggalan/anomali (*tomfoolery*) pada mekanisme logout yang perlu distandarisasi penanganan token/session-nya.

---

## Matriks Rangkuman & Prioritas

| No | Modul | Deskripsi Masalah | Tanggal Temuan | Status | Referensi Teknis |
|:--:|---|---|:---:|:---:|---|
| 1 | Sesi & Kelas | Pembuatan sesi manual & handle pending kelas | 23/09/2026 | **Resolved (Phase 4)** | [docs/features/manual-sessions.md](../features/manual-sessions.md) |
| 2 | Sesi & Finansial | Trigger invoice setelah target sesi kontrak selesai | 23/09/2026 | **Resolved (Phase 5)** | [docs/features/finance-session-trigger.md](../features/finance-session-trigger.md) |
| 3 | Finansial | Gambar bukti bayar tidak muncul (Storage/Bucket) | 23/09/2026 | **Resolved (Phase 1)** | [docs/features/media-serving.md](../features/media-serving.md) |
| 4 | Finansial | Tagihan sekolah tidak masuk ke modul finance | 23/09/2026 | **Resolved (Phase 2)** | [docs/features/finance-reconciliation.md](../features/finance-reconciliation.md) |
| 5 | Siswa | Fitur edit biodata murid (role sekolah, superadmin, admin) | 24/09/2026 | **Resolved (Phase 3)** | [docs/features/student-lifecycle.md](../features/student-lifecycle.md) |
| 6 | Siswa | Bug transisi status Cuti $\rightarrow$ Berhenti & ubah label ke Nonaktif | 24/09/2026 | **Resolved (Phase 3)** | [docs/features/student-lifecycle.md](../features/student-lifecycle.md) |
| 7 | Siswa | Tabel riwayat status siswa (*immutable audit trail*) | 24/09/2026 | **Resolved (Phase 3)** | [docs/features/student-lifecycle.md](../features/student-lifecycle.md) |
| 8 | Siswa | Filter visibilitas murid yang belum bayar/verified | 24/09/2026 | **Resolved (Phase 3)** | [docs/features/student-lifecycle.md](../features/student-lifecycle.md) |
| 9 | Finansial / Ortu | Penyesuaian tampilan tagihan ortu sesuai 3 skema sekolah (v1/v2/v3) | 24/09/2026 | High | Backlog / Phase 5 |
| 10 | Portal Ortu | Tampilkan nama sekolah di bawah sapaan orang tua | 24/09/2026 | **Resolved** | Frontend `ParentShell` & Backend `ParentLookup` |
| 11 | Auth | Perbaikan alur & mekanisme logout | 24/09/2026 | Bugfix | Backlog |
| 12 | System | Optimalisasi performa respon backend | 23/09/2026 | **Optimized** | [docs/performance/walkthrough.md](../performance/walkthrough.md) |

---

## Log Riwayat Implementasi Fase

1. **Phase 1: Local-First Media Serving & Proof View**
   - Mengubah respon download paksa menjadi stream biner inline.
   - Menyediakan otorisasi multi-tenant bagi admin sekolah untuk verifikasi bukti bayar murid.
   - Dokumentasi: [`docs/features/media-serving.md`](../features/media-serving.md).

2. **Phase 2: Rekonsiliasi Finansial & Dashboard Pendapatan**
   - Menyinkronkan persetujuan setoran sekolah dengan status pelunasan invoice dan aktivasi siswa.
   - Menghitung omzet setoran sekolah pada dashboard keuangan tanpa risiko *double-counting*.
   - Dokumentasi: [`docs/features/finance-reconciliation.md`](../features/finance-reconciliation.md).

3. **Phase 3: Siklus Hidup Siswa (Status, Biodata, & Filter Visibilitas)**
   - Harmonisasi status siswa (`berhenti` $\rightarrow$ `nonaktif`) dengan audit log immutable di `student_status_logs`.
   - Endpoint pembaruan biodata murid & kontak wali untuk Admin dan Sekolah (dengan isolasi multi-tenant `school_id`).
   - Filter visibilitas dinamis `verification_status` (`verified`, `unverified`, `all`).
   - Dokumentasi: [`docs/features/student-lifecycle.md`](../features/student-lifecycle.md).

4. **Phase 4: Manajemen Sesi Manual & Penanganan Kelas Pending**
   - Penambahan endpoint `POST /api/v1/sesi/manual` dengan koordinat GPS dan foto selfie berstatus *nullable*.
   - Dukungan kuota sesi dinamis berbasis `meetings_per_period` pada kelas.
   - Opsi penyelesaian sesi manual instan tanpa GPS kepulangan dan fleksibilitas pengisian absensi susulan.
   - Integrasi modal sesi susulan dan indikator badge manual pada antarmuka frontend.
   - Dokumentasi: [`docs/features/manual-sessions.md`](../features/manual-sessions.md).

5. **Phase 5: Trigger Invoice Setelah Target Sesi Kontrak Selesai**
   - Implementasi `BillingCycleService::triggerPeriodCompletionForClass()` untuk evaluasi capaian sesi kelas secara otomatis.
   - Integrasi pemicu pada `SessionController::end()` (sesi langsung & manual).
   - Dukungan skema pembayaran `self_managed` (invoice otomatis `'lunas'`) dan `belum_bayar` (mandiri/direct).
   - Idempotensi `cycle_number` guard dan atomisitas `DB::transaction()`.
   - Auto-nonaktif murid saat kuota kontrak (`period_quota` / `total_periods`) terlampaui dengan audit trail `student_status_logs`.
   - Test coverage 12 skenario PHPUnit (100% pass).
   - Dokumentasi: [`docs/features/finance-session-trigger.md`](../features/finance-session-trigger.md).
