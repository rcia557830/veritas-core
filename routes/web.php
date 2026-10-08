<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/forgot-password', [AuthController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::middleware(['auth', 'active', 'role:owner,bookkeeper,office-manager'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile.edit');
    Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/clients/{record}/archive', [ClientController::class, 'archive'])->name('clients.archive');
    Route::get('/documents/{record}/validate', [DocumentController::class, 'validation'])->middleware('permission:document.validate')->name('documents.validation');
    Route::post('/documents/{record}/validate', [DocumentController::class, 'validateDocument'])->middleware('permission:document.validate')->name('documents.validate');
    Route::get('/documents/{record}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::post('/ledger/{record}/transition', [LedgerController::class, 'transition'])->name('ledger.transition');
    Route::post('/billing/{record}/transition', [InvoiceController::class, 'transition'])->name('billing.transition');
    Route::post('/billing/{record}/payments', [PaymentController::class, 'store'])->name('billing.payments.store');
    Route::get('/billing/{record}/print', [InvoiceController::class, 'print'])->name('billing.print');
    Route::get('/billing/{record}/pdf', [InvoiceController::class, 'pdf'])->name('billing.pdf');
    foreach (['clients' => ClientController::class, 'documents' => DocumentController::class, 'ledger' => LedgerController::class, 'compliance' => ComplianceController::class, 'billing' => InvoiceController::class, 'knowledge' => KnowledgeController::class] as $module => $controller) {
        Route::resource($module, $controller)->parameters([$module => 'record'])->except($module === 'billing' ? ['destroy'] : []);
    }
    Route::get('/search', SearchController::class)->middleware('throttle:90,1')->name('search');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'all'])->name('notifications.all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:report.view,report.generate')->name('reports.index');
    Route::get('/reports/csv', [ReportController::class, 'csv'])->middleware('permission:report.export')->name('reports.csv');
    Route::middleware('permission:notice.view')->group(function () {
        Route::post('/notices/{notice}/publish', [NoticeController::class, 'publish'])->name('notices.publish');
        Route::post('/notices/{notice}/archive', [NoticeController::class, 'archive'])->name('notices.archive');
        Route::resource('notices', NoticeController::class)->except('destroy');
    });
    Route::middleware('role:owner')->group(function () {
        Route::get('/workspace', [SettingController::class, 'edit'])->middleware('permission:workspace.manage')->name('workspace.edit');
        Route::put('/workspace', [SettingController::class, 'update'])->middleware('permission:workspace.manage')->name('workspace.update');
        Route::resource('admin/users', UserController::class)->names('admin.users')->except(['show', 'destroy']);
        Route::post('/admin/users/{user}/reset-password', [UserController::class, 'reset'])->name('admin.users.reset');
        Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('admin.audit.index');
    });
});
