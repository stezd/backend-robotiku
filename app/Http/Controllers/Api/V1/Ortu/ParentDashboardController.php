<?php

namespace App\Http\Controllers\Api\V1\Ortu;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Invoice;
use App\Models\Kelas;
use App\Models\Payment;
use App\Models\Student;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentDashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $r): JsonResponse
    {
        $data = $r->validate(['student_id' => ['required', 'exists:students,id']]);
        $s = Student::with('program:id,name', 'school:id,name,self_managed')->findOrFail($data['student_id']);

        $selfManaged = (bool) optional($s->school)->self_managed;

        // pertemuan per periode mengikuti kelas siswa (default 4 bila belum ada kelas)
        $perPeriod = (int) (Kelas::whereHas('students', fn($q) => $q->where('students.id', $s->id))
            ->value('meetings_per_period') ?: 4);
        $perPeriod = max(1, $perPeriod);

        $att = Attendance::where('student_id', $s->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $hadir = (int) ($att['hadir'] ?? 0);

        // Sekolah kelola-sendiri → ortu tidak melihat tagihan sama sekali
        $invoices = $selfManaged
            ? collect()
            : Invoice::where('student_id', $s->id)->latest()->get(['id', 'invoice_number', 'total_amount', 'status', 'due_date']);

        return $this->success([
            'student' => [
                'name'         => $s->name,
                'student_code' => $s->student_code,
                'program'      => $s->program?->name,
                'school'       => $s->school?->name,
                'status'       => $s->status,
                'period_quota' => $s->period_quota,
                'self_managed' => $selfManaged,
            ],
            'self_managed' => $selfManaged,
            'kpi' => [
                'hadir'           => $hadir,
                'periode_selesai' => intdiv($hadir, $perPeriod),
                'pekan_berjalan'  => $hadir % $perPeriod,
                'per_periode'     => $perPeriod,
                'tagihan_belum'   => $selfManaged ? 0 : $invoices->where('status', 'belum_bayar')->count(),
            ],
            'kehadiran' => [
                ['name' => 'Hadir', 'value' => (int) ($att['hadir'] ?? 0)],
                ['name' => 'Izin',  'value' => (int) ($att['izin'] ?? 0)],
                ['name' => 'Sakit', 'value' => (int) ($att['sakit'] ?? 0)],
                ['name' => 'Alpa',  'value' => (int) ($att['tanpa_keterangan'] ?? 0)],
            ],
            'invoices' => $invoices->values(),
        ], 'Dashboard ortu.');
    }

    public function pay(Request $r): JsonResponse
    {
        $data = $r->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'proof'      => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $invoice = Invoice::with('student.school')->findOrFail($data['invoice_id']);

        // pengaman: sekolah kelola-sendiri tidak menerima pembayaran dari ortu
        if (optional($invoice->student->school)->self_managed) {
            return $this->error('Pembayaran untuk sekolah ini dikelola langsung oleh pihak sekolah.', 422);
        }

        $path = $r->file('proof')->store('payments', 'local');
        Payment::create([
            'invoice_id'    => $invoice->id,
            'proof_file'    => $path,
            'uploader_type' => 'parent',
            'uploader_id'   => $invoice->student->parent_id,
            'status'        => 'menunggu_verifikasi',
        ]);
        $invoice->update(['status' => 'menunggu_verifikasi']);

        return $this->success(null, 'Bukti pembayaran terkirim, menunggu verifikasi.');
    }
}
