<?php

namespace App\Http\Controllers\Api\V1\Canvas;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Canvas\SchoolStatusRequest;
use App\Models\SchoolStatusLog;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Canvas\SchoolRequest;
use App\Models\Mou;
use App\Support\ImageStorage;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SchoolController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = School::query()
            ->when($request->filled('search'), fn($q) =>
            $q->where('name', 'like', '%' . $request->search . '%'))
            ->when($request->filled('status'), fn($q) =>
            $q->where('pipeline_status', $request->status))
            ->when($request->filled('updated_from'), fn($q) =>
            $q->whereDate('updated_at', '>=', $request->updated_from))
            ->when($request->filled('updated_to'), fn($q) =>
            $q->whereDate('updated_at', '<=', $request->updated_to))
            ->orderByDesc('updated_at');

        $schools = $query->paginate($request->integer('per_page', 15));

        // KPI per status (tanpa terpengaruh filter)
        $counts = School::selectRaw('pipeline_status, COUNT(*) as total')
            ->groupBy('pipeline_status')
            ->pluck('total', 'pipeline_status');

        $kpi = [
            'total'        => (int) $counts->sum(),
            'prospek'      => (int) ($counts['prospek'] ?? 0),
            'dalam_proses' => (int) ($counts['dalam_proses'] ?? 0),
            'sudah_mou'    => (int) ($counts['sudah_mou'] ?? 0),
            'tidak_lanjut' => (int) ($counts['tidak_lanjut'] ?? 0),
        ];

        return $this->success(['kpi' => $kpi, 'schools' => $schools], 'Daftar sekolah.');
    }

    public function store(SchoolRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pipelineStatus = $data['pipeline_status'] ?? $request->input('pipeline_status', 'prospek');
        $data['pipeline_status'] = $pipelineStatus;
        $data['is_mou'] = array_key_exists('is_mou', $data) && $data['is_mou'] !== null
            ? (bool) $data['is_mou']
            : ($pipelineStatus === 'sudah_mou');
        $data['created_by'] = $request->user()->id;

        $school = School::create($data + [
            'commission_percent' => $request->input('commission_percent', 10),
        ]);

        return $this->success($school, 'Sekolah ditambahkan.', 201);
    }

    public function show(\App\Models\School $school): JsonResponse
    {
        $school->load(['notes.creator:id,name', 'statusLogs' => fn($q) => $q->latest()]);
        return $this->success($school, 'Detail sekolah.');
    }

    public function update(SchoolRequest $request, \App\Models\School $school): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['pipeline_status']) && !array_key_exists('is_mou', $data)) {
            $data['is_mou'] = ($data['pipeline_status'] === 'sudah_mou');
        }
        $school->update($data);
        return $this->success($school->fresh(), 'Data sekolah diperbarui.');
    }

    public function changeStatus(SchoolStatusRequest $request, School $school): JsonResponse
    {
        $old = $school->pipeline_status;
        $new = $request->pipeline_status;

        DB::transaction(function () use ($school, $old, $new, $request) {
            $school->update([
                'pipeline_status' => $new,
                'is_mou'          => $new === 'sudah_mou',
            ]);

            // log immutable (INSERT only)
            SchoolStatusLog::create([
                'school_id'  => $school->id,
                'old_status' => $old,
                'new_status' => $new,
                'note'       => $request->input('note'),
                'changed_by' => $request->user()->id,
            ]);
        });

        return $this->success($school->fresh(), 'Status pipeline diperbarui.');
    }

    public function addNote(Request $request, School $school): JsonResponse
    {
        $isPertemuan = $request->input('kind') === 'pertemuan';

        $data = $request->validate([
            'kind'      => ['required', 'in:pertemuan,audit'],
            'note'      => ['required', 'string'],
            'photo'     => [$isPertemuan ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'latitude'  => [$isPertemuan ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$isPertemuan ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
        ], [
            'photo.required'    => 'Foto wajib untuk catatan pertemuan.',
            'latitude.required' => 'Lokasi wajib diambil untuk catatan pertemuan.',
        ]);

        $photoPath = $request->hasFile('photo')
            ? ImageStorage::storeWebp($request->file('photo'), 'school_notes') // folder terproteksi → /media
            : null;

        $note = $school->notes()->create([
            'kind'       => $data['kind'],
            'note'       => $data['note'],
            'photo'      => $photoPath,
            'latitude'   => $data['latitude'] ?? null,
            'longitude'  => $data['longitude'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return $this->success($note->load('creator:id,name'), 'Catatan ditambahkan.', 201);
    }

    public function mou(): JsonResponse
    {
        $schools = School::where('is_mou', true)
            ->orderBy('name')
            ->get(['id', 'name', 'registration_fee', 'price_per_cycle', 'self_managed']);

        return $this->success($schools, 'Daftar sekolah MOU.');
    }

    public function mouIndex(\App\Models\School $school): JsonResponse
    {
        return $this->success(
            $school->mous()->with('creator:id,name')->latest()->get(),
            'Daftar MoU.'
        );
    }

    public function mouStore(Request $request, \App\Models\School $school): JsonResponse
    {
        $data = $request->validate([
            'file'         => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'periods'      => ['required', 'integer', 'min:1'],
            'self_managed' => ['nullable', 'boolean'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date', 'after_or_equal:start_date'],
            'note'         => ['nullable', 'string'],
        ]);

        $path        = MediaStorage::store($request->file('file'), 'mou');
        $selfManaged = $request->boolean('self_managed');

        $mou = $school->mous()->create([
            'file'         => $path,
            'periods'      => $data['periods'],
            'self_managed' => $selfManaged,
            'start_date'   => $data['start_date'] ?? null,
            'end_date'     => $data['end_date'] ?? null,
            'note'         => $data['note'] ?? null,
            'created_by'   => $request->user()->id,
        ]);

        $school->update([
            'is_mou'          => true,
            'pipeline_status' => 'sudah_mou',
            'self_managed'    => $selfManaged, // cerminkan status terbaru ke sekolah
        ]);

        return $this->success($mou, 'MoU ditambahkan.', 201);
    }

    public function mouFile(Mou $mou): Response
    {
        abort_unless($mou->file, 404);

        return MediaStorage::response($mou->file, true);
    }

    public function mouDestroy(Mou $mou): JsonResponse
    {
        if ($mou->file) {
            MediaStorage::delete($mou->file);
        }
        $mou->delete();
        return $this->success(null, 'MoU dihapus.');
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120']]);
        $path = ImageStorage::storeWebp($request->file('image'), 'schools');

        return $this->success(['path' => $path, 'url' => asset('storage/' . $path)], 'Gambar terunggah.');
    }

    public function setCommission(Request $request, \App\Models\School $school): JsonResponse
    {
        $data = $request->validate(['commission_percent' => ['required', 'numeric', 'min:0', 'max:100']]);
        $school->update($data);
        return $this->success($school->fresh(), 'Komisi diperbarui.');
    }

    public function destroy(\App\Models\School $school): JsonResponse
    {
        if ($school->students()->exists()) {
            return $this->error('Sekolah punya siswa terdaftar — tidak bisa dihapus.', 422);
        }
        $school->delete();
        return $this->success(null, 'Sekolah dihapus.');
    }

    public function rekap(): JsonResponse
    {
        $byStatus = DB::table('schools')->selectRaw('pipeline_status, count(*) c')->groupBy('pipeline_status')->pluck('c', 'pipeline_status');
        $total = (int) $byStatus->sum();
        $mou = (int) ($byStatus['sudah_mou'] ?? 0);

        // kunjungan (catatan pertemuan) per canvaser
        $visitByUser = DB::table('school_notes')->where('kind', 'pertemuan')
            ->selectRaw('created_by, count(*) c')->groupBy('created_by')->pluck('c', 'created_by');

        $perCanvaser = DB::table('schools')
            ->join('users', 'users.id', '=', 'schools.created_by')
            ->selectRaw('users.id, users.name, count(*) total, sum(schools.pipeline_status = "sudah_mou") mou')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->get()
            ->map(fn($u) => [
                'name'      => $u->name,
                'total'     => (int) $u->total,
                'mou'       => (int) $u->mou,
                'kunjungan' => (int) ($visitByUser[$u->id] ?? 0),
                'konversi'  => $u->total > 0 ? (int) round($u->mou / $u->total * 100) : 0,
            ]);

        $tren = DB::table('schools')->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at,'%Y-%m') ym, count(*) c")->groupBy('ym')->orderBy('ym')->get()
            ->map(fn($r) => ['bulan' => $r->ym, 'jumlah' => (int) $r->c]);

        $recent = DB::table('school_notes')
            ->join('schools', 'schools.id', '=', 'school_notes.school_id')
            ->leftJoin('users', 'users.id', '=', 'school_notes.created_by')
            ->where('school_notes.kind', 'pertemuan')
            ->orderByDesc('school_notes.created_at')->limit(12)
            ->selectRaw('school_notes.id, schools.id as school_id, schools.name as school, school_notes.note, school_notes.photo, school_notes.latitude, school_notes.longitude, school_notes.created_at, users.name as canvaser')
            ->get();

        return $this->success([
            'kpi' => [
                'total'          => $total,
                'mou'            => $mou,
                'konversi'       => $total > 0 ? (int) round($mou / $total * 100) : 0,
                'kunjungan'      => (int) DB::table('school_notes')->where('kind', 'pertemuan')->count(),
                'canvaser_aktif' => $perCanvaser->count(),
            ],
            'status' => [
                ['name' => 'Prospek', 'value' => (int) ($byStatus['prospek'] ?? 0)],
                ['name' => 'Dalam Proses', 'value' => (int) ($byStatus['dalam_proses'] ?? 0)],
                ['name' => 'MoU', 'value' => (int) ($byStatus['sudah_mou'] ?? 0)],
                ['name' => 'Tidak Lanjut', 'value' => (int) ($byStatus['tidak_lanjut'] ?? 0)],
            ],
            'per_canvaser' => $perCanvaser,
            'tren'         => $tren,
            'recent'       => $recent,
        ], 'Rekap Canvas.');
    }

    /** Daftar sekolah ringkas untuk dropdown rekap. */
    public function rekapSchools(): JsonResponse
    {
        return $this->success(
            School::orderBy('name')->get(['id', 'name', 'pipeline_status']),
            'Daftar sekolah.'
        );
    }

    /** Semua catatan (pertemuan & audit) sebuah sekolah. */
    public function rekapSchoolNotes(School $school): JsonResponse
    {
        $notes = $school->notes()->with('creator:id,name')->latest()->get();
        return $this->success($notes, 'Catatan sekolah.');
    }
}
