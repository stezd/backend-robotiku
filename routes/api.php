<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SchoolAdminAuthController;
use App\Http\Controllers\Api\V1\Auth\ParentLookupController;
use App\Http\Controllers\Api\V1\PromoController;
use App\Http\Controllers\Api\V1\DaftarController;
use App\Http\Controllers\Api\V1\SchoolStudentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Bayar\PaymentController;
use App\Http\Controllers\Api\V1\Canvas\CanvasDashboardController;
use App\Http\Controllers\Api\V1\Canvas\SchoolController;
use App\Http\Controllers\Api\V1\Bayar\PaymentVerificationController;
use App\Http\Controllers\Api\V1\Murid\ProgressController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\StudentController;
use App\Http\Controllers\Api\V1\Admin\ClassController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\DiscountCodeController;
use App\Http\Controllers\Api\V1\ArticlePublicController;
use App\Http\Controllers\Api\V1\Admin\ArticleController;
use App\Http\Controllers\Api\V1\Karyawan\EmployeeAttendanceController;
use App\Http\Controllers\Api\V1\Murid\EReportController;
use App\Http\Controllers\Api\V1\Landing\LandingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\Sekolah\SchoolPortalController;
use App\Http\Controllers\Api\V1\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\V1\Admin\SchoolAdminController;
use App\Http\Controllers\Api\V1\Admin\BankAccountController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Murid\SessionController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Admin\PeriodController;
use App\Http\Controllers\Api\V1\Sekolah\SchoolPaymentController;
use App\Http\Controllers\Api\V1\Keuangan\FinanceController;
use App\Http\Controllers\Api\V1\Keuangan\KeuanganDashboardController;
use App\Http\Controllers\Api\V1\Admin\InstansiPaymentController;
use App\Http\Controllers\Api\V1\Ortu\ParentDashboardController;
use App\Http\Controllers\Api\V1\Admin\ParentController;
use App\Http\Controllers\Api\V1\Bayar\BillingReminderController;


