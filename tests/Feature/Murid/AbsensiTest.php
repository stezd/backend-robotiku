<?php

namespace Tests\Feature\Murid;

use App\Models\Kelas;
use App\Models\Program;
use App\Models\Session;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbsensiTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrainerClass(): array
    {
        $trainer = User::create([
            'name' => 'T',
            'email' => 'tr'.uniqid().'@r.id',
            'password' => bcrypt('x'),
            'role' => 'trainer',
            'is_active' => true,
        ]);
        $program = Program::create([
            'name' => 'Robotika Dasar',
            'level' => 'beginner',
            'registration_fee' => 150000,
            'price_per_cycle' => 200000,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $kelas = Kelas::create([
            'name' => 'Robo',
            'trainer_id' => $trainer->id,
            'program_id' => $program->id,
        ]);

        return [$trainer, $kelas];
    }

    private function makeStudent(Kelas $kelas): Student
    {
        $s = Student::create([
            'student_code' => 'ROBO-MDR'.uniqid(),
            'name' => 'Andi',
            'gender' => 'L',
            'status' => 'aktif',
            'registration_type' => 'mandiri',
            'program_id' => $kelas->program_id,
        ]);
        $s->classes()->attach($kelas->id, ['joined_at' => now()]);

        return $s;
    }

    private function makeSession(Kelas $kelas, User $trainer): Session
    {
        return Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainer->id,
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
            'start_photo' => 'start.jpg',
            'started_at' => now(),
            'status' => 'started',
        ]);
    }

    public function test_trainer_simpan_absensi(): void
    {
        [$trainer, $kelas] = $this->makeTrainerClass();
        $student = $this->makeStudent($kelas);
        $session = $this->makeSession($kelas, $trainer);
        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/sesi/{$session->id}/absensi", [
            'student_id' => $student->id,
            'status' => 'hadir',
            'report' => 'Aktif merakit robot.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'session_id' => $session->id,
            'student_id' => $student->id,
            'status' => 'hadir',
        ]);
    }

    public function test_absensi_ke_4_buat_invoice(): void
    {
        [$trainer, $kelas] = $this->makeTrainerClass();
        $student = $this->makeStudent($kelas);
        Sanctum::actingAs($trainer);

        for ($i = 1; $i <= 4; $i++) {
            $session = $this->makeSession($kelas, $trainer);
            $this->postJson("/api/v1/sesi/{$session->id}/absensi", [
                'student_id' => $student->id,
                'status' => 'hadir',
            ])->assertStatus(201);
        }

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('billing_months', ['student_id' => $student->id, 'cycle_number' => 2]);
    }

    public function test_trainer_tidak_bisa_absen_kelas_orang_lain(): void
    {
        [$trainerA, $kelasA] = $this->makeTrainerClass();
        $student = $this->makeStudent($kelasA);
        $sessionA = $this->makeSession($kelasA, $trainerA);

        $trainerB = User::create([
            'name' => 'TB',
            'email' => 'tb@r.id',
            'password' => bcrypt('x'),
            'role' => 'trainer',
            'is_active' => true,
        ]);
        Sanctum::actingAs($trainerB);

        $this->postJson("/api/v1/sesi/{$sessionA->id}/absensi", [
            'student_id' => $student->id,
            'status' => 'hadir',
        ])->assertStatus(403);
    }

    public function test_murid_di_luar_kelas_ditolak(): void
    {
        [$trainer, $kelas] = $this->makeTrainerClass();
        $session = $this->makeSession($kelas, $trainer);
        $luar = Student::create([
            'student_code' => 'ROBO-X',
            'name' => 'Luar',
            'gender' => 'P',
            'status' => 'aktif',
            'registration_type' => 'mandiri',
        ]);
        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/sesi/{$session->id}/absensi", [
            'student_id' => $luar->id,
            'status' => 'hadir',
        ])->assertStatus(422);
    }

    public function test_non_trainer_ditolak(): void
    {
        [$trainer, $kelas] = $this->makeTrainerClass();
        $session = $this->makeSession($kelas, $trainer);
        $student = $this->makeStudent($kelas);

        $ak = User::create([
            'name' => 'AK',
            'email' => 'ak@r.id',
            'password' => bcrypt('x'),
            'role' => 'admin_keuangan',
            'is_active' => true,
        ]);
        Sanctum::actingAs($ak);

        $this->postJson("/api/v1/sesi/{$session->id}/absensi", [
            'student_id' => $student->id,
            'status' => 'hadir',
        ])->assertStatus(403);
    }

    public function test_list_murid_hanya_kelas_trainer(): void
    {
        [$trainer, $kelas] = $this->makeTrainerClass();
        $this->makeStudent($kelas);
        $session = $this->makeSession($kelas, $trainer);

        // murid kelas trainer lain tidak boleh muncul
        $trainer2 = User::create([
            'name' => 'T2',
            'email' => 't2@r.id',
            'password' => bcrypt('x'),
            'role' => 'trainer',
            'is_active' => true,
        ]);
        $kelas2 = Kelas::create([
            'name' => 'Lain',
            'trainer_id' => $trainer2->id,
        ]);
        $this->makeStudent($kelas2);

        Sanctum::actingAs($trainer);
        $this->getJson("/api/v1/sesi/{$session->id}/murid")->assertOk()->assertJsonCount(1, 'data.students');
    }
}
