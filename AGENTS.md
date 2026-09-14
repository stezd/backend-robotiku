# Agent Instructions

## Project Overview

Robotiku ERP Backend adalah aplikasi Laravel 13 berbasis PHP 8.3 untuk operasional sekolah, siswa, pendaftaran, pembayaran, keuangan, absensi, sesi kelas, dan e-rapor.

## Repository Structure

- `app/`: controller, model, service, request, import, export, dan dukungan aplikasi.
- `database/`: migration, factory, dan seeder.
- `routes/`: route web dan API. API menggunakan prefix `/api/v1`.
- `resources/`: view dan asset frontend.
- `tests/`: unit test dan feature test.
- `config/`: konfigurasi aplikasi dan integrasi.

## Local Setup

1. Pastikan PHP 8.3+, Composer, Node.js, npm, dan MySQL tersedia.
2. Jalankan `composer run setup` untuk memasang dependency, membuat `.env`, menghasilkan application key, menjalankan migration, dan membangun asset.
3. Tinjau `.env`, terutama `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD`.
4. Pastikan database `robotiku` sudah dibuat sebelum menjalankan migration jika menggunakan konfigurasi MySQL pada `.env.example`.

## Common Commands

```bash
composer run setup  # Initial project setup
composer run dev    # Laravel server, queue, logs, and Vite
composer run test   # PHPUnit test suite
php artisan route:list
npm run build       # Production frontend build
```

Pada Windows PowerShell, gunakan `New-Item database/database.sqlite -ItemType File` hanya jika sengaja mengganti konfigurasi database ke SQLite.

## Development Guidelines

- Baca implementasi dan test terkait sebelum mengubah perilaku.
- Pertahankan public API, struktur response, middleware, dan role authorization yang sudah ada kecuali perubahan memang diminta.
- Gunakan service atau request class yang sudah tersedia sebelum menambah abstraksi baru.
- Jangan memasukkan secret, token, credential, atau `APP_KEY` ke repository.
- Jangan mengubah file yang tidak berkaitan dengan task.
- Dokumentasikan perubahan user-facing di `README.md` atau dokumentasi terkait.
- Tambahkan atau perbarui test untuk perubahan perilaku.
- Gunakan format kode proyek yang ada dan jalankan formatter hanya pada file yang disentuh.

## Validation

Setelah perubahan kode:

1. Jalankan test yang paling dekat dengan area perubahan.
2. Jalankan `composer run test` jika perubahan menyentuh perilaku lintas modul atau kontrak API.
3. Jalankan `npm run build` jika mengubah asset frontend.
4. Periksa `git diff --check` sebelum menyelesaikan pekerjaan.

## Git Workflow

- `main`: production-ready.
- `development`: staging utama.
- `feature/<nama-fitur>`: pengembangan fitur.

Gunakan format commit:

```text
<type>(scope): <deskripsi>
```

Type yang digunakan: `feat`, `fix`, `refactor`, `docs`, `style`, `chore`, `perf`, dan `revert`.