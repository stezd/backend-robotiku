# Daftar Masalah & Kebutuhan Pengembangan (Rapat Logbook)

Dokumen ini merangkum inventarisasi masalah, temuan celah (*gap expected vs reality*), dan kebutuhan fitur pada sistem **Siimrobi (Robotiku ERP)** yang dihimpun dari catatan rapat tanggal 23 dan 24 September 2026.

---

## Rapat: 23 September 2026

### 1. Manajemen Sesi & Kelas (Handle Pending Kelas)
* **Kondisi Saat Ini:**
  - Sesi kelas hanya dibuat otomatis mingguan oleh sistem (*by system*).
* **Gap & Kebutuhan:**
  - **Pembuatan Sesi Manual:** Harus bisa mengatur/membuat sesi kelas secara manual untuk mengantisipasi kelas pending atau penyesuaian jadwal lapangan.
  - **Adaptasi Kontrak:** Jumlah sesi tidak selalu terpaku 4 sesi (bisa fleksibel sesuai kontrak kerjasama).
  - **Koneksi Sesi ke Penagihan:** Sistem harus menghubungkan progres sesi dengan penagihan. Ketika target sesi tercapai (misal: 4 sesi sesuai kesepakatan kontrak), sistem otomatis men-generate tagihan untuk bulan berikutnya.

### 2. Verifikasi Pembayaran & Finansial
* **Bukti Pembayaran Tidak Tampil:** Gambar bukti transfer/pembayaran tidak muncul di halaman verifikasi (diduga kendala konfigurasi storage/bucket atau serving URL).
* **Aliran Tagihan ke Finance:** Tagihan yang dikirim oleh pihak sekolah tidak masuk/terekap di dashboard tim finance.

### 3. Kinerja Sistem (Performance)
* **Latency Tidak Konsisten:** Respons sistem kadang terasa lambat secara sporadis. Diperlukan profiling query database, optimalisasi endpoint, dan caching.

---

## Rapat: 24 September 2026

### 1. Data Siswa & Siklus Hidup (Student Lifecycle)
* **Filter Murid Baru:** Murid tidak muncul dalam daftar murid jika statusnya belum bayar dan belum diverifikasi.
* **Fitur Edit Biodata Murid:** Belum tersedia fungsi edit profil/biodata siswa untuk role `sekolah`, `admin`, dan `superadmin`.
* **Transisi Status Siswa:** Terjadi masalah/kendala teknis saat transisi status dari **Cuti $\rightarrow$ Berhenti**.
* **Penyederhanaan Label UI:** Label status *"Berhenti"* perlu diganti menjadi *"Nonaktif"* agar lebih representatif.
* **Audit Trail / Riwayat Status:** Riwayat perubahan status siswa yang bersifat *immutable* belum terimplementasi.
* **Pencarian Nomor HP Orang Tua:** Temuan nomor HP (08969696) yang sebelumnya dilaporkan tidak ditemukan dikonfirmasi **working as intended** (faktor akun *self-managed*).

### 2. Skema Pembayaran Sekolah (Multi-scheme Payment)
Terdapat *gap* ekspektasi alur pembayaran antara yang langsung ke Robotiku dengan yang dikelola sekolah:
* **Versi 1 (Direct Payment):** Pembayaran langsung ditujukan ke rekening Robotiku.
* **Versi 2 (Managed by School):** Dikelola oleh pihak sekolah (dapat menggunakan rekening sekolah).
* **Versi 3 (Collective Payment):** Sekolah mengelola pembiayaan secara kolektif, sehingga tagihan pembiayaan **tidak boleh dimunculkan** di portal orang tua (*expose* logika penayangan tagihan).

### 3. Portal Orang Tua (Parent Portal UX)
* **Header Identitas:** Menambahkan informasi **nama sekolah** tepat di bawah sapaan `"Hi, {nama},"` agar orang tua mengetahui konteks sekolah anak yang terdaftar.

### 4. Autentikasi & Sesi
* **Logout Mechanics:** Terdapat kejanggalan/anomali (*tomfoolery*) pada mekanisme logout yang perlu distandarisasi penanganan token/session-nya.

---

## Matriks Rangkuman & Prioritas

| No | Modul | Deskripsi Masalah | Tanggal Temuan | Status |
|:--:|---|---|:---:|:---:|
| 1 | Sesi & Kelas | Pembuatan sesi manual & handle pending kelas | 23/09/2026 | Backlog |
| 2 | Sesi & Finansial | Trigger invoice setelah target sesi kontrak selesai | 23/09/2026 | Backlog |
| 3 | Finansial | Gambar bukti bayar tidak muncul (Storage/Bucket) | 23/09/2026 | Urgent |
| 4 | Finansial | Tagihan sekolah tidak masuk ke modul finance | 23/09/2026 | Urgent |
| 5 | Siswa | Fitur edit biodata murid (role sekolah, superadmin, admin) | 24/09/2026 | Backlog |
| 6 | Siswa | Bug transisi status Cuti $\rightarrow$ Berhenti & ubah label ke Nonaktif | 24/09/2026 | Backlog |
| 7 | Siswa | Tabel riwayat status siswa (*immutable audit trail*) | 24/09/2026 | Backlog |
| 8 | Siswa | Filter visibilitas murid yang belum bayar/verified | 24/09/2026 | To Review |
| 9 | Finansial / Ortu | Penyesuaian tampilan tagihan ortu sesuai 3 skema sekolah (v1/v2/v3) | 24/09/2026 | High |
| 10 | Portal Ortu | Tampilkan nama sekolah di bawah sapaan orang tua | 24/09/2026 | UI Quick-fix |
| 11 | Auth | Perbaikan alur & mekanisme logout | 24/09/2026 | Bugfix |
| 12 | System | Optimalisasi performa respon backend | 23/09/2026 | Performance |
