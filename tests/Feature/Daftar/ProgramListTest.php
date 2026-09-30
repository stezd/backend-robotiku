<?php

namespace Tests\Feature\Daftar;

use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Daftar program publik.
     *
     * Catatan: dulu aturannya "hanya kelas berharga yang muncul", dan harga
     * disimpan per kelas di tabel billing_settings. Tabel itu dihapus (Juli 2026)
     * dan harga pindah ke programs. Filter yang benar-benar dipakai sekarang
     * adalah `is_visible` — lihat ProgramController::index().
     */
    public function test_hanya_program_visible_yang_muncul(): void
    {
        Program::create([
            'name' => 'Robo Kids',
            'level' => 'beginner',
            'registration_fee' => 150000,
            'price_per_cycle' => 200000,
            'is_active' => true,
            'is_visible' => true,
        ]);

        // disembunyikan → tidak muncul di daftar publik
        Program::create([
            'name' => 'Belum Tayang',
            'level' => 'beginner',
            'registration_fee' => 150000,
            'price_per_cycle' => 200000,
            'is_active' => true,
            'is_visible' => false,
        ]);

        $this->getJson('/api/v1/programs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Robo Kids')
            // kontrak baru: harga tersaji dari program (dulu dari billing_settings per kelas)
            ->assertJsonPath('data.0.registration_fee', 150000)
            ->assertJsonPath('data.0.price_per_cycle', 200000);
    }
}
