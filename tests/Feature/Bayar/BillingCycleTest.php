<?php

namespace Tests\Feature\Bayar;

use App\Models\Attendance;
use App\Models\BillingMonth;
use App\Models\Invoice;
use App\Models\Kelas;
use App\Models\Program;
use App\Models\School;
use App\Models\Session;
use App\Models\Student;
use App\Models\User;
use App\Services\BillingCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $trainer;

    private Kelas $kelas;

    private Program $program;

    private function makeTrainerAndClass(array $classOverrides = []): void
    {
        $this->trainer = User::create([
            'name' => 'Trainer',
            'email' => 'tr'.uniqid().'@r.id',
            'password' => bcrypt('x'),
            'role' => 'trainer',
            'is_active' => true,
        ]);
        $this->program = Program::create([
            'name' => 'Robotika Dasar',
            'level' => 'beginner',
            'registration_fee' => 150000,
            'price_per_cycle' => 200000,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $this->kelas = Kelas::create(array_merge([
            'name' => 'Robotika',
            'trainer_id' => $this->trainer->id,
            'program_id' => $this->program->id,
        ], $classOverrides));
    }

    private function makeStudent(string $status = 'aktif', array $overrides = []): Student
    {
        return Student::create(array_merge([
            'student_code' => 'ROBO-MDR'.uniqid(),
            'name' => 'Andi',
            'gender' => 'L',
            'status' => $status,
            'registration_type' => 'mandiri',
            'program_id' => $this->program->id,
        ], $overrides));
    }

    private function attachStudent(Student $student): void
    {
        $student->classes()->attach($this->kelas->id, ['joined_at' => now()]);
    }

    private function hadir(Student $student, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Attendance::create([
                'class_id' => $this->kelas->id,
                'student_id' => $student->id,
                'trainer_id' => $this->trainer->id,
                'status' => 'hadir',
                'attended_at' => now(),
            ]);
        }
    }

    /**
     * Helper: buat N sesi yang sudah berstatus 'ended' untuk kelas.
     */
    private function createEndedSessions(int $count): void
    {
        $perPeriod = max(1, (int) ($this->kelas->meetings_per_period ?: 4));
        for ($i = 0; $i < $count; $i++) {
            Session::create([
                'class_id' => $this->kelas->id,
                'trainer_id' => $this->trainer->id,
                'week' => ($i % $perPeriod) + 1,
                'is_manual' => false,
                'started_at' => now()->subDays($count - $i),
                'ended_at' => now()->subDays($count - $i)->addHours(2),
                'status' => 'ended',
            ]);
        }
    }

    // ─── EXISTING TESTS (FIXED: handleAttendance → onAttendance + removed BillingSetting) ──────

    public function test_4_hadir_membuat_siklus_dan_invoice(): void
    {
        $this->makeTrainerAndClass();
        $student = $this->makeStudent();
        $this->attachStudent($student);
        $this->hadir($student, 4);

        $invoice = app(BillingCycleService::class)->onAttendance($student, $this->kelas);

        $this->assertNotNull($invoice);
        $this->assertEquals('200000.00', $invoice->total_amount);
        $this->assertNull($invoice->registration_fee);
        $this->assertDatabaseHas('billing_months', ['student_id' => $student->id, 'cycle_number' => 2]);
    }

    public function test_belum_4_hadir_tidak_membuat_invoice(): void
    {
        $this->makeTrainerAndClass();
        $student = $this->makeStudent();
        $this->attachStudent($student);
        $this->hadir($student, 3);

        $this->assertNull(app(BillingCycleService::class)->onAttendance($student, $this->kelas));
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_idempoten_tidak_dobel(): void
    {
        $this->makeTrainerAndClass();
        $student = $this->makeStudent();
        $this->attachStudent($student);
        $this->hadir($student, 4);

        $svc = app(BillingCycleService::class);
        $svc->onAttendance($student, $this->kelas);
        $second = $svc->onAttendance($student, $this->kelas);

        $this->assertNull($second);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_siswa_cuti_tidak_ditagih(): void
    {
        $this->makeTrainerAndClass();
        $student = $this->makeStudent('cuti');
        $this->attachStudent($student);
        $this->hadir($student, 4);

        $this->assertNull(app(BillingCycleService::class)->onAttendance($student, $this->kelas));
        $this->assertDatabaseCount('invoices', 0);
    }

    // ─── NEW: AC-001 — Period completion generates invoices for all active students ──────

    public function test_period_completion_generates_invoices_for_all_active_students(): void
    {
        $this->makeTrainerAndClass(['meetings_per_period' => 4]);
        $studentA = $this->makeStudent('aktif', ['name' => 'Andi']);
        $studentB = $this->makeStudent('aktif', ['name' => 'Budi']);
        $this->attachStudent($studentA);
        $this->attachStudent($studentB);

        // Buat 4 sesi ended → kelipatan meetings_per_period tercapai
        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        $this->assertCount(2, $invoices);
        $this->assertDatabaseHas('billing_months', ['student_id' => $studentA->id, 'cycle_number' => 2]);
        $this->assertDatabaseHas('billing_months', ['student_id' => $studentB->id, 'cycle_number' => 2]);
        $this->assertDatabaseCount('invoices', 2);

        foreach ($invoices as $inv) {
            $this->assertEquals('belum_bayar', $inv->status);
            $this->assertStringStartsWith('INV-', $inv->invoice_number);
        }
    }

    // ─── NEW: AC-002 — Murid izin/sakit tetap ditagihkan saat periode kelas selesai ──────

    public function test_absent_students_still_billed_on_period_completion(): void
    {
        $this->makeTrainerAndClass(['meetings_per_period' => 4]);
        $studentHadir = $this->makeStudent('aktif', ['name' => 'Hadir Boy']);
        $studentIzin = $this->makeStudent('aktif', ['name' => 'Izin Girl']);
        $this->attachStudent($studentHadir);
        $this->attachStudent($studentIzin);

        // Student hadir 4x, student izin hanya izin di semua sesi
        $this->hadir($studentHadir, 4);
        for ($i = 0; $i < 4; $i++) {
            Attendance::create([
                'class_id' => $this->kelas->id,
                'student_id' => $studentIzin->id,
                'trainer_id' => $this->trainer->id,
                'status' => 'izin',
                'attended_at' => now(),
            ]);
        }

        // 4 sesi ended
        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        // KEDUA murid harus mendapat invoice — termasuk yang izin
        $this->assertCount(2, $invoices);
        $this->assertDatabaseHas('invoices', ['student_id' => $studentHadir->id]);
        $this->assertDatabaseHas('invoices', ['student_id' => $studentIzin->id]);
    }

    // ─── NEW: AC-003 — self_managed school → invoice berstatus 'lunas' ──────

    public function test_self_managed_school_creates_lunas_invoice(): void
    {
        $school = School::create([
            'name' => 'SD Maju',
            'self_managed' => true,
            'price_per_cycle' => 300000,
        ]);
        $this->makeTrainerAndClass(['meetings_per_period' => 4, 'school_id' => $school->id]);
        $student = $this->makeStudent('aktif', [
            'name' => 'Andi',
            'school_id' => $school->id,
            'registration_type' => 'instansi',
        ]);
        $this->attachStudent($student);

        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        $this->assertCount(1, $invoices);
        $this->assertEquals('lunas', $invoices[0]->status);
        $this->assertEquals('300000.00', $invoices[0]->total_amount);
    }

    // ─── NEW: AC-003b — non-self_managed → invoice 'belum_bayar' ──────

    public function test_non_self_managed_school_creates_belum_bayar_invoice(): void
    {
        $school = School::create([
            'name' => 'SD Reguler',
            'self_managed' => false,
            'price_per_cycle' => 250000,
        ]);
        $this->makeTrainerAndClass(['meetings_per_period' => 4, 'school_id' => $school->id]);
        $student = $this->makeStudent('aktif', [
            'name' => 'Budi',
            'school_id' => $school->id,
            'registration_type' => 'instansi',
        ]);
        $this->attachStudent($student);

        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        $this->assertCount(1, $invoices);
        $this->assertEquals('belum_bayar', $invoices[0]->status);
    }

    // ─── NEW: AC-004 — Kuota habis → murid nonaktif, tidak ditagih ──────

    public function test_quota_exceeded_deactivates_student_no_invoice(): void
    {
        $this->makeTrainerAndClass(['meetings_per_period' => 4, 'total_periods' => 2]);
        $student = $this->makeStudent('aktif', ['period_quota' => 2]);
        $this->attachStudent($student);

        // Buat 8 sesi ended (2 periods sudah lewat → next = 3 > quota 2)
        $this->createEndedSessions(8);
        // Simulate: cycle 1 dan 2 sudah dibuat sebelumnya
        BillingMonth::create(['student_id' => $student->id, 'cycle_number' => 1, 'period_month' => 9, 'period_year' => 2026, 'status' => 'aktif']);
        BillingMonth::create(['student_id' => $student->id, 'cycle_number' => 2, 'period_month' => 9, 'period_year' => 2026, 'status' => 'aktif']);

        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        $this->assertEmpty($invoices);
        $this->assertEquals('nonaktif', $student->fresh()->status);
        $this->assertDatabaseHas('student_status_logs', [
            'student_id' => $student->id,
            'old_status' => 'aktif',
            'new_status' => 'nonaktif',
        ]);
        // Tidak ada invoice baru
        $this->assertDatabaseCount('invoices', 0);
    }

    // ─── NEW: AC-005 — Idempotensi trigger class-level ──────

    public function test_idempotent_trigger_no_duplicate_invoices(): void
    {
        $this->makeTrainerAndClass(['meetings_per_period' => 4]);
        $student = $this->makeStudent('aktif');
        $this->attachStudent($student);

        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);

        // Panggilan pertama — buat invoice
        $first = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);
        $this->assertCount(1, $first);

        // Panggilan kedua — harus idempoten, tidak ada duplikasi
        $second = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);
        $this->assertEmpty($second);

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('billing_months', 1);
    }

    // ─── NEW: Belum kelipatan → tidak generate invoice ──────

    public function test_non_multiple_session_count_no_invoice(): void
    {
        $this->makeTrainerAndClass(['meetings_per_period' => 4]);
        $student = $this->makeStudent('aktif');
        $this->attachStudent($student);

        // Hanya 3 sesi ended → belum kelipatan 4
        $this->createEndedSessions(3);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        $this->assertEmpty($invoices);
        $this->assertDatabaseCount('invoices', 0);
    }

    // ─── NEW: Murid nonaktif/cuti di kelas tidak ditagih ──────

    public function test_inactive_students_not_billed_on_period_completion(): void
    {
        $this->makeTrainerAndClass(['meetings_per_period' => 4]);
        $activeStudent = $this->makeStudent('aktif', ['name' => 'Aktif']);
        $inactiveStudent = $this->makeStudent('nonaktif', ['name' => 'Nonaktif']);
        $cutiStudent = $this->makeStudent('cuti', ['name' => 'Cuti']);
        $this->attachStudent($activeStudent);
        $this->attachStudent($inactiveStudent);
        $this->attachStudent($cutiStudent);

        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        // Hanya murid aktif yang ditagih
        $this->assertCount(1, $invoices);
        $this->assertEquals($activeStudent->id, $invoices[0]->student_id);
    }

    // ─── NEW: E2E HTTP — Session end endpoint triggers period billing cycle atomically ──────

    public function test_session_end_endpoint_triggers_billing_cycle_atomically(): void
    {
        Storage::fake('public');
        $this->makeTrainerAndClass(['meetings_per_period' => 4]);
        $student = $this->makeStudent('aktif', ['name' => 'Charlie']);
        $this->attachStudent($student);

        // Buat 3 sesi sebelumnya yang sudah ended
        $this->createEndedSessions(3);

        // Sesi ke-4 yang masih 'started'
        $session4 = Session::create([
            'class_id' => $this->kelas->id,
            'trainer_id' => $this->trainer->id,
            'start_latitude' => -6.2,
            'start_longitude' => 106.8,
            'start_photo' => 'start.jpg',
            'started_at' => now(),
            'status' => 'started',
        ]);

        Sanctum::actingAs($this->trainer);
        $photo = UploadedFile::fake()->image('end.jpg');

        $response = $this->postJson("/api/v1/sesi/{$session4->id}/selesai", [
            'latitude' => -6.2001,
            'longitude' => 106.8167,
            'photo' => $photo,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ended');

        // Invoice dan billing month harus tercipta via endpoint
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('billing_months', [
            'student_id' => $student->id,
            'cycle_number' => 2,
        ]);
    }

    // ─── NEW: Mixed school schemes in the same class ──────

    public function test_class_with_mixed_school_types_generates_correct_invoice_statuses(): void
    {
        $schoolSelf = School::create([
            'name' => 'Sekolah Mandiri SPP',
            'self_managed' => true,
            'price_per_cycle' => 300000,
        ]);
        $schoolReguler = School::create([
            'name' => 'Sekolah Reguler',
            'self_managed' => false,
            'price_per_cycle' => 250000,
        ]);

        $this->makeTrainerAndClass(['meetings_per_period' => 4]);

        $studentSelf = $this->makeStudent('aktif', [
            'name' => 'Murid Self Managed',
            'school_id' => $schoolSelf->id,
            'registration_type' => 'instansi',
        ]);
        $studentReguler = $this->makeStudent('aktif', [
            'name' => 'Murid Reguler',
            'school_id' => $schoolReguler->id,
            'registration_type' => 'instansi',
        ]);
        $this->attachStudent($studentSelf);
        $this->attachStudent($studentReguler);

        $this->createEndedSessions(4);
        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        $this->assertCount(2, $invoices);

        $invoiceSelf = collect($invoices)->firstWhere('student_id', $studentSelf->id);
        $invoiceReguler = collect($invoices)->firstWhere('student_id', $studentReguler->id);

        $this->assertNotNull($invoiceSelf);
        $this->assertNotNull($invoiceReguler);

        $this->assertEquals('lunas', $invoiceSelf->status);
        $this->assertEquals('300000.00', $invoiceSelf->total_amount);

        $this->assertEquals('belum_bayar', $invoiceReguler->status);
        $this->assertEquals('250000.00', $invoiceReguler->total_amount);
    }

    // ─── NEW: Personal quota overrides class total_periods ──────

    public function test_student_personal_period_quota_overrides_class_total_periods(): void
    {
        // Kelas memiliki total_periods = 5, tapi student ini hanya punya quota = 2
        $this->makeTrainerAndClass(['meetings_per_period' => 4, 'total_periods' => 5]);
        $student = $this->makeStudent('aktif', ['period_quota' => 2]);
        $this->attachStudent($student);

        // Buat 8 sesi ended (2 periods selesai → nextPeriod = 3 > quota 2)
        $this->createEndedSessions(8);
        BillingMonth::create(['student_id' => $student->id, 'cycle_number' => 1, 'period_month' => 9, 'period_year' => 2026, 'status' => 'aktif']);
        BillingMonth::create(['student_id' => $student->id, 'cycle_number' => 2, 'period_month' => 9, 'period_year' => 2026, 'status' => 'aktif']);

        $lastSession = Session::where('class_id', $this->kelas->id)->latest('id')->first();

        $svc = app(BillingCycleService::class);
        $invoices = $svc->triggerPeriodCompletionForClass($this->kelas, $lastSession);

        // Murid dinonaktifkan karena kuota pribadi (2) habis, meskipun kelas total_periods (5) belum habis
        $this->assertEmpty($invoices);
        $this->assertEquals('nonaktif', $student->fresh()->status);
        $this->assertDatabaseHas('student_status_logs', [
            'student_id' => $student->id,
            'old_status' => 'aktif',
            'new_status' => 'nonaktif',
        ]);
    }
}
