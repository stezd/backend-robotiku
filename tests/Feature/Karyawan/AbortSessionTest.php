<?php

namespace Tests\Feature\Karyawan;

use App\Models\Attendance;
use App\Models\Kelas;
use App\Models\Session;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbortSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('s3');
    }

    private function createTrainer(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Trainer Test',
            'email' => 'trainer_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'trainer',
            'is_active' => true,
        ], $attributes));
    }

    private function createAdmin(string $role = 'admin'): User
    {
        return User::create([
            'name' => ucfirst($role).' Test',
            'email' => $role.'_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createClass(User $trainer): Kelas
    {
        return Kelas::create([
            'name' => 'Kelas Robotika',
            'trainer_id' => $trainer->id,
            'meetings_per_period' => 4,
        ]);
    }

    private function createActiveStudent(Kelas $kelas): Student
    {
        $student = Student::create([
            'student_code' => 'STD-'.uniqid(),
            'name' => 'Murid Aktif',
            'gender' => 'L',
            'status' => 'aktif',
            'registration_type' => 'mandiri',
        ]);
        $kelas->students()->attach($student->id, ['joined_at' => now()]);

        return $student;
    }

    public function test_trainer_pemilik_kelas_berhasil_membatalkan_sesi_started(): void
    {
        $trainer = $this->createTrainer();
        $kelas = $this->createClass($trainer);
        $student = $this->createActiveStudent($kelas);

        $startPhotoPath = 'sessions/start_'.uniqid().'.webp';
        $attPhotoPath = 'attendances/att_'.uniqid().'.webp';

        Storage::disk('local')->put($startPhotoPath, 'fake-start-image');
        Storage::disk('local')->put($attPhotoPath, 'fake-attendance-image');

        $session = Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainer->id,
            'status' => 'started',
            'is_manual' => false,
            'started_at' => now(),
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
            'start_photo' => $startPhotoPath,
        ]);

        $att = Attendance::create([
            'class_id' => $kelas->id,
            'student_id' => $student->id,
            'trainer_id' => $trainer->id,
            'session_id' => $session->id,
            'status' => 'hadir',
            'photo' => $attPhotoPath,
            'attended_at' => now(),
        ]);

        Storage::disk('local')->assertExists($startPhotoPath);
        Storage::disk('local')->assertExists($attPhotoPath);

        Sanctum::actingAs($trainer);

        $response = $this->deleteJson("/api/v1/sesi/{$session->id}/batal");

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Sesi berhasil dibatalkan dan dihapus.');

        $this->assertDatabaseMissing('class_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('attendances', ['id' => $att->id]);

        Storage::disk('local')->assertMissing($startPhotoPath);
        Storage::disk('local')->assertMissing($attPhotoPath);
    }

    public function test_admin_dan_super_admin_berhasil_membatalkan_sesi_trainer_lain(): void
    {
        $trainer = $this->createTrainer();
        $kelas = $this->createClass($trainer);
        $this->createActiveStudent($kelas);

        $sessionAdmin = Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainer->id,
            'status' => 'started',
            'is_manual' => false,
            'started_at' => now(),
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
        ]);

        $admin = $this->createAdmin('admin');
        Sanctum::actingAs($admin);

        $responseAdmin = $this->deleteJson("/api/v1/sesi/{$sessionAdmin->id}/batal");
        $responseAdmin->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Sesi berhasil dibatalkan dan dihapus.');

        $this->assertDatabaseMissing('class_sessions', ['id' => $sessionAdmin->id]);

        $sessionSuperAdmin = Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainer->id,
            'status' => 'started',
            'is_manual' => false,
            'started_at' => now(),
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
        ]);

        $superAdmin = $this->createAdmin('super_admin');
        Sanctum::actingAs($superAdmin);

        $responseSuperAdmin = $this->deleteJson("/api/v1/sesi/{$sessionSuperAdmin->id}/batal");
        $responseSuperAdmin->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Sesi berhasil dibatalkan dan dihapus.');

        $this->assertDatabaseMissing('class_sessions', ['id' => $sessionSuperAdmin->id]);
    }

    public function test_trainer_lain_bukan_pengampu_ditolak_403(): void
    {
        $trainerA = $this->createTrainer();
        $trainerB = $this->createTrainer(['name' => 'Trainer B']);
        $kelas = $this->createClass($trainerA);
        $this->createActiveStudent($kelas);

        $session = Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainerA->id,
            'status' => 'started',
            'is_manual' => false,
            'started_at' => now(),
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
        ]);

        Sanctum::actingAs($trainerB);

        $response = $this->deleteJson("/api/v1/sesi/{$session->id}/batal");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Bukan sesi Anda.');

        $this->assertDatabaseHas('class_sessions', ['id' => $session->id]);
    }

    public function test_sesi_berstatus_ended_ditolak_422(): void
    {
        $trainer = $this->createTrainer();
        $kelas = $this->createClass($trainer);
        $this->createActiveStudent($kelas);

        $session = Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainer->id,
            'status' => 'ended',
            'is_manual' => false,
            'started_at' => now()->subHours(2),
            'ended_at' => now(),
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
        ]);

        Sanctum::actingAs($trainer);

        $response = $this->deleteJson("/api/v1/sesi/{$session->id}/batal");

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Sesi yang sudah selesai tidak dapat dibatalkan.');

        $this->assertDatabaseHas('class_sessions', ['id' => $session->id]);
    }

    public function test_setelah_sesi_dibatalkan_kelas_dapat_dimulai_ulang(): void
    {
        $trainer = $this->createTrainer();
        $kelas = $this->createClass($trainer);
        $this->createActiveStudent($kelas);

        Sanctum::actingAs($trainer);

        $startResponse = $this->postJson('/api/v1/sesi/mulai', [
            'class_id' => $kelas->id,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $startResponse->assertStatus(201)
            ->assertJsonPath('status', true);

        $sessionId = $startResponse->json('data.id');
        $this->assertDatabaseHas('class_sessions', ['id' => $sessionId, 'status' => 'started']);

        // Mencoba mulai ulang saat sesi masih 'started' harus gagal 422
        $retryResponse = $this->postJson('/api/v1/sesi/mulai', [
            'class_id' => $kelas->id,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'photo' => UploadedFile::fake()->image('selfie2.jpg'),
        ]);
        $retryResponse->assertStatus(422)
            ->assertJsonPath('message', 'Sesi langsung hari ini sudah dimulai.');

        // Batalkan sesi
        $cancelResponse = $this->deleteJson("/api/v1/sesi/{$sessionId}/batal");
        $cancelResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Sesi berhasil dibatalkan dan dihapus.');

        $this->assertDatabaseMissing('class_sessions', ['id' => $sessionId]);

        // Mulai ulang sesi setelah pembatalan harus berhasil 201
        $restartResponse = $this->postJson('/api/v1/sesi/mulai', [
            'class_id' => $kelas->id,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'photo' => UploadedFile::fake()->image('selfie3.jpg'),
        ]);

        $restartResponse->assertStatus(201)
            ->assertJsonPath('status', true);

        $newSessionId = $restartResponse->json('data.id');
        $this->assertNotEquals($sessionId, $newSessionId);
        $this->assertDatabaseHas('class_sessions', ['id' => $newSessionId, 'status' => 'started']);
    }

    public function test_unauthenticated_ditolak_401(): void
    {
        $trainer = $this->createTrainer();
        $kelas = $this->createClass($trainer);

        $session = Session::create([
            'class_id' => $kelas->id,
            'trainer_id' => $trainer->id,
            'status' => 'started',
            'is_manual' => false,
            'started_at' => now(),
            'start_latitude' => -6.200000,
            'start_longitude' => 106.816666,
        ]);

        $response = $this->deleteJson("/api/v1/sesi/{$session->id}/batal");

        $response->assertStatus(401);
        $this->assertDatabaseHas('class_sessions', ['id' => $session->id]);
    }
}
