<?php

namespace Tests\Feature\Daftar;

use App\Models\Kelas;
use App\Models\School;
use App\Models\SchoolAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UploadExcelTest extends TestCase
{
    use RefreshDatabase;

    private Kelas $kelas;
    private School $school;

    private function setupData(): void
    {
        User::create(['name' => 'SA', 'email' => 'sa@r.id', 'password' => bcrypt('x'), 'role' => 'super_admin', 'is_active' => true]);
        $this->school = School::create(['name' => 'SD IT Bawamai', 'pipeline_status' => 'sudah_mou', 'is_mou' => true]);
        $this->kelas = Kelas::create(['name' => 'Robo Kids']);
        // Harga tidak lagi tinggal di kelas: StudentImportService tidak membaca tarif sama sekali.
        // Tarif instansi (kalau nanti diperlukan) ada di schools.registration_fee / price_per_cycle.
    }

    private function token(): string
    {
        $admin = SchoolAdmin::create([
            'school_id' => $this->school->id,
            'name' => 'AS',
            'email' => 'as@b.id',
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);
        return $admin->createToken('t')->plainTextToken;
    }

    private function csvFile(string $body): UploadedFile
    {
        $header = "nama,tanggal_lahir,gender,ukuran_baju,kelas_asal,alergi\n";
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $header . $body);
        return new UploadedFile($path, 'murid.csv', 'text/csv', null, true);
    }

    public function test_preview_menandai_baris_error(): void
    {
        $this->setupData();
        $token = $this->token();

        // baris 1 valid, baris 2 nama kosong (error)
        $file = $this->csvFile("Budi,2017-01-01,L,M,3A,\n,2017-02-02,L,M,3A,\n");

        $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/sekolah/murid/preview-excel', ['class_id' => $this->kelas->id, 'file' => $file])
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.valid_count', 1)
            ->assertJsonPath('data.error_count', 1);

        $this->assertDatabaseCount('students', 0); // preview tidak menyimpan
    }

    public function test_import_menyimpan_baris_valid_saja(): void
    {
        $this->setupData();
        $token = $this->token();

        $file = $this->csvFile("Budi,2017-01-01,L,M,3A,\n,2017-02-02,L,M,3A,\nSiti,2018-03-03,P,S,2A,\n");

        $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/sekolah/murid/import-excel', ['class_id' => $this->kelas->id, 'file' => $file])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertDatabaseCount('students', 2);
        $this->assertDatabaseHas('students', ['name' => 'Budi', 'registration_type' => 'instansi']);
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_non_admin_sekolah_ditolak(): void
    {
        $this->setupData();
        $user = User::where('role', 'super_admin')->first();
        $token = $user->createToken('t')->plainTextToken;

        $file = $this->csvFile("Budi,2017-01-01,L,M,3A,\n");

        $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/sekolah/murid/import-excel', ['class_id' => $this->kelas->id, 'file' => $file])
            ->assertStatus(403);
    }
}
