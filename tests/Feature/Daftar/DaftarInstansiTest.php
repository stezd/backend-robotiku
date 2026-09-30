<?php

namespace Tests\Feature\Daftar;

use App\Models\Kelas;
use App\Models\School;
use App\Models\SchoolAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DaftarInstansiTest extends TestCase
{
    use RefreshDatabase;

    private Kelas $kelas;
    private School $school;

    private function setupData(float $reg = 100000, float $cycle = 200000): void
    {
        User::create(['name' => 'SA', 'email' => 'sa@r.id', 'password' => bcrypt('x'), 'role' => 'super_admin', 'is_active' => true]);
        User::create(['name' => 'AK', 'email' => 'ak@r.id', 'password' => bcrypt('x'), 'role' => 'admin_keuangan', 'is_active' => true]);

        // instansi: harga datang dari sekolah (RegistrationService::registerInstansi)
        $this->school = School::create([
            'name' => 'SD IT Bawamai',
            'pipeline_status' => 'sudah_mou',
            'is_mou' => true,
            'registration_fee' => $reg,
            'price_per_cycle' => $cycle,
        ]);
        $this->kelas = Kelas::create(['name' => 'IoT Junior']);
    }

    private function schoolAdminToken(): string
    {
        $admin = SchoolAdmin::create([
            'school_id' => $this->school->id,
            'name' => 'Admin Sekolah',
            'email' => 'as@bawamai.id',
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);

        return $admin->createToken('test')->plainTextToken;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Murid Instansi',
            'birth_date' => '2017-03-03',
            'gender' => 'P',
            'shirt_size' => 'L',
            'school_grade' => '3A',
            'allergy_notes' => null,
            'photo_permission' => true,
            'class_id' => $this->kelas->id,
        ], $override);
    }

    public function test_admin_sekolah_daftar_murid(): void
    {
        $this->setupData();
        $token = $this->schoolAdminToken();

        $res = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/sekolah/murid', $this->payload());

        $res->assertStatus(201)
            ->assertJsonPath('data.student.school_id', $this->school->id)
            ->assertJsonPath('data.invoice.total_amount', '300000.00'); // 100rb + 200rb

        $this->assertDatabaseHas('students', [
            'name' => 'Murid Instansi',
            'registration_type' => 'instansi',
            'school_id' => $this->school->id,
        ]);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_user_internal_tidak_boleh(): void
    {
        $this->setupData();
        $user = User::where('role', 'super_admin')->first();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/sekolah/murid', $this->payload())
            ->assertStatus(403);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->setupData();
        $this->postJson('/api/v1/sekolah/murid', $this->payload())->assertStatus(401);
    }

    public function test_duplikat_ditolak(): void
    {
        $this->setupData();
        $token = $this->schoolAdminToken();

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/sekolah/murid', $this->payload())->assertStatus(201);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/sekolah/murid', $this->payload())->assertStatus(422);
    }
}
