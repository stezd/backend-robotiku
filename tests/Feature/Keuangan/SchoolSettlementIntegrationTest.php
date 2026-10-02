<?php

namespace Tests\Feature\Keuangan;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolAdmin;
use App\Models\SchoolSettlement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolSettlementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createSchoolAdmin(School $school): SchoolAdmin
    {
        return SchoolAdmin::create([
            'school_id' => $school->id,
            'name' => 'Admin ' . $school->name,
            'email' => 'admin-' . uniqid() . '@sekolah.id',
            'phone' => '0812' . rand(10000000, 99999999),
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
    }

    private function createFinanceAdmin(): User
    {
        return User::create([
            'name' => 'Finance Staff',
            'email' => 'finance-' . uniqid() . '@robotiku.id',
            'password' => bcrypt('password123'),
            'role' => 'admin_keuangan',
            'is_active' => true,
        ]);
    }

    public function test_complete_settlement_reconciliation_and_dashboard_revenue_lifecycle(): void
    {
        Storage::fake('local');

        // ==========================================
        // 1. SETUP: Sekolah Mitra & Siswa Instansi
        // ==========================================
        $school = School::create([
            'name' => 'SD Harapan Bangsa',
            'commission_percent' => 20, // 20% komisi sekolah, 80% Robotiku
        ]);

        $schoolAdmin = $this->createSchoolAdmin($school);
        $financeAdmin = $this->createFinanceAdmin();

        $studentA = Student::create([
            'student_code' => 'STU-INST-A',
            'name' => 'Ananda Rizky',
            'gender' => 'L',
            'status' => 'aktif',
            'is_verified' => false,
            'school_id' => $school->id,
            'registration_type' => 'instansi',
        ]);

        $studentB = Student::create([
            'student_code' => 'STU-INST-B',
            'name' => 'Bunga Citra',
            'gender' => 'P',
            'status' => 'aktif',
            'is_verified' => false,
            'school_id' => $school->id,
            'registration_type' => 'instansi',
        ]);

        // Invoice masing-masing 250.000 (Gross = 500.000)
        $invoiceA = Invoice::create([
            'invoice_number' => 'INV-2026-A',
            'student_id' => $studentA->id,
            'base_amount' => 250000,
            'total_amount' => 250000,
            'status' => 'belum_bayar',
        ]);

        $invoiceB = Invoice::create([
            'invoice_number' => 'INV-2026-B',
            'student_id' => $studentB->id,
            'base_amount' => 250000,
            'total_amount' => 250000,
            'status' => 'belum_bayar',
        ]);

        // Wali murid A upload bukti bayar ke rekening sekolah
        $paymentA = Payment::create([
            'invoice_id' => $invoiceA->id,
            'proof_file' => 'payments/proof-a.jpg',
            'status' => 'menunggu_verifikasi',
            'uploader_type' => 'parent',
            'uploader_id' => 1,
        ]);

        // ========================================================
        // 2. ACTOR: Admin Sekolah Verifikasi Pembayaran Murid A & B
        // ========================================================
        Sanctum::actingAs($schoolAdmin);

        // Verifikasi pembayaran A oleh Admin Sekolah
        $resVerifyA = $this->postJson("/api/v1/sekolah/pembayaran/{$paymentA->id}/verifikasi", [
            'action' => 'approve',
            'note' => 'Pembayaran via kasir sekolah diverifikasi.',
        ])->assertOk();

        $this->assertEquals('lunas', $invoiceA->fresh()->status);
        $this->assertEquals('diverifikasi', $paymentA->fresh()->status);

        // Murid B bayar tunai langsung lunas
        $invoiceB->update(['status' => 'lunas']);

        // ========================================================
        // 3. ACTOR: Admin Sekolah Cek Invoice Siap Disetor
        // ========================================================
        $resAvailable = $this->getJson('/api/v1/sekolah/setoran/tersedia')->assertOk();
        $resAvailable->assertJson([
            'status' => true,
            'data' => [
                'gross' => 500000,
                'commission_percent' => 20,
                'commission_amount' => 100000,
                'net' => 400000,
            ],
        ]);

        // ========================================================
        // 4. ACTOR: Admin Sekolah Mengajukan Setoran (Settlement)
        // ========================================================
        $dummyProof = UploadedFile::fake()->create('bukti-setoran-bank.jpg', 300, 'image/jpeg');

        $resSettlement = $this->postJson('/api/v1/sekolah/setoran', [
            'invoice_ids' => [$invoiceA->id, $invoiceB->id],
            'proof' => $dummyProof,
        ])->assertStatus(201);

        $settlementId = $resSettlement->json('data.id');
        $settlement = SchoolSettlement::findOrFail($settlementId);

        $this->assertEquals('menunggu_verifikasi', $settlement->status);
        $this->assertEquals(500000, $settlement->gross_amount);
        $this->assertEquals(100000, $settlement->commission_amount);
        $this->assertEquals(400000, $settlement->net_amount);

        // ========================================================
        // 5. ACTOR: Admin Keuangan Robotiku Melihat Setoran Masuk
        // ========================================================
        Sanctum::actingAs($financeAdmin);

        // List setoran pending
        $resPending = $this->getJson('/api/v1/keuangan/setoran?status=menunggu_verifikasi')->assertOk();
        $this->assertEquals(1, $resPending->json('data.total'));

        // Cek dashboard SEBELUM verifikasi setoran: omzet sekolah belum masuk
        $resDashBefore = $this->getJson('/api/v1/keuangan/dashboard')->assertOk();
        $this->assertEquals(0, $resDashBefore->json('data.pendapatan_bulan_ini'));

        // Preview bukti transfer setoran inline (Phase 1 endpoint)
        $resProof = $this->get("/api/v1/keuangan/setoran/{$settlement->id}/proof");
        $resProof->assertOk();
        $this->assertStringStartsWith('inline', (string) $resProof->headers->get('Content-Disposition'));

        // ========================================================
        // 6. ACTOR: Admin Keuangan Approve Setoran (Phase 2 Core)
        // ========================================================
        $resApprove = $this->postJson("/api/v1/keuangan/setoran/{$settlement->id}/verifikasi", [
            'action' => 'approve',
            'note' => 'Dana setoran Rp 400.000 sudah masuk ke rekening BCA Robotiku.',
        ])->assertOk();

        // Idempotency: Mencoba verifikasi kedua kalinya harus ditolak (HTTP 422)
        $this->postJson("/api/v1/keuangan/setoran/{$settlement->id}/verifikasi", [
            'action' => 'approve',
        ])->assertStatus(422);

        // ========================================================
        // 7. VERIFIKASI STATE AKHIR: Rekonsiliasi Tuntas & Bersih
        // ========================================================
        $settlement->refresh();
        $this->assertEquals('diverifikasi', $settlement->status);
        $this->assertEquals($financeAdmin->id, $settlement->verified_by);
        $this->assertNotNull($settlement->verified_at);

        // Invoice murid harus lunas
        $this->assertEquals('lunas', $invoiceA->fresh()->status);
        $this->assertEquals('lunas', $invoiceB->fresh()->status);

        // Siswa berstatus terverifikasi
        $this->assertTrue((bool) $studentA->fresh()->is_verified);
        $this->assertTrue((bool) $studentB->fresh()->is_verified);

        // ========================================================
        // 8. VERIFIKASI DASHBOARD FINANCE: Omzet Masuk & Akurat
        // ========================================================
        $resDashAfter = $this->getJson('/api/v1/keuangan/dashboard')->assertOk();

        // Omzet yang masuk adalah net_amount (400.000), BUKAN gross 500.000 dan BUKAN dobel dengan invoice 250k
        $this->assertEquals(400000, $resDashAfter->json('data.pendapatan_bulan_ini'));
        $this->assertEquals(400000, $resDashAfter->json('data.pendapatan_tahun_ini'));

        // Cek riwayat setoran terbaru di dashboard menampilkan data sekolah
        $this->assertEquals('SD Harapan Bangsa', $resDashAfter->json('data.setoran_terbaru.0.school_name'));
        $this->assertEquals(400000, $resDashAfter->json('data.setoran_terbaru.0.net_amount'));
    }
}
