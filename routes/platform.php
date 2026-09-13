<?php

use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\MerchantVerificationController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SubscriptionController;
use App\Http\Controllers\Platform\UserController;
use App\Http\Controllers\Platform\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:platform_admin')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('platform.login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('platform.login.store');
});

Route::middleware('platform.admin')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('platform.logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('platform.dashboard');

    Route::get('/users', [UserController::class, 'index'])->name('platform.users.index');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('platform.users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('platform.users.update');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('platform.workspaces.index');
    Route::get('/workspaces/{workspace}/edit', [WorkspaceController::class, 'edit'])->name('platform.workspaces.edit');
    Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('platform.workspaces.update');

    Route::resource('plans', PlanController::class, [
        'as' => 'platform',
    ])->except(['show']);

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('platform.subscriptions.index');
    Route::get('/subscriptions/{subscription}/edit', [SubscriptionController::class, 'edit'])->name('platform.subscriptions.edit');
    Route::put('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('platform.subscriptions.update');

    Route::get('/merchant-verifications', [MerchantVerificationController::class, 'index'])->name('platform.merchant-verifications.index');
    Route::get('/merchant-verifications/{merchantProfile}', [MerchantVerificationController::class, 'show'])->name('platform.merchant-verifications.show');
    Route::post('/merchant-verifications/{merchantProfile}/approve', [MerchantVerificationController::class, 'approve'])->name('platform.merchant-verifications.approve');
    Route::post('/merchant-verifications/{merchantProfile}/reject', [MerchantVerificationController::class, 'reject'])->name('platform.merchant-verifications.reject');
    Route::post('/merchant-verifications/{merchantProfile}/suspend', [MerchantVerificationController::class, 'suspend'])->name('platform.merchant-verifications.suspend');
    Route::post('/merchant-verifications/{merchantProfile}/request-documents', [MerchantVerificationController::class, 'requestDocuments'])->name('platform.merchant-verifications.request-documents');
    Route::get('/merchant-verifications/documents/{document}/download', [MerchantVerificationController::class, 'downloadDocument'])
        ->name('platform.merchant-verifications.documents.download');
});
