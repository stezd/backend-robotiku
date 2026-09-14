# Robotiku ERP Backend

Backend untuk platform Robotiku yang menangani operasional sekolah, siswa, pendaftaran, pembayaran, keuangan, absensi, sesi kelas, e-rapor, dan konten landing page melalui REST API berbasis Laravel.

## Fitur Utama

- REST API dengan versioning pada prefix `/api/v1`
- Autentikasi token menggunakan Laravel Sanctum
- Manajemen sekolah, siswa, kelas, program, periode, dan pengguna
- Pendaftaran mandiri maupun melalui instansi
- Pembayaran, tagihan, verifikasi pembayaran, dan dashboard keuangan
- Absensi karyawan serta sesi kelas
- Progress siswa dan e-rapor dengan ekspor PDF/Excel
- Impor dan ekspor data siswa menggunakan Excel
- Frontend asset bundling menggunakan Vite dan Tailwind CSS

## Teknologi

- PHP 8.3+
- Laravel 13
- Laravel Sanctum
- SQLite, MySQL, MariaDB, PostgreSQL, atau SQL Server
- Vite dan Tailwind CSS 4
- PHPUnit 12

## Prasyarat

Pastikan perangkat berikut sudah terpasang:

- PHP 8.3 atau lebih baru beserta ekstensi yang dibutuhkan Laravel
- Composer
- Node.js dan npm
- Database yang sesuai dengan konfigurasi `.env`

## Instalasi Lokal

1. Clone repository dan masuk ke direktori proyek:

   ```bash
   git clone <url-repository>
   cd backend-robotiku
   ```

2. Jalankan setup otomatis:

   ```bash
   composer run setup
   ```

   Perintah tersebut memasang dependency PHP, membuat `.env`, menghasilkan application key, menjalankan migration, memasang dependency JavaScript, dan membuat build frontend.

3. Tinjau konfigurasi `.env`. Secara default aplikasi menggunakan SQLite. Untuk memakai database lain, sesuaikan `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD`.

4. Jika menggunakan SQLite dan file database belum tersedia, buat file-nya lalu jalankan migration:

   ```bash
   touch database/database.sqlite
   php artisan migrate
   ```

   Di Windows PowerShell, gunakan `New-Item database/database.sqlite -ItemType File` sebagai pengganti `touch`.

## Menjalankan Aplikasi

Untuk menjalankan server Laravel saja:

```bash
php artisan serve
```

Untuk menjalankan server aplikasi, worker queue, log viewer, dan Vite secara bersamaan:

```bash
composer run dev
```

API lokal tersedia pada `http://localhost:8000`. Endpoint API menggunakan prefix `http://localhost:8000/api/v1`.

Build asset frontend untuk lingkungan produksi:

```bash
npm run build
```

## Contoh Penggunaan API

Contoh mengambil daftar program publik:

```bash
curl http://localhost:8000/api/v1/programs
```

Contoh login pengguna internal:

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'
```

Gunakan token yang dikembalikan oleh endpoint login pada endpoint terproteksi:

```bash
curl http://localhost:8000/api/v1/auth/me \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <token>"
```

> Detail payload, role, validasi, dan daftar endpoint dikelola di controller serta request class terkait. Dokumentasi API yang lebih lengkap sebaiknya ditempatkan terpisah dari README utama.

## Pengujian

Jalankan seluruh test dengan:

```bash
composer run test
```

Konfigurasi PHPUnit menggunakan SQLite in-memory sehingga test tidak memerlukan database development terpisah.

## Struktur Direktori

```text
app/            Logika aplikasi, controller, model, service, dan request
database/       Migration, factory, dan seeder
routes/         Route web dan API
resources/      Asset frontend dan view
tests/          Unit test dan feature test
public/         Entry point serta asset publik
```

## Branching dan Commit

Branch utama yang digunakan:

- `main`: file siap produksi dan deployment
- `development`: staging utama sebelum masuk ke `main`
- `feature/<nama-fitur>`: pengembangan fitur baru

Gunakan format commit berikut:

```text
<type>(scope): <deskripsi>
```

Contoh:

```text
feat(login): tambah validasi client-side pada autentikasi
```

Type commit yang disarankan: `feat`, `fix`, `refactor`, `docs`, `style`, `chore`, `perf`, dan `revert`.

## Lisensi

Proyek ini menggunakan lisensi [MIT](https://opensource.org/licenses/MIT), sesuai deklarasi pada `composer.json`.