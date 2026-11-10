<?php

use App\Http\Controllers\Admin\InstitutionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Tenant\SchoolController;
use App\Http\Controllers\Tenant\StudentController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\InitializeTenant;
use App\Http\Middleware\RequireMfa;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', HealthController::class);

// --- Autenticação ---------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/login/mfa', [LoginController::class, 'verifyMfa'])->middleware('throttle:20,1');
    Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/mfa/enroll', [MfaController::class, 'enroll'])->middleware('throttle:10,1');
    Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:10,1');
});

Route::middleware(['auth', RequireMfa::class])->group(function () {

    Route::get('/contexts', [ContextController::class, 'index']);
    Route::post('/context', [ContextController::class, 'select']);

    // --- Painel do superadministrador SaaS (somente banco de controle) ----
    Route::prefix('admin')->middleware(EnsurePlatformAdmin::class)->group(function () {
        Route::get('/institutions', [InstitutionController::class, 'index']);
        Route::post('/institutions', [InstitutionController::class, 'store']);
        Route::post('/institutions/{publicId}/suspend', [InstitutionController::class, 'suspend']);
        Route::post('/institutions/{publicId}/reactivate', [InstitutionController::class, 'reactivate']);
    });

    // --- Painel institucional (contexto de tenant obrigatório) -------------
    Route::prefix('app')->middleware([InitializeTenant::class, 'throttle:tenant'])->group(function () {
        Route::get('/schools', [SchoolController::class, 'index']);
        Route::post('/schools', [SchoolController::class, 'store']);
        Route::get('/schools/{school}', [SchoolController::class, 'show']);
        Route::get('/schools/{school}/classes', [SchoolController::class, 'classes']);
        Route::get('/classes/{class}/students', [StudentController::class, 'index']);
        Route::post('/classes/{class}/students', [StudentController::class, 'store']);
    });
});
