<?php

namespace App\Http\Controllers\Api\V1\Murid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Murid\EReportRequest;
use App\Models\EReport;
use App\Models\User;
use App\Support\Phone;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Storage;
use App\Models\Kelas;
use App\Models\Student;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class EReportController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $reports = EReport::with('student:id,name,student_code')
            ->when($request->filled('student_id'), fn($q) => $q->where('student_id', $request->student_id))
            ->when($request->filled('year'), fn($q) => $q->where('year', $request->year))
            ->orderByDesc('year')
            ->paginate($request->integer('per_page', 20));

        return $this->success($reports, 'Daftar E-Rapot.');
    }

    /** Cetak PDF (internal). */
    public function pdf(EReport $eReport): Response
    {
        return $this->renderPdf($eReport);
    }

    /** Ortu: daftar E-Rapot anak (verifikasi via HP). */
    public function parentList(Request $request): JsonResponse
    {
        $request->validate(['student_id' => ['required', 'integer'], 'phone' => ['required', 'string']]);

        $student = Student::with('parent')->find($request->student_id);
        if (! $student || optional($student->parent)->phone !== Phone::normalize($request->phone)) {
            return $this->error('Data tidak cocok. Periksa nomor HP.', 403);
        }

        $reports = EReport::where('student_id', $student->id)->orderByDesc('year')->get();

        return $this->success($reports, 'E-Rapot anak.');
    }

    /** Ortu: download PDF (verifikasi via HP). */
    public function parentPdf(Request $request, EReport $eReport): Response
    {
        $request->validate(['phone' => ['required', 'string']]);
        $eReport->load('student.parent');

        if (optional($eReport->student->parent)->phone !== Phone::normalize($request->phone)) {
            abort(403, 'Nomor HP tidak cocok.');
        }

        return $this->renderPdf($eReport);
    }

    /* ---------- helpers ---------- */

    private function guardTrainer(?object $user, int $studentId, int $classId): ?JsonResponse
    {
        if ($user instanceof User && $user->role === 'trainer') {
            $teaches = \App\Models\Kelas::where('id', $classId)->where('trainer_id', $user->id)->exists()
                && \App\Models\ClassStudent::where('class_id', $classId)->where('student_id', $studentId)->exists();
            if (! $teaches) {
                return $this->error('Anda hanya bisa menilai murid di kelas Anda.', 403);
            }
        }

        return null;
    }

    /** Admin Sekolah: daftar E-Rapot murid sekolahnya. */
    public function schoolList(Request $request, Student $student): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof \App\Models\SchoolAdmin) {
            return $this->error('Akses ditolak: khusus Admin Sekolah.', 403);
        }
        if ($student->school_id !== $admin->school_id) {
            return $this->error('Murid bukan dari sekolah Anda.', 403);
        }

        $reports = EReport::where('student_id', $student->id)->orderByDesc('year')->orderByDesc('semester')->get();

        return $this->success($reports, 'E-Rapot murid.');
    }

    /** Admin Sekolah: download PDF E-Rapot murid sekolahnya. */
    public function schoolPdf(Request $request, EReport $eReport): Response
    {
        $admin = $request->user();
        if (! $admin instanceof \App\Models\SchoolAdmin) {
            abort(403, 'Khusus Admin Sekolah.');
        }
        $eReport->load('student');
        if ($eReport->student->school_id !== $admin->school_id) {
            abort(403, 'Murid bukan dari sekolah Anda.');
        }

        return $this->renderPdf($eReport);
    }

    public function store(EReportRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($err = $this->guardTrainer($user, $request->student_id, $request->class_id)) return $err;

        $exists = EReport::where('student_id', $request->student_id)
            ->where('semester', $request->semester)->where('year', $request->year)->exists();
        if ($exists) return $this->error('E-Rapot siswa untuk semester & tahun ini sudah ada. Gunakan edit.', 422);

        $data = $request->safe()->except('signature');
        $data['trainer_id'] = $user->id;
        $data['signature_image'] = $user->signature_image;          // ← TTD otomatis dari trainer

        return $this->success(EReport::create($data), 'E-Rapot dibuat.', 201);
    }

    public function update(EReportRequest $request, EReport $eReport): JsonResponse
    {
        $user = $request->user();
        if ($err = $this->guardTrainer($user, $request->student_id, $request->class_id)) return $err;

        $data = $request->safe()->except('signature');
        $data['signature_image'] = optional($eReport->trainer)->signature_image ?? $user->signature_image;

        $eReport->update($data);
        return $this->success($eReport->fresh(), 'E-Rapot diperbarui.');
    }

    public function show(EReport $eReport): JsonResponse
    {
        $eReport->load([
            'student:id,name,student_code,school_grade,school_origin,school_id',
            'student.school:id,name',
            'trainer:id,name,signature_image',
            'kelas:id,name,program_id',
            'kelas.program:id,name',
        ]);
        return $this->success($eReport, 'Detail E-Rapot.');
    }

    /** Kelas yang bisa dinilai user (trainer: kelasnya; admin/super: semua). */
    public function gradableClasses(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Kelas::with('program:id,name', 'school:id,name')->orderBy('name');
        if ($user->role === 'trainer') $q->whereHas('trainers', fn($x) => $x->where('users.id', $user->id));
        return $this->success($q->get(['id', 'name', 'program_id', 'school_id']), 'Kelas.');
    }

    /** Matriks status E-Rapot per siswa (semester 1 & 2). */
    public function matrix(Request $request): JsonResponse
    {
        $request->validate(['class_id' => ['required', 'exists:classes,id'], 'year' => ['nullable', 'integer']]);
        $year = $request->integer('year', (int) date('Y'));
        $kelas = Kelas::with('program:id,name', 'school:id,name')->findOrFail($request->class_id);

        $user = $request->user();
        if ($user->role === 'trainer' && ! $kelas->trainers()->where('users.id', $user->id)->exists()) {
            return $this->error('Bukan kelas Anda.', 403);
        }

        $students = $kelas->students()->orderBy('name')->get(['students.id', 'name', 'student_code', 'school_grade']);
        $reports = EReport::where('class_id', $kelas->id)->where('year', $year)->get(['id', 'student_id', 'semester'])->groupBy('student_id');

        $rows = $students->map(function ($s) use ($reports) {
            $rs = $reports->get($s->id, collect());
            return [
                'id' => $s->id,
                'name' => $s->name,
                'student_code' => $s->student_code,
                'school_grade' => $s->school_grade,
                'semester_1' => optional($rs->firstWhere('semester', 1))->id,
                'semester_2' => optional($rs->firstWhere('semester', 2))->id,
            ];
        });

        return $this->success([
            'class' => ['id' => $kelas->id, 'name' => $kelas->name, 'program' => optional($kelas->program)->name, 'school' => optional($kelas->school)->name],
            'year' => $year,
            'students' => $rows,
        ], 'Matriks E-Rapot.');
    }

    /** Data header untuk isi rapot baru. */
    public function prefill(Request $request): JsonResponse
    {
        $request->validate(['student_id' => ['required', 'exists:students,id'], 'class_id' => ['required', 'exists:classes,id']]);
        $student = Student::with('school:id,name')->findOrFail($request->student_id);
        $kelas = Kelas::with('program:id,name')->findOrFail($request->class_id);
        return $this->success([
            'student' => ['id' => $student->id, 'name' => $student->name, 'student_code' => $student->student_code, 'school_grade' => $student->school_grade, 'school' => optional($student->school)->name ?? $student->school_origin],
            'class'   => ['id' => $kelas->id, 'name' => $kelas->name, 'program' => optional($kelas->program)->name],
        ], 'Prefill.');
    }

    /** TTD milik user login. */
    public function mySignature(Request $request): JsonResponse
    {
        return $this->success(['signature_image' => $request->user()->signature_image], 'TTD saya.');
    }

    public function updateMySignature(Request $request): JsonResponse
    {
        $request->validate([
            'signature' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048'], // TTD maks 2MB
        ]);

        $user = $request->user();

        // hapus TTD lama bila ada
        if ($user->signature_image && Storage::disk('local')->exists($user->signature_image)) {
            Storage::disk('local')->delete($user->signature_image);
        }

        // Simpan APA ADANYA (PNG/JPG). JANGAN storeWebp — DomPDF tidak mendukung WebP.
        $path = $request->file('signature')->store('signatures', 'local'); // folder terproteksi → /media
        $user->update(['signature_image' => $path]);

        return $this->success(['signature_image' => $path], 'Tanda tangan diperbarui.');
    }

    private function renderPdf(EReport $eReport): Response
    {
        $eReport->load(['student:id,name,student_code,school_grade,school_origin,school_id', 'student.school:id,name', 'trainer:id,name,signature_image', 'kelas:id,name']);

        $sig = null;
        if (($sp = optional($eReport->trainer)->signature_image) && Storage::disk('local')->exists($sp)) {
            $ext  = strtolower(pathinfo($sp, PATHINFO_EXTENSION));
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext] ?? 'image/png';
            $sig  = 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('local')->get($sp));
        }

        $logo = null;
        if (is_file($lp = public_path('images/robotiku-logo.png'))) {
            $logo = 'data:image/png;base64,' . base64_encode(file_get_contents($lp));
        }

        $pdf = Pdf::loadView('erapot.pdf', ['r' => $eReport, 'sig' => $sig, 'logo' => $logo])->setPaper('a4', 'portrait');
        return $pdf->download("E-Rapot-{$eReport->student->student_code}-S{$eReport->semester}-{$eReport->year}.pdf");
    }

    /** Baca Excel template → cocokkan siswa di kelas → kembalikan data untuk dikonfirmasi. */
    public function importParse(Request $request): JsonResponse
    {
        $request->validate([
            'file'     => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
            'class_id' => ['required', 'exists:classes,id'],
        ]);

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $val   = fn($c) => trim((string) $sheet->getCell($c)->getFormattedValue());
        $strip = fn($s) => ltrim(preg_replace('/^\s*:\s*/', '', $s));

        $school     = $strip($val('D5'));
        $classGrade = $strip($val('N5'));
        $studentNm  = $strip($val('D6'));
        $group      = $strip($val('D7'));

        // Topics & Activities: baris 12–16 (B=topik, D=aktivitas)
        $topics = [];
        for ($r = 12; $r <= 16; $r++) {
            $t = $val("B$r");
            $a = $val("D$r");
            if ($t !== '' || $a !== '') $topics[] = ['topic' => $t, 'activity' => $a];
        }

        // kolom nilai G..K = A..E ; ambil kolom yang ada tandanya
        $gcols = ['G' => 'A', 'H' => 'B', 'I' => 'C', 'J' => 'D', 'K' => 'E'];
        $gradeAt = function (int $row) use ($sheet, $gcols): ?string {
            foreach ($gcols as $col => $g) {
                if (trim((string) $sheet->getCell($col . $row)->getValue()) !== '') return $g;
            }
            return null;
        };

        $payload = [
            'skill_building'          => $gradeAt(20),
            'skill_imagination'       => $gradeAt(21),
            'skill_creativity'        => $gradeAt(22),
            'skill_logic'             => $gradeAt(23),
            'behavior_punctual'       => $gradeAt(26),
            'behavior_stay'           => $gradeAt(27),
            'behavior_communication'  => $gradeAt(28),
            'behavior_responsibility' => $gradeAt(29),
            'comments'                => $val('L19'),
            'topics'                  => $topics,
            'report_place'            => 'Pontianak',
            'report_date'             => null,
        ];

        $kelas      = Kelas::findOrFail($request->class_id);
        $candidates = $kelas->students()->orderBy('name')->get(['students.id', 'name']);
        $matched    = $candidates->first(fn($s) => mb_strtolower(trim($s->name)) === mb_strtolower(trim($studentNm)));

        return $this->success([
            'header'             => ['school' => $school, 'class_grade' => $classGrade, 'student_name' => $studentNm, 'group' => $group],
            'matched_student_id' => optional($matched)->id,
            'students'           => $candidates,
            'payload'            => $payload,
        ], 'Hasil baca Excel.');
    }

    /** Unduh template kosong (posisi sel sama dengan importParse). */
    public function importTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $ss = new Spreadsheet();
        $this->buildErapotSheet($ss->getActiveSheet(), null);
        $file = tempnam(sys_get_temp_dir(), 'erapot') . '.xlsx';
        (new Xlsx($ss))->save($file);
        return response()->download($file, 'template-e-rapot.xlsx')->deleteFileAfterSend(true);
    }

    private function buildErapotSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, ?EReport $r): void
    {
        $center = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
        $bold = ['font' => ['bold' => true]];
        $band = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E5E5']]];
        $year = $r?->year ?? (int) date('Y');
        $ay = $year . '/' . ($year + 1);

        $s->mergeCells('C1:U1')->setCellValue('C1', 'ROBOTIKU INDONESIA')->getStyle('C1')->applyFromArray($center + ['font' => ['bold' => true, 'size' => 16]]);
        $s->mergeCells('C2:U2')->setCellValue('C2', "TAHUN AJARAN $ay ROBOTIKU CLUB REPORT CARD")->getStyle('C2')->applyFromArray($center + $bold);

        $s->setCellValue('B5', 'SCHOOL')->getStyle('B5')->applyFromArray($bold);
        $s->setCellValue('C5', ':');
        $s->setCellValue('L5', 'CLASS')->getStyle('L5')->applyFromArray($bold);
        $s->setCellValue('M5', ':');
        $s->setCellValue('B6', 'STUDENT NAME')->getStyle('B6')->applyFromArray($bold);
        $s->setCellValue('C6', ':');
        $s->setCellValue('B7', 'GROUP')->getStyle('B7')->applyFromArray($bold);
        $s->setCellValue('C7', ':');
        if ($r) {
            $s->setCellValue('D5', optional($r->student->school)->name ?? $r->student->school_origin ?? '-');
            $s->setCellValue('N5', $r->student->school_grade ?? '-');
            $s->setCellValue('D6', $r->student->name);
            $s->setCellValue('D7', optional($r->kelas)->name ?? '-');
        }

        $s->mergeCells('A8:W8')->setCellValue('A8', 'REPORT RATING KEY')->getStyle('A8')->applyFromArray($center + $bold + $band);
        $s->setCellValue('B9', 'A : Very Good')->setCellValue('D9', 'B : Good')->setCellValue('G9', 'C : Average')->setCellValue('J9', 'D : Poor')->setCellValue('L9', 'E : Very Poor');

        $s->mergeCells('A11:W11')->setCellValue('A11', 'Topics and Activities')->getStyle('A11')->applyFromArray($center + $bold + $band);
        if ($r) {
            $row = 12;
            foreach (($r->topics ?? []) as $t) {
                if ($row > 16) break;
                $s->setCellValue("B$row", $t['topic'] ?? '')->setCellValue("D$row", $t['activity'] ?? '');
                $row++;
            }
        }

        $gcols = ['A' => 'G', 'B' => 'H', 'C' => 'I', 'D' => 'J', 'E' => 'K'];
        $s->setCellValue('B19', 'Skills')->getStyle('B19')->applyFromArray($bold);
        $s->setCellValue('B24', 'Behaviour')->getStyle('B24')->applyFromArray($bold);
        $s->setCellValue('G18', 'Grade')->setCellValue('G24', 'Grade');
        foreach ($gcols as $g => $col) {
            $s->setCellValue($col . '19', $g)->setCellValue($col . '25', $g);
        }
        $aspects = [
            20 => ['Building', 'skill_building'],
            21 => ['Imagination', 'skill_imagination'],
            22 => ['Creativity', 'skill_creativity'],
            23 => ['Logic Thinking', 'skill_logic'],
            26 => ['Attends on time', 'behavior_punctual'],
            27 => ["Don't leave class early", 'behavior_stay'],
            28 => ['Communication', 'behavior_communication'],
            29 => ['Responsibility', 'behavior_responsibility']
        ];
        foreach ($aspects as $rw => [$label, $field]) {
            $s->setCellValue("B$rw", $label);
            if ($r && ($v = $r->{$field}) && isset($gcols[$v])) $s->setCellValue($gcols[$v] . $rw, '√');
        }
        $s->getStyle('G18:K29')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $s->getStyle('G18:K29')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $s->setCellValue('L18', 'Comments')->getStyle('L18')->applyFromArray($center + $bold);
        $s->mergeCells('L19:V29')->getStyle('L19')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        if ($r) $s->setCellValue('L19', $r->comments);

        $date = $r && $r->report_date ? \Carbon\Carbon::parse($r->report_date)->translatedFormat('j F Y') : '';
        $s->setCellValue('B31', 'RobotiKU');
        $s->setCellValue('B32', ($r->report_place ?? 'Pontianak') . ($date ? ", $date" : ''));
        $s->getStyle('B36')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $s->setCellValue('B37', 'Trainer');
        $s->getColumnDimension('B')->setWidth(20);
        $s->getColumnDimension('D')->setWidth(20);

        $logoPath = public_path('images/robotiku-logo.png');
        if (is_file($logoPath)) {
            $d = new Drawing();
            $d->setPath($logoPath);
            $d->setCoordinates('A1');
            $d->setHeight(55);
            $d->setWorksheet($s);
        }

        if ($r && optional($r->trainer)->signature_image) {
            try {
                $sp = Storage::disk('local')->path($r->trainer->signature_image);
                if (is_file($sp)) {
                    $sig = new Drawing();
                    $sig->setPath($sp);
                    $sig->setCoordinates('B34');
                    $sig->setHeight(42);
                    $sig->setWorksheet($s);
                }
            } catch (\Throwable $e) { /* webp bisa gagal di drawing — abaikan */
            }
        }
    }

    public function exportExcel(EReport $eReport): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $eReport->load(['student', 'student.school:id,name', 'trainer:id,name,signature_image', 'kelas:id,name']);
        $ss = new Spreadsheet();
        $this->buildErapotSheet($ss->getActiveSheet(), $eReport);
        $file = tempnam(sys_get_temp_dir(), 'erapot') . '.xlsx';
        (new Xlsx($ss))->save($file);
        return response()->download($file, "E-Rapot-{$eReport->student->student_code}-S{$eReport->semester}-{$eReport->year}.xlsx")->deleteFileAfterSend(true);
    }
}