Route::prefix('v1')->group(function () {

    /* ---------- PUBLIK ---------- */


    Route::get('landing', [LandingController::class, 'index']);
    Route::get('landing/{section}', [LandingController::class, 'show']);
    // Auth internal (admin.robotiku.id)
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Auth Admin Sekolah (robotiku.id)
    Route::post('auth/school-admin/login', [SchoolAdminAuthController::class, 'login'])->middleware('throttle:login');

    // Lookup Orang Tua — passwordless, tanpa token
    Route::post('auth/parent/lookup', [ParentLookupController::class, 'lookup'])->middleware('throttle:login');

    // Daftar mandiri + cek promo
    Route::get('public-media/{path}', [MediaController::class, 'publicShow'])->where('path', '.*')->middleware('throttle:api');
    Route::post('promo/check', [PromoController::class, 'check'])->middleware('throttle:api');
    Route::post('daftar', [DaftarController::class, 'mandiri'])->middleware('throttle:api');
    Route::get('ukuran-kaos', [SettingController::class, 'shirtChart'])->middleware('throttle:api');
    Route::post('bayar/tagihan', [PaymentController::class, 'parentTagihan'])->middleware('throttle:api');
    Route::post('bayar/upload', [PaymentController::class, 'parentUpload'])->middleware('throttle:api');

    Route::get('rekening-robotiku', [\App\Http\Controllers\Api\V1\Admin\BankAccountController::class, 'active'])->middleware('throttle:api');
    Route::post('daftar/bayar-mandiri', [DaftarController::class, 'mandiriBayar'])->middleware('throttle:api');

    Route::post('murid/progress', [ProgressController::class, 'parent'])->middleware('throttle:api');
    Route::post('ortu/dashboard', [ParentDashboardController::class, 'index'])->middleware('throttle:api');
    Route::post('ortu/bayar', [ParentDashboardController::class, 'pay'])->middleware('throttle:api');
    Route::post('e-rapot/parent', [EReportController::class, 'parentList'])->middleware('throttle:api');
    Route::post('e-rapot/{eReport}/parent-pdf', [EReportController::class, 'parentPdf'])->middleware('throttle:api');

    Route::get('artikel', [ArticlePublicController::class, 'index']);
    Route::get('artikel/{slug}', [ArticlePublicController::class, 'show']);
    // Sekolah MOU untuk dropdown daftar instansi (publik)
    Route::get('sekolah/mou', [SchoolController::class, 'mou'])->middleware('throttle:api');

    // Daftar via instansi — publik, school_id dari body
    Route::post('daftar/instansi', [DaftarController::class, 'instansi'])->middleware('throttle:api');
    Route::post('daftar/instansi/preview', [DaftarController::class, 'instansiPreview'])->middleware('throttle:api');
    Route::post('daftar/instansi/import', [DaftarController::class, 'instansiImport'])->middleware('throttle:api');
    Route::get('daftar/instansi/template', [DaftarController::class, 'instansiTemplate'])->middleware('throttle:api');
    Route::post('daftar/instansi/bayar', [DaftarController::class, 'instansiBayar'])->middleware('throttle:api');

    Route::get('programs', [ProgramController::class, 'index'])->middleware('throttle:api');

    /* ---------- TERPROTEKSI (butuh token) ---------- */
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        Route::get('media/{path}', [MediaController::class, 'show'])->where('path', '.*');
        Route::get('notifikasi', [NotificationController::class, 'index']);
        Route::get('notifikasi/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('notifikasi/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifikasi/{notification}/read', [NotificationController::class, 'markRead']);
        Route::get('bank-robotiku', [BankAccountController::class, 'active']);
        // Umum (internal & school admin)
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/school-admin/logout', [SchoolAdminAuthController::class, 'logout']);

        Route::get('sekolah/pembayaran', [SchoolPaymentController::class, 'index']);
        Route::post('sekolah/pembayaran/upload', [SchoolPaymentController::class, 'collectiveUpload']);

        Route::get('sekolah/pembayaran-masuk', [SchoolPaymentController::class, 'pendingPayments']);
        Route::post('sekolah/pembayaran/{payment}/verifikasi', [SchoolPaymentController::class, 'verify']);
        Route::get('sekolah/setoran/tersedia', [SchoolPaymentController::class, 'availableInvoices']);
        Route::post('sekolah/setoran', [SchoolPaymentController::class, 'createSettlement']);
        Route::get('sekolah/setoran', [SchoolPaymentController::class, 'settlements']);
        Route::get('sekolah/setoran/{settlement}', [SchoolPaymentController::class, 'showSettlement']);
        Route::get('sekolah/pembayaran-riwayat', [SchoolPaymentController::class, 'paymentHistory']);

        Route::get('sekolah/rekening', [SchoolPortalController::class, 'rekening']);
        Route::put('sekolah/rekening', [SchoolPortalController::class, 'updateRekening']);
        Route::post('sekolah/rekening/qris', [SchoolPortalController::class, 'uploadQris']);
        Route::get('sekolah/dashboard', [SchoolPortalController::class, 'kpi']);
        Route::get('sekolah/murid', [SchoolPortalController::class, 'students']);
        Route::get('sekolah/murid/{student}', [SchoolPortalController::class, 'showStudent']);
        // Admin Sekolah — daftar murid (controller cek instanceof SchoolAdmin)
        Route::post('sekolah/murid', [SchoolStudentController::class, 'store']);
        Route::post('sekolah/murid/preview-excel', [SchoolStudentController::class, 'previewExcel']);
        Route::post('sekolah/murid/import-excel', [SchoolStudentController::class, 'importExcel']);

        Route::get('bayar/sekolah/invoices', [PaymentController::class, 'schoolInvoices']);
        Route::post('bayar/sekolah/invoices/{invoice}/upload', [PaymentController::class, 'schoolUpload']);
        Route::middleware('auth:sanctum')->post('sekolah/ganti-password', [SchoolAdminAuthController::class, 'changePassword']);
        Route::get('sekolah/tagihan', [SchoolPaymentController::class, 'studentInvoices']);

        // Contoh route khusus role internal (placeholder uji RBAC)
        Route::get('ping/internal', fn() => response()->json(['status' => true, 'data' => 'pong', 'message' => 'OK']))
            ->middleware('role:super_admin,admin');

        Route::middleware('role:marketing,admin,super_admin')->group(function () {
            Route::get('canvas/dashboard-marketing', [CanvasDashboardController::class, 'marketing']);
            Route::get('canvas/schools', [SchoolController::class, 'index']);
            Route::post('canvas/schools', [SchoolController::class, 'store']);
            Route::get('canvas/schools/{school}', [SchoolController::class, 'show']);
            Route::patch('canvas/schools/{school}/status', [SchoolController::class, 'changeStatus']);
            Route::post('canvas/schools/{school}/notes', [SchoolController::class, 'addNote']);
            Route::post('canvas/upload', [SchoolController::class, 'upload']);                       // foto/QRIS
            Route::patch('canvas/schools/{school}/commission', [SchoolController::class, 'setCommission']);
            Route::get('canvas/schools/{school}/mou', [SchoolController::class, 'mouIndex']);
            Route::post('canvas/schools/{school}/mou', [SchoolController::class, 'mouStore']);
            Route::delete('canvas/mou/{mou}', [SchoolController::class, 'mouDestroy']);
            Route::get('canvas/mou/{mou}/file', [SchoolController::class, 'mouFile']);
        });

        Route::middleware('role:admin_keuangan,admin,super_admin')->group(function () {
            Route::get('bayar/payments', [PaymentVerificationController::class, 'index']);
            Route::delete('bayar/payments/{payment}', [PaymentVerificationController::class, 'destroy']);
            Route::get('bayar/payments/{payment}/proof', [PaymentVerificationController::class, 'proof']);
            Route::post('bayar/payments/{payment}/verify', [PaymentVerificationController::class, 'verify']);
            Route::get('bayar/invoices/{invoice}/wa', [PaymentVerificationController::class, 'waLink']);
            Route::get('bank-accounts', [BankAccountController::class, 'index']);
            Route::post('bank-accounts', [BankAccountController::class, 'store']);
            Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update']);
            Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);
            Route::get('keuangan/dashboard', [KeuanganDashboardController::class, 'index']);
            Route::get('keuangan/dashboard/kpi', [KeuanganDashboardController::class, 'kpi']);
            Route::get('keuangan/dashboard/trend', [KeuanganDashboardController::class, 'trend']);
            Route::get('keuangan/setoran', [FinanceController::class, 'settlements']);
            Route::post('keuangan/setoran/{settlement}/verifikasi', [FinanceController::class, 'verifySettlement']);
            Route::get('tagihan/instansi/sekolah', [BillingReminderController::class, 'instansiSchools']);
            Route::get('tagihan/instansi/sekolah/{school}', [BillingReminderController::class, 'instansiSchool']);
            Route::get('tagihan/instansi/sekolah/{school}/wa', [BillingReminderController::class, 'instansiSchoolWaLink']);
            Route::post('tagihan/instansi/sekolah/{school}/kirim', [BillingReminderController::class, 'instansiSchoolSend']);
            Route::get('tagihan', [BillingReminderController::class, 'index']);
            Route::get('tagihan/{invoice}/wa', [BillingReminderController::class, 'waLink']);
            Route::post('tagihan/{invoice}/kirim', [BillingReminderController::class, 'send']);
        });

        Route::middleware('role:trainer')->group(function () {
            Route::post('absensi-karyawan', [EmployeeAttendanceController::class, 'store']);
            Route::get('absensi-karyawan/today', [EmployeeAttendanceController::class, 'today']);
            Route::get('sesi/kelas', [SessionController::class, 'myClasses']);
            Route::post('sesi/mulai', [SessionController::class, 'start']);
            Route::get('sesi/{session}/murid', [SessionController::class, 'students']);
            Route::post('sesi/{session}/absensi', [SessionController::class, 'attend']);
            Route::post('sesi/{session}/selesai', [SessionController::class, 'end']);
            Route::get('sesi/kelas/{kelas}/periode', [SessionController::class, 'periods']);
        });

        Route::get('sekolah/murid/{student}/progress', [ProgressController::class, 'school']);
        Route::get('sekolah/murid/{student}/e-rapot', [EReportController::class, 'schoolList']);
        Route::get('sekolah/e-rapot/{eReport}/pdf', [EReportController::class, 'schoolPdf']);

        Route::middleware('role:trainer,admin,super_admin')->group(function () {
            Route::get('manajemen/murid/{student}/progress', [ProgressController::class, 'internal']);
            Route::get('e-rapot/kelas',   [EReportController::class, 'gradableClasses']);
            Route::get('e-rapot/matrix',  [EReportController::class, 'matrix']);
            Route::get('e-rapot/prefill', [EReportController::class, 'prefill']);
            Route::get('profil/tanda-tangan',  [EReportController::class, 'mySignature']);
            Route::post('profil/tanda-tangan', [EReportController::class, 'updateMySignature']);
            Route::get('e-rapot', [EReportController::class, 'index']);
            Route::post('e-rapot', [EReportController::class, 'store']);
            Route::get('e-rapot/template',     [EReportController::class, 'importTemplate']);
            Route::post('e-rapot/import-parse', [EReportController::class, 'importParse']);
            Route::get('e-rapot/{eReport}', [EReportController::class, 'show']);
            Route::put('e-rapot/{eReport}', [EReportController::class, 'update']);
            Route::get('e-rapot/{eReport}/pdf', [EReportController::class, 'pdf']);
            Route::get('e-rapot/{eReport}/excel', [EReportController::class, 'exportExcel']);
            Route::get('sesi/rekap', [SessionController::class, 'rekap']);
            Route::get('sesi/{session}/detail', [SessionController::class, 'show']);
            Route::get('sesi/kelas', [SessionController::class, 'myClasses']);
            Route::get('sesi/kelas/{kelas}/list', [SessionController::class, 'classSessions']);
            Route::post('sesi/manual', [SessionController::class, 'manualStore']);
            Route::post('sesi/mulai', [SessionController::class, 'start']);
            Route::get('sesi/{session}/murid', [SessionController::class, 'students']);
            Route::post('sesi/{session}/absensi', [SessionController::class, 'attend']);
            Route::post('sesi/{session}/selesai', [SessionController::class, 'end']);
        });

        Route::middleware('role:admin,super_admin')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::put('landing/{section}', [LandingController::class, 'update']);
            Route::post('landing-upload', [LandingController::class, 'upload']);
            Route::get('siswa/export/excel', [StudentController::class, 'exportExcel']);
            Route::get('siswa/export/pdf', [StudentController::class, 'exportPdf']);
            Route::get('siswa', [StudentController::class, 'index']);
            Route::get('siswa/{student}', [StudentController::class, 'show']);
            Route::patch('siswa/{student}/status', [StudentController::class, 'changeStatus']);
            Route::put('canvas/schools/{school}', [SchoolController::class, 'update']);
            Route::delete('canvas/schools/{school}', [SchoolController::class, 'destroy']);
            Route::get('canvas/analitik', [CanvasDashboardController::class, 'analitik']);
            Route::post('canvas/target-kunjungan', [CanvasDashboardController::class, 'updateTarget']);
            Route::get('canvas/rekap/schools', [SchoolController::class, 'rekapSchools']);
            Route::get('canvas/rekap/schools/{school}/notes', [SchoolController::class, 'rekapSchoolNotes']);
            Route::get('kelas', [ClassController::class, 'index']);
            Route::post('kelas', [ClassController::class, 'store']);
            Route::get('kelas/{kelas}', [ClassController::class, 'show']);
            Route::put('kelas/{kelas}', [ClassController::class, 'update']);
            Route::post('kelas/{kelas}/murid', [ClassController::class, 'assignStudents']);
            Route::delete('kelas/{kelas}/murid/{studentId}', [ClassController::class, 'removeStudent']);
            Route::get('admin/artikel', [ArticleController::class, 'index']);
            Route::post('admin/artikel', [ArticleController::class, 'store']);
            Route::get('admin/artikel/{article}', [ArticleController::class, 'show']);
            Route::put('admin/artikel/{article}', [ArticleController::class, 'update']);
            Route::delete('admin/artikel/{article}', [ArticleController::class, 'destroy']);
            Route::get('absensi-karyawan/rekap', [SessionController::class, 'rekap']);
            Route::delete('absensi-karyawan/{employeeAttendance}', [EmployeeAttendanceController::class, 'destroy']);
            Route::get('promo', [DiscountCodeController::class, 'index']);
            Route::post('promo', [DiscountCodeController::class, 'store']);
            Route::get('promo/{discountCode}', [DiscountCodeController::class, 'show']);
            Route::put('promo/{discountCode}', [DiscountCodeController::class, 'update']);
            Route::delete('promo/{discountCode}', [DiscountCodeController::class, 'destroy']);
            Route::get('trainers', [ClassController::class, 'trainers']);
            Route::delete('kelas/{kelas}', [ClassController::class, 'destroy']);
            Route::get('program', [AdminProgramController::class, 'index']);
            Route::post('program', [AdminProgramController::class, 'store']);
            Route::get('program/{program}', [AdminProgramController::class, 'show']);
            Route::put('program/{program}', [AdminProgramController::class, 'update']);
            Route::delete('program/{program}', [AdminProgramController::class, 'destroy']);
            Route::get('akun-sekolah', [SchoolAdminController::class, 'index']);
            Route::post('akun-sekolah', [SchoolAdminController::class, 'store']);
            Route::put('akun-sekolah/{schoolAdmin}', [SchoolAdminController::class, 'update']);
            Route::patch('akun-sekolah/{schoolAdmin}/password', [SchoolAdminController::class, 'resetPassword']);
            Route::patch('akun-sekolah/{schoolAdmin}/status', [SchoolAdminController::class, 'toggleActive']);
            Route::get('akun/sekolah/mou-schools', [SchoolAdminController::class, 'mouSchools']);
            Route::get('akun/sekolah', [SchoolAdminController::class, 'index']);
            Route::post('akun/sekolah', [SchoolAdminController::class, 'store']);
            Route::put('akun/sekolah/{schoolAdmin}', [SchoolAdminController::class, 'update']);
            Route::post('akun/sekolah/{schoolAdmin}/reset-password', [SchoolAdminController::class, 'resetPassword']);
            Route::post('akun/sekolah/{schoolAdmin}/toggle-active', [SchoolAdminController::class, 'toggleActive']);
            Route::get('periode', [PeriodController::class, 'index']);
            Route::post('periode', [PeriodController::class, 'store']);
            Route::put('periode/{period}', [PeriodController::class, 'update']);
            Route::delete('periode/{period}', [PeriodController::class, 'destroy']);
            Route::get('instansi/pembayaran', [InstansiPaymentController::class, 'index']);
            Route::post('instansi/pembayaran/{payment}/verifikasi', [InstansiPaymentController::class, 'verify']);
            Route::post('pengaturan/ukuran-kaos', [SettingController::class, 'updateShirtChart']);
            Route::post('admin/artikel/upload', [ArticleController::class, 'uploadImage']);
            Route::get('mou/periode', [ClassController::class, 'schoolPeriods']);
        });

        Route::middleware('role:super_admin')->group(function () {
            Route::get('akun', [UserController::class, 'index']);
            Route::post('akun', [UserController::class, 'store']);
            Route::put('akun/{user}', [UserController::class, 'update']);
            Route::patch('akun/{user}/password', [UserController::class, 'resetPassword']);
            Route::patch('akun/{user}/status', [UserController::class, 'toggleActive']);
            Route::get('pengaturan/lokasi-kantor', [SettingController::class, 'officeLocation']);
            Route::put('pengaturan/lokasi-kantor', [SettingController::class, 'updateOfficeLocation']);
            Route::get('pengaturan/wa', [SettingController::class, 'wa']);
            Route::put('pengaturan/wa', [SettingController::class, 'updateWa']);
            Route::post('pengaturan/wa/test', [SettingController::class, 'testWa']);
            Route::get('akun/orang-tua', [ParentController::class, 'index']);
        });
    });
});
