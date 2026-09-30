<?php

namespace App\Services;

use App\Models\BillingMonth;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentParent;
use App\Models\User;
use App\Support\Phone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationService
{
    public function __construct(private PromoService $promo) {}

    /**
     * Pendaftaran mandiri oleh Orang Tua.
     * @throws DomainException
     */
    public function registerMandiri(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $program = Program::where('is_active', true)->find($data['program_id']);
            if (! $program) {
                throw new DomainException('Program tidak ditemukan atau tidak aktif.');
            }

            $registrationFee = (float) $program->registration_fee;
            $pricePerCycle   = (float) $program->price_per_cycle;

            // Validasi promo (opsional) — hanya untuk registration_fee
            $promo = null;
            $discount = 0.0;
            if (! empty($data['promo_code'])) {
                [$promo, $discount] = $this->promo->validate($data['promo_code'], $registrationFee);
            }

            // Cek duplikat: nama + tanggal lahir
            $dup = Student::where('name', $data['name'])
                ->whereDate('birth_date', $data['birth_date'])
                ->exists();
            if ($dup) {
                throw new DomainException('Siswa dengan nama & tanggal lahir yang sama sudah terdaftar.');
            }

            // Orang tua: cari via HP, buat kalau belum ada
            $parent = StudentParent::updateOrCreate(
                ['phone' => Phone::normalize($data['phone'])],
                [
                    'name'      => $data['parent_name'],
                    'greeting'  => $data['greeting'] ?? null,
                    'phone_alt' => isset($data['phone_alt']) ? Phone::normalize($data['phone_alt']) : null,
                ]
            );

            // Buat siswa
            $student = Student::create([
                'student_code'      => $this->generateStudentCode(),
                'name'              => $data['name'],
                'birth_date'        => $data['birth_date'],
                'gender'            => $data['gender'],
                'shirt_size'        => $data['shirt_size'] ?? null,
                'school_origin'     => $data['school_origin'] ?? null,
                'school_grade'      => $data['school_grade'] ?? null,
                'allergy_notes'     => $data['allergy_notes'] ?? null,
                'photo_permission'  => $data['photo_permission'],
                'parent_id'         => $parent->id,
                'program_id'        => $program->id,        // ← kunci
                'status'            => 'aktif',
                'registration_type' => 'mandiri',
            ]);

            // Siklus tagihan pertama
            $billingMonth = BillingMonth::create([
                'student_id'   => $student->id,
                'cycle_number' => 1,
                'period_month' => (int) now()->format('n'),
                'period_year'  => (int) now()->format('Y'),
                'status'       => 'aktif',
            ]);

            // Invoice pertama: registration_fee - diskon + price_per_cycle
            $total = max(0, $registrationFee - $discount) + $pricePerCycle;

            $invoice = Invoice::create([
                'invoice_number'   => 'TMP-' . Str::uuid(),
                'student_id'       => $student->id,
                'billing_month_id' => $billingMonth->id,
                'base_amount'      => $pricePerCycle,
                'registration_fee' => $registrationFee,
                'discount_amount'  => $discount,
                'total_amount'     => $total,
                'due_date'         => now()->addDays(7),
                'status'           => 'belum_bayar',
            ]);
            $invoice->update([
                'invoice_number' => 'INV-' . now()->format('Ymd') . '-' . str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT),
            ]);

            // Pakai promo (atomik: kuota + catat usage)
            if ($promo) {
                $this->promo->apply($promo, $student->id, $invoice->id);
            }

            // Notifikasi in-app ke Admin Keuangan & Super Admin
            $this->notifyNewRegistration($student->name);

            return ['student' => $student->fresh(), 'invoice' => $invoice->fresh()];
        });
    }

    /**
     * Pendaftaran via instansi (tanpa promo).
     * @throws DomainException
     */
    public function registerInstansi(array $data, int $schoolId): array
    {
        return DB::transaction(function () use ($data, $schoolId) {
            $school = \App\Models\School::findOrFail($schoolId);

            // Sekolah kelola pendaftaran & pembayaran sendiri
            $selfManaged     = (bool) $school->self_managed;
            $registrationFee = (float) $school->registration_fee;
            $pricePerCycle   = (float) $school->price_per_cycle;
            $quota           = (int) ($school->mous()->latest()->value('periods') ?? 0);

            $dup = Student::where('name', $data['name'])->whereDate('birth_date', $data['birth_date'])->exists();
            if ($dup) throw new DomainException('Siswa dengan nama & tanggal lahir yang sama sudah terdaftar.');

            // Data orang tua OPSIONAL pada jalur instansi.
            // - Import CSV tidak punya kolom nomor HP sama sekali.
            // - parents.name dan parents.phone keduanya NOT NULL, jadi keduanya
            //   harus ada untuk boleh membuat baris (DaftarInstansiRequest
            //   menjaga pasangannya lewat required_with).
            $parent = null;
            if (! empty($data['phone']) && ! empty($data['parent_name'])) {
                $parent = StudentParent::updateOrCreate(
                    ['phone' => Phone::normalize($data['phone'])],
                    [
                        'name'      => $data['parent_name'],
                        'greeting'  => $data['greeting'] ?? null,
                        'phone_alt' => isset($data['phone_alt']) ? Phone::normalize($data['phone_alt']) : null,
                    ]
                );
            }

            $student = Student::create([
                'student_code'      => $this->generateInstansiCode(),
                'name'              => $data['name'],
                'birth_date'        => $data['birth_date'],
                'gender'            => $data['gender'],
                'shirt_size'        => $data['shirt_size'] ?? null,
                'school_origin'     => $school->name,
                'school_grade'      => $data['school_grade'] ?? null,
                'allergy_notes'     => $data['allergy_notes'] ?? null,
                'photo_permission'  => $data['photo_permission'] ?? false,
                'parent_id'         => $parent?->id,
                'school_id'         => $schoolId,
                'program_id'        => $data['program_id'] ?? null,
                'period_quota'      => $quota ?: null,
                'joined_at'         => now()->toDateString(),
                'status'            => 'aktif',
                'is_verified'       => $selfManaged,   // ← kelola-sendiri: langsung sah jadi siswa
                'registration_type' => 'instansi',
            ]);

            $billingMonth = BillingMonth::create([
                'student_id'   => $student->id,
                'cycle_number' => 1,
                'period_month' => (int) now()->format('n'),
                'period_year'  => (int) now()->format('Y'),
                'status'       => 'aktif',
            ]);

            $total = $registrationFee + $pricePerCycle;
            $invoice = Invoice::create([
                'invoice_number'   => 'TMP-' . \Illuminate\Support\Str::uuid(),
                'student_id'       => $student->id,
                'billing_month_id' => $billingMonth->id,
                'base_amount'      => $pricePerCycle,
                'registration_fee' => $registrationFee,
                'discount_amount'  => 0,
                'total_amount'     => $total,
                'due_date'         => now()->addDays(14),
                'status'           => $selfManaged ? 'lunas' : 'belum_bayar',  // ← langsung jadi kewajiban sekolah
            ]);
            $invoice->update(['invoice_number' => 'INV-' . now()->format('Ymd') . '-' . str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);

            $this->notifyNewRegistration($student->name);
            return ['student' => $student->fresh(), 'invoice' => $invoice->fresh()];
        });
    }

    private function notifyNewRegistration(string $studentName): void
    {
        $recipients = User::whereIn('role', ['admin_keuangan', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($recipients as $userId) {
            Notification::create([
                'recipient_type' => 'user',
                'recipient_id'   => $userId,
                'title'          => 'Pendaftaran Baru',
                'message'        => "Siswa baru terdaftar: {$studentName}.",
                'type'           => 'pendaftaran_baru',
                'is_read'        => false,
            ]);
        }
    }

    private function generateInstansiCode(): string
    {
        $prefix = 'ROBO-INS';
        $last = Student::where('student_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('student_code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function generateStudentCode(): string
    {
        $prefix = 'ROBO-MDR';
        $last = Student::where('student_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('student_code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
