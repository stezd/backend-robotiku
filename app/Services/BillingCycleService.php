<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BillingMonth;
use App\Models\Invoice;
use App\Models\Kelas;
use App\Models\Notification;
use App\Models\Session;
use App\Models\Student;
use App\Models\StudentStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingCycleService
{
    /**
     * Dipicu saat murid HADIR di sesi PEKAN 4.
     * → buat tagihan periode berikutnya (harga: instansi=sekolah, mandiri=program).
     * → jika quota MoU habis → murid jadi Nonaktif (tak ditagih lagi).
     */
    public function handlePeriodCompletion(Student $student, Session $session): ?Invoice
    {
        if (in_array($student->status, ['nonaktif', 'lulus', 'cuti'], true)) {
            return null;
        }
        if ((int) $session->week !== 4) {
            return null;
        } // hanya pekan terakhir memicu

        $session->loadMissing('period');
        $period = $session->period;
        if (! $period) {
            return null;
        }

        $next = $period->number + 1;

        // Quota (instansi): period_quota; mandiri: null = tanpa batas
        if ($student->period_quota !== null && $next > (int) $student->period_quota) {
            if ($student->status === 'aktif') {
                $old = $student->status;
                $student->update(['status' => 'nonaktif']);
                StudentStatusLog::create([
                    'student_id' => $student->id,
                    'old_status' => $old,
                    'new_status' => 'nonaktif',
                    'changed_by' => null,
                ]);
            }

            return null; // quota habis → tidak buat tagihan
        }

        // cegah dobel untuk periode yang sama
        if (BillingMonth::where('student_id', $student->id)->where('cycle_number', $next)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($student, $next) {
            $cycle = $this->cyclePrice($student);

            $month = BillingMonth::create([
                'student_id' => $student->id,
                'cycle_number' => $next,
                'period_month' => (int) now()->format('n'),
                'period_year' => (int) now()->format('Y'),
                'status' => 'aktif',
            ]);

            $invoice = Invoice::create([
                'invoice_number' => 'TMP-'.Str::uuid(),
                'student_id' => $student->id,
                'billing_month_id' => $month->id,
                'base_amount' => $cycle,
                'registration_fee' => null,
                'discount_amount' => 0,
                'total_amount' => $cycle,
                'due_date' => now()->addDays(7),
                'status' => 'belum_bayar',
            ]);
            $invoice->update(['invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);

            $this->notifyNewInvoice($student->name, $invoice->invoice_number);

            return $invoice->fresh();
        });
    }

    /** Harga per siklus: instansi → sekolah, mandiri → program. */
    private function cyclePrice(Student $student): float
    {
        if ($student->school_id) {
            $student->loadMissing('school');

            return (float) ($student->school?->price_per_cycle ?? 0);
        }
        $student->loadMissing('program');

        return (float) ($student->program?->price_per_cycle ?? 0);
    }

    private function notifyNewInvoice(string $name, string $inv): void
    {
        foreach (User::whereIn('role', ['admin_keuangan', 'super_admin'])->where('is_active', true)->pluck('id') as $uid) {
            Notification::create([
                'recipient_type' => 'user',
                'recipient_id' => $uid,
                'title' => 'Tagihan Periode Baru',
                'message' => "Tagihan {$inv} untuk {$name} telah dibuat.",
                'type' => 'pembayaran_baru',
                'is_read' => false,
            ]);
        }
    }

    /**
     * Dipanggil saat murid HADIR di sesi Pekan-4.
     * Menyelesaikan periode berjalan → buat tagihan periode BERIKUTNYA.
     * Jika jatah periode (period_quota / MoU) habis → murid jadi Nonaktif.
     */
    public function completePeriod(Student $student): ?Invoice
    {
        if (in_array($student->status, ['nonaktif', 'lulus', 'cuti'], true)) {
            return null;
        }

        return DB::transaction(function () use ($student) {
            $billed = (int) BillingMonth::where('student_id', $student->id)->max('cycle_number'); // periode yang sudah ditagih

            // Instansi: cek jatah periode dari MoU (mandiri: period_quota null = tak terbatas)
            if ($student->period_quota !== null && $billed >= $student->period_quota) {
                $student->update(['status' => 'nonaktif']); // jatah habis → berhenti tagih
                StudentStatusLog::create([
                    'student_id' => $student->id,
                    'old_status' => 'aktif',
                    'new_status' => 'nonaktif',
                    'changed_by' => null, // sistem
                ]);

                return null;
            }

            $next = $billed + 1;
            if (BillingMonth::where('student_id', $student->id)->where('cycle_number', $next)->exists()) {
                return null; // sudah pernah ditagih (hindari dobel bila absensi diedit)
            }

            $price = $this->cyclePrice($student); // instansi→harga sekolah, mandiri→harga program

            $month = BillingMonth::create([
                'student_id' => $student->id,
                'cycle_number' => $next,
                'period_month' => (int) now()->format('n'),
                'period_year' => (int) now()->format('Y'),
                'status' => 'aktif',
            ]);

            $invoice = Invoice::create([
                'invoice_number' => 'TMP-'.Str::uuid(),
                'student_id' => $student->id,
                'billing_month_id' => $month->id,
                'base_amount' => $price,
                'registration_fee' => null,
                'discount_amount' => 0,
                'total_amount' => $price,
                'due_date' => now()->addDays(7),
                'status' => 'belum_bayar',
            ]);
            $invoice->update(['invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);

            $this->notifyNewInvoice($student->name, $invoice->invoice_number);

            return $invoice->fresh();
        });
    }

    /**
     * Dipanggil SETELAH sebuah absensi 'hadir' tersimpan.
     * Batas periode ditentukan per-kelas (meetings_per_period), berhenti di total_periods.
     */
    public function onAttendance(Student $student, Kelas $kelas): ?Invoice
    {
        if ($student->status !== 'aktif') {
            return null;
        }

        $perPeriod = max(1, (int) ($kelas->meetings_per_period ?: 4));

        $hadir = Attendance::where('class_id', $kelas->id)
            ->where('student_id', $student->id)
            ->where('status', 'hadir')
            ->count();

        if ($hadir === 0 || $hadir % $perPeriod !== 0) {
            return null;
        }

        $completedPeriods = intdiv($hadir, $perPeriod);
        $nextPeriod = $completedPeriods + 1;
        $totalPeriods = $kelas->total_periods !== null ? (int) $kelas->total_periods : null;

        return DB::transaction(function () use ($student, $nextPeriod, $totalPeriods) {
            $lockedStudent = Student::where('id', $student->id)->lockForUpdate()->first();
            if (! $lockedStudent || $lockedStudent->status !== 'aktif') {
                return null;
            }

            if ($totalPeriods !== null && $nextPeriod > $totalPeriods) {
                $old = $lockedStudent->status;
                $lockedStudent->update(['status' => 'nonaktif']);
                StudentStatusLog::create([
                    'student_id' => $lockedStudent->id,
                    'old_status' => $old,
                    'new_status' => 'nonaktif',
                    'changed_by' => null,
                ]);

                return null;
            }

            if (BillingMonth::where('student_id', $lockedStudent->id)->where('cycle_number', $nextPeriod)->lockForUpdate()->exists()) {
                return null;
            }

            // Sekolah kelola-sendiri → tagihan langsung LUNAS (jadi kewajiban sekolah, siap disetor)
            $lockedStudent->loadMissing(['school', 'program']);
            $selfManaged = (bool) optional($lockedStudent->school)->self_managed;

            $price = $this->cyclePrice($lockedStudent);

            $month = BillingMonth::create([
                'student_id' => $lockedStudent->id,
                'cycle_number' => $nextPeriod,
                'period_month' => (int) now()->format('n'),
                'period_year' => (int) now()->format('Y'),
                'status' => 'aktif',
            ]);

            $invoice = Invoice::create([
                'invoice_number' => 'TMP-'.Str::uuid(),
                'student_id' => $lockedStudent->id,
                'billing_month_id' => $month->id,
                'base_amount' => $price,
                'registration_fee' => null,
                'discount_amount' => 0,
                'total_amount' => $price,
                'due_date' => now()->addDays(7),
                'status' => $selfManaged ? 'lunas' : 'belum_bayar',
            ]);
            $invoice->update(['invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);

            // notifikasi tagihan hanya untuk yang perlu ditagih ke ortu
            if (! $selfManaged) {
                $this->notifyNewInvoice($lockedStudent->name, $invoice->invoice_number);
            }

            return $invoice->fresh();
        });
    }

    /**
     * Dipanggil saat sesi kelas SELESAI (ended).
     * Mengevaluasi apakah kelas telah mencapai kelipatan meetings_per_period.
     * Jika ya → buat BillingMonth + Invoice untuk SEMUA murid aktif di kelas tsb.
     *
     * Idempotensi: guard cycle_number mencegah duplikasi.
     * Self-managed: invoice langsung 'lunas', tanpa notifikasi SPP.
     * Kuota: jika next period > total_periods atau period_quota → murid nonaktif.
     *
     * @return array<int, Invoice> Daftar invoice yang dibuat (bisa kosong)
     */
    public function triggerPeriodCompletionForClass(Kelas $kelas, Session $session): array
    {
        $perPeriod = max(1, (int) ($kelas->meetings_per_period ?: 4));

        // Hitung total sesi SELESAI (ended) untuk kelas ini
        $endedCount = Session::where('class_id', $kelas->id)
            ->where('status', 'ended')
            ->count();

        // Belum kelipatan → belum saatnya generate billing
        if ($endedCount === 0 || $endedCount % $perPeriod !== 0) {
            return [];
        }

        $completedPeriods = intdiv($endedCount, $perPeriod);
        $nextPeriod = $completedPeriods + 1;
        $totalPeriods = $kelas->total_periods !== null ? (int) $kelas->total_periods : null;

        // Ambil semua murid aktif di kelas (eager load dan urutkan id untuk mencegah deadlock)
        $students = $kelas->students()
            ->with(['school', 'program'])
            ->where('students.status', 'aktif')
            ->orderBy('students.id')
            ->get();

        $invoices = [];

        foreach ($students as $student) {
            $invoice = $this->generateInvoiceForStudent($student, $nextPeriod, $totalPeriods);
            if ($invoice) {
                $invoices[] = $invoice;
            }
        }

        return $invoices;
    }

    /**
     * Generate invoice untuk satu murid pada cycle tertentu.
     * Handles: quota guard, idempotency, self_managed, notifikasi dalam DB::transaction.
     */
    private function generateInvoiceForStudent(Student $student, int $nextPeriod, ?int $totalPeriods): ?Invoice
    {
        return DB::transaction(function () use ($student, $nextPeriod, $totalPeriods) {
            // Re-fetch dan lock row student untuk mencegah race condition status
            $lockedStudent = Student::where('id', $student->id)->lockForUpdate()->first();
            if (! $lockedStudent || $lockedStudent->status !== 'aktif') {
                return null;
            }

            // Kuota kontrak per-murid (dari MoU) — override total_periods kelas
            $quotaLimit = $lockedStudent->period_quota !== null
                ? (int) $lockedStudent->period_quota
                : $totalPeriods;

            // Jika kuota terlampaui → nonaktifkan murid
            if ($quotaLimit !== null && $nextPeriod > $quotaLimit) {
                $old = $lockedStudent->status;
                $lockedStudent->update(['status' => 'nonaktif']);
                StudentStatusLog::create([
                    'student_id' => $lockedStudent->id,
                    'old_status' => $old,
                    'new_status' => 'nonaktif',
                    'changed_by' => null, // sistem
                ]);

                return null;
            }

            // Idempotensi: cegah dobel invoice untuk cycle yang sama
            if (BillingMonth::where('student_id', $lockedStudent->id)
                ->where('cycle_number', $nextPeriod)
                ->lockForUpdate()
                ->exists()
            ) {
                return null;
            }

            // Deteksi skema self_managed
            $lockedStudent->loadMissing(['school', 'program']);
            $selfManaged = (bool) optional($lockedStudent->school)->self_managed;

            $price = $this->cyclePrice($lockedStudent);

            $month = BillingMonth::create([
                'student_id' => $lockedStudent->id,
                'cycle_number' => $nextPeriod,
                'period_month' => (int) now()->format('n'),
                'period_year' => (int) now()->format('Y'),
                'status' => 'aktif',
            ]);

            $invoice = Invoice::create([
                'invoice_number' => 'TMP-'.Str::uuid(),
                'student_id' => $lockedStudent->id,
                'billing_month_id' => $month->id,
                'base_amount' => $price,
                'registration_fee' => null,
                'discount_amount' => 0,
                'total_amount' => $price,
                'due_date' => now()->addDays(7),
                'status' => $selfManaged ? 'lunas' : 'belum_bayar',
            ]);
            $invoice->update([
                'invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT),
            ]);

            // Notifikasi hanya untuk non-self-managed
            if (! $selfManaged) {
                $this->notifyNewInvoice($lockedStudent->name, $invoice->invoice_number);
            }

            return $invoice->fresh();
        });
    }
}
