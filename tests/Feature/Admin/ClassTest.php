<?php

namespace Tests\Feature\Admin;

use App\Models\Kelas;
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

    private function student(): Student
    {
        return Student::create(['student_code' => 'S' . uniqid(), 'name' => 'Murid', 'gender' => 'L', 'status' => 'aktif', 'registration_type' => 'mandiri']);
    }

    public function test_buat_kelas_dengan_trainer(): void
    {
        $trainer = User::create(['name' => 'T', 'email' => 't@r.id', 'password' => bcrypt('x'), 'role' => 'trainer', 'is_active' => true]);
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/kelas', ['name' => 'Robo Kids', 'trainer_id' => $trainer->id, 'capacity' => 15])
            ->assertStatus(201)->assertJsonPath('data.name', 'Robo Kids');
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
        $kelas = Kelas::create(['name' => 'A']);
        $s1 = $this->student();
        $s2 = $this->student();

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
