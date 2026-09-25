<?php

namespace Tests\Feature\Admin;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardStudentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): void
    {
        $u = User::create(['name' => $role, 'email' => $role . '-' . uniqid() . '@r.id', 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        Sanctum::actingAs($u);
    }

    public function test_dashboard_kpi(): void
    {
        Student::create(['student_code' => 'A1', 'name' => 'A', 'gender' => 'L', 'status' => 'aktif', 'registration_type' => 'mandiri', 'is_verified' => true]);
        Student::create(['student_code' => 'A2', 'name' => 'B', 'gender' => 'P', 'status' => 'nonaktif', 'registration_type' => 'mandiri', 'is_verified' => true]);
        Invoice::create(['invoice_number' => 'INV-1', 'student_id' => 1, 'base_amount' => 200000, 'total_amount' => 350000, 'status' => 'lunas']);

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.siswa_aktif', 1)
            ->assertJsonPath('data.siswa_total', 2)
            ->assertJsonPath('data.pendapatan', 350000);
    }

    public function test_list_dan_filter_siswa(): void
    {
        Student::create(['student_code' => 'A1', 'name' => 'Andi', 'gender' => 'L', 'status' => 'aktif', 'registration_type' => 'mandiri', 'is_verified' => true]);
        Student::create(['student_code' => 'A2', 'name' => 'Budi', 'gender' => 'L', 'status' => 'cuti', 'registration_type' => 'instansi', 'is_verified' => true]);

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/siswa?status=cuti')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/v1/siswa?search=Andi')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_ubah_status_membuat_log(): void
    {
        $s = Student::create(['student_code' => 'A1', 'name' => 'Andi', 'gender' => 'L', 'status' => 'aktif', 'registration_type' => 'mandiri']);
        $this->actingAsRole('super_admin');

        $this->patchJson("/api/v1/siswa/{$s->id}/status", ['status' => 'berhenti'])->assertOk();

        $this->assertDatabaseHas('student_status_logs', [
            'student_id' => $s->id,
            'old_status' => 'aktif',
            'new_status' => 'nonaktif',
            'changed_by_type' => 'user',
        ]);
    }

    public function test_status_sama_ditolak(): void
    {
        $s = Student::create(['student_code' => 'A1', 'name' => 'Andi', 'gender' => 'L', 'status' => 'aktif', 'registration_type' => 'mandiri']);
        $this->actingAsRole('admin');

        $this->patchJson("/api/v1/siswa/{$s->id}/status", ['status' => 'aktif'])->assertStatus(422);
    }

    public function test_marketing_tidak_boleh_dashboard(): void
    {
        $this->actingAsRole('marketing');
        $this->getJson('/api/v1/dashboard')->assertStatus(403);
    }
}
