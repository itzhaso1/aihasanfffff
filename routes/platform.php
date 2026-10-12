<?php

use App\Http\Controllers\Platform\AiModelSettingsController;
use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\MerchantVerificationController;
use App\Http\Controllers\Platform\OpsController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SiteSettingsController;
use App\Http\Controllers\Platform\SubscriptionController;
use App\Http\Controllers\Platform\UserController;
use App\Http\Controllers\Platform\WorkerContractRateController;
use App\Http\Controllers\Platform\WorkerDisputeController;
use App\Http\Controllers\Platform\WorkerOperationsController;
use App\Http\Controllers\Platform\WorkerPayoutController;
use App\Http\Controllers\Platform\WorkerVerificationController;
use App\Http\Controllers\Platform\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth('platform_admin')->check()) {
        return redirect()->route('platform.dashboard');
    }

    return redirect()->route('platform.login');
})->name('platform.home');

Route::middleware('guest:platform_admin')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('platform.login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('platform.login.store');
});

Route::middleware('platform.admin')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('platform.logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('platform.dashboard');
    Route::get('/ops/health', [OpsController::class, 'health'])->name('platform.ops.health');
    Route::get('/ops/metrics', [OpsController::class, 'metrics'])->name('platform.ops.metrics');

    Route::get('/site', [SiteSettingsController::class, 'edit'])->name('platform.site.edit');
    Route::put('/site', [SiteSettingsController::class, 'update'])->name('platform.site.update');

    Route::get('/ai', [AiModelSettingsController::class, 'edit'])->name('platform.ai.edit');
    Route::put('/ai', [AiModelSettingsController::class, 'update'])->name('platform.ai.update');

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
    Route::get('/subscriptions/create', [SubscriptionController::class, 'create'])->name('platform.subscriptions.create');
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])->name('platform.subscriptions.store');
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

    Route::get('/worker-verifications', [WorkerVerificationController::class, 'index'])->name('platform.worker-verifications.index');
    Route::get('/worker-verifications/{workerProfile}', [WorkerVerificationController::class, 'show'])->name('platform.worker-verifications.show');
    Route::post('/worker-verifications/{workerProfile}/approve', [WorkerVerificationController::class, 'approve'])->name('platform.worker-verifications.approve');
    Route::post('/worker-verifications/{workerProfile}/reject', [WorkerVerificationController::class, 'reject'])->name('platform.worker-verifications.reject');
    Route::post('/worker-verifications/{workerProfile}/request-documents', [WorkerVerificationController::class, 'requestDocuments'])->name('platform.worker-verifications.request-documents');
    Route::post('/worker-verifications/{workerProfile}/suspend', [WorkerVerificationController::class, 'suspend'])->name('platform.worker-verifications.suspend');
    Route::get('/worker-verifications/documents/{document}/download', [WorkerVerificationController::class, 'downloadDocument'])
        ->name('platform.worker-verifications.documents.download');

    Route::get('/worker-operations', [WorkerOperationsController::class, 'index'])
        ->name('platform.worker-operations.index');
    Route::get('/worker-contract-rates', [WorkerContractRateController::class, 'index'])
        ->name('platform.worker-contract-rates.index');
    Route::post('/worker-contract-rates', [WorkerContractRateController::class, 'store'])
        ->name('platform.worker-contract-rates.store');
    Route::post('/worker-contract-rates/{rate}/disable', [WorkerContractRateController::class, 'disable'])
        ->name('platform.worker-contract-rates.disable');
    Route::get('/worker-disputes', [WorkerDisputeController::class, 'index'])
        ->name('platform.worker-disputes.index');
    Route::get('/worker-disputes/{dispute}', [WorkerDisputeController::class, 'show'])
        ->whereNumber('dispute')
        ->name('platform.worker-disputes.show');
    Route::post('/worker-engagements/{engagement}/disputes', [WorkerDisputeController::class, 'store'])
        ->whereNumber('engagement')
        ->name('platform.worker-engagements.disputes.store');
    Route::post('/worker-disputes/{dispute}/under-review', [WorkerDisputeController::class, 'beginReview'])
        ->whereNumber('dispute')
        ->name('platform.worker-disputes.under-review');
    Route::post('/worker-disputes/{dispute}/resolve', [WorkerDisputeController::class, 'resolve'])
        ->whereNumber('dispute')
        ->name('platform.worker-disputes.resolve');
    Route::post('/worker-disputes/{dispute}/close', [WorkerDisputeController::class, 'close'])
        ->whereNumber('dispute')
        ->name('platform.worker-disputes.close');
    Route::get('/worker-payouts', [WorkerPayoutController::class, 'index'])->name('platform.worker-payouts.index');
    Route::post('/worker-payouts', [WorkerPayoutController::class, 'store'])->name('platform.worker-payouts.store');
    Route::patch('/worker-payouts/{payout}', [WorkerPayoutController::class, 'update'])->name('platform.worker-payouts.update');
});
