<?php

namespace Tests\Feature\Admin;

use App\Models\Kelas;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $u = User::create(['name' => $role, 'email' => $role . '-' . uniqid() . '@r.id', 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        Sanctum::actingAs($u);
        return $u;
    }

    private function student(?int $programId = null): Student
    {
        return Student::create([
            'student_code'      => 'S' . uniqid(),
            'name'              => 'Murid',
            'gender'            => 'L',
            'status'            => 'aktif',
            'registration_type' => 'mandiri',
            'is_verified'       => true,
            'program_id'        => $programId,
        ]);
    }

    public function test_buat_kelas_dengan_trainer(): void
    {
        $trainer = User::create(['name' => 'T', 'email' => 't@r.id', 'password' => bcrypt('x'), 'role' => 'trainer', 'is_active' => true]);
        $program = Program::create([
            'name' => 'Robotika Dasar',
            'level' => 'beginner',
            'registration_fee' => 150000,
            'price_per_cycle' => 200000,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $this->actingAsRole('admin');

        // Kontrak sekarang (StoreClassRequest):
        // - `program_id` wajib
        // - trainer dikirim sebagai array `trainers[]`, bukan `trainer_id` di level atas
        // - `total_periods` wajib karena kelas ini tanpa school_id (kelas mandiri)
        $this->postJson('/api/v1/kelas', [
            'program_id' => $program->id,
            'name' => 'Robo Kids',
            'capacity' => 15,
            'meetings_per_period' => 4,
            'total_periods' => 6,
            'trainers' => [['trainer_id' => $trainer->id, 'role' => 'utama']],
        ])->assertStatus(201)->assertJsonPath('data.name', 'Robo Kids');
    }

    public function test_trainer_id_harus_role_trainer(): void
    {
        $admin = $this->actingAsRole('admin');
        // pakai id admin (bukan trainer) → gagal validasi
        $this->postJson('/api/v1/kelas', ['name' => 'X', 'trainer_id' => $admin->id])
            ->assertStatus(422);
    }

    public function test_assign_murid_tanpa_duplikat(): void
    {
        $this->actingAsRole('admin');
        $program = Program::create([
            'name'             => 'Robotika Dasar',
            'level'            => 'beginner',
            'registration_fee' => 150000,
            'price_per_cycle'  => 200000,
            'is_active'        => true,
            'is_visible'       => true,
        ]);
        $kelas = Kelas::create(['name' => 'A', 'program_id' => $program->id]);
        $s1 = $this->student($program->id);
        $s2 = $this->student($program->id);

        $this->postJson("/api/v1/kelas/{$kelas->id}/murid", ['student_ids' => [$s1->id, $s2->id]])->assertOk();
        // assign ulang s1 → tidak dobel
        $this->postJson("/api/v1/kelas/{$kelas->id}/murid", ['student_ids' => [$s1->id]])
            ->assertOk()->assertJsonPath('data.total_in_class', 2);

        $this->assertDatabaseCount('class_students', 2);
    }

    public function test_trainer_tidak_boleh_kelola_kelas(): void
    {
        $this->actingAsRole('trainer');
        $this->postJson('/api/v1/kelas', ['name' => 'X'])->assertStatus(403);
    }
}
