<?php

namespace Tests\Feature\Daftar;

use App\Models\DiscountCode;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentParent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DaftarMandiriTest extends TestCase
{
    use RefreshDatabase;

    private function setupData(float $reg = 150000, float $cycle = 200000): Program
    {
        // penerima notifikasi
        User::create(['name' => 'SA', 'email' => 'sa@r.id', 'password' => bcrypt('x'), 'role' => 'super_admin', 'is_active' => true]);
        User::create(['name' => 'AK', 'email' => 'ak@r.id', 'password' => bcrypt('x'), 'role' => 'admin_keuangan', 'is_active' => true]);

        // mandiri: harga datang dari program (RegistrationService::registerMandiri)
        return Program::create([
            'name' => 'Robotika Dasar',
            'level' => 'beginner',
            'registration_fee' => $reg,
            'price_per_cycle' => $cycle,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function payload(Program $program, array $override = []): array
    {
        return array_merge([
            'name' => 'Andi Kecil',
            'birth_date' => '2018-05-10',
            'gender' => 'L',
            'shirt_size' => 'M',
            'school_origin' => 'SD A',
            'school_grade' => '2B',
            'allergy_notes' => null,
            'photo_permission' => true,
            'parent_name' => 'Budi',
            'phone' => '081200000001',
            'program_id' => $program->id,
        ], $override);
    }

    public function test_daftar_mandiri_berhasil(): void
    {
        $program = $this->setupData();

        $res = $this->postJson('/api/v1/daftar', $this->payload($program));

        $res->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.invoice.total_amount', '350000.00'); // 150rb + 200rb

        $this->assertDatabaseHas('students', ['name' => 'Andi Kecil', 'registration_type' => 'mandiri']);
        $this->assertDatabaseHas('parents', ['phone' => '081200000001']);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('notifications', 2); // SA + AK
    }

    public function test_daftar_dengan_promo(): void
    {
        $program = $this->setupData();
        DiscountCode::create([
            'code' => 'HEMAT50',
            'type' => 'percentage',
            'value' => 50,
            'quota' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/v1/daftar', $this->payload($program, ['promo_code' => 'hemat50']));

        $res->assertStatus(201)
            ->assertJsonPath('data.invoice.discount_amount', '75000.00')   // 50% x 150rb
            ->assertJsonPath('data.invoice.total_amount', '275000.00');    // (150-75)+200

        $this->assertDatabaseHas('discount_codes', ['code' => 'HEMAT50', 'used_count' => 1]);
        $this->assertDatabaseCount('discount_usages', 1);
    }

    public function test_duplikat_nama_dan_tanggal_lahir_ditolak(): void
    {
        $program = $this->setupData();
        Student::create([
            'student_code' => 'ROBO-MDR001',
            'name' => 'Andi Kecil',
            'birth_date' => '2018-05-10',
            'gender' => 'L',
            'status' => 'aktif',
            'registration_type' => 'mandiri',
        ]);

        $this->postJson('/api/v1/daftar', $this->payload($program))->assertStatus(422);
    }

    public function test_ortu_existing_tidak_terduplikasi(): void
    {
        $program = $this->setupData();
        StudentParent::create(['name' => 'Budi', 'phone' => '081200000001']);

        $this->postJson('/api/v1/daftar', $this->payload($program, ['name' => 'Anak Dua', 'birth_date' => '2019-01-01']))
            ->assertStatus(201);

        $this->assertDatabaseCount('parents', 1); // tetap satu
    }

    public function test_program_id_tidak_valid(): void
    {
        $this->setupData();

        $this->postJson('/api/v1/daftar', $this->payload(new Program(['id' => 999]), ['program_id' => 999]))
            ->assertStatus(422);
    }
}
