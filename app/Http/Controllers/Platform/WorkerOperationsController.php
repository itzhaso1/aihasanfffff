<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\WorkerOperationalReportRequest;
use App\Models\PlatformAdmin;
use App\Services\Worker\WorkerOperationalReportingService;
use Illuminate\View\View;

final class WorkerOperationsController extends Controller
{
    public function __construct(
        private readonly WorkerOperationalReportingService $reporting,
    ) {}

    public function index(WorkerOperationalReportRequest $request): View
    {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        return view('platform.worker-operations.index', $this->reporting->platformOverview(
            $admin,
            $request->validated(),
        ));
    }
}
