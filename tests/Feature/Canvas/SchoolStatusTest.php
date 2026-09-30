<?php

namespace Tests\Feature\Canvas;

use App\Models\School;
use App\Models\SchoolStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SchoolStatusTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): void
    {
        $user = User::create([
            'name' => $role,
            'email' => $role . '-' . uniqid() . '@r.id',
            'password' => bcrypt('x'),
            'role' => $role,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);
    }

    public function test_ubah_status_membuat_log_dan_set_mou(): void
    {
        $school = School::create(['name' => 'SD X', 'pipeline_status' => 'prospek']);
        $this->actingAsRole('marketing');

        $this->patchJson("/api/v1/canvas/schools/{$school->id}/status", [
            'pipeline_status' => 'sudah_mou',
            'note' => 'Tanda tangan MOU',
        ])->assertOk()->assertJsonPath('data.is_mou', true);

        $this->assertDatabaseHas('school_status_logs', [
            'school_id' => $school->id,
            'old_status' => 'prospek',
            'new_status' => 'sudah_mou',
        ]);
    }

    public function test_log_status_immutable(): void
    {
        $school = School::create(['name' => 'SD Y', 'pipeline_status' => 'prospek']);
        $log = SchoolStatusLog::create([
            'school_id' => $school->id,
            'old_status' => 'prospek',
            'new_status' => 'dalam_proses',
        ]);

        $this->expectException(RuntimeException::class);
        $log->update(['new_status' => 'sudah_mou']); // harus ditolak trait Immutable
    }

    public function test_tambah_catatan(): void
    {
        $school = School::create(['name' => 'SD Z', 'pipeline_status' => 'prospek']);
        $this->actingAsRole('marketing');

        // `kind` wajib (in:pertemuan,audit) dan divalidasi inline di dalam controller,
        // bukan di StoreNoteRequest. `audit` dipilih karena itu default kolomnya dan
        // tidak menuntut foto + latitude + longitude seperti `pertemuan`.
        $this->postJson("/api/v1/canvas/schools/{$school->id}/notes", [
            'kind' => 'audit',
            'note' => 'Follow up minggu depan',
        ])->assertStatus(201);

        $this->assertDatabaseHas('school_notes', ['school_id' => $school->id, 'note' => 'Follow up minggu depan']);
    }

    public function test_dropdown_mou_hanya_sekolah_mou(): void
    {
        School::create(['name' => 'SD MOU', 'pipeline_status' => 'sudah_mou', 'is_mou' => true]);
        School::create(['name' => 'SD Prospek', 'pipeline_status' => 'prospek', 'is_mou' => false]);

        $this->actingAsRole('admin');

        $this->getJson('/api/v1/sekolah/mou')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'SD MOU');
    }

    public function test_trainer_tidak_boleh_ubah_status(): void
    {
        $school = School::create(['name' => 'SD W', 'pipeline_status' => 'prospek']);
        $this->actingAsRole('trainer');

        $this->patchJson("/api/v1/canvas/schools/{$school->id}/status", ['pipeline_status' => 'sudah_mou'])
            ->assertStatus(403);
    }
}
