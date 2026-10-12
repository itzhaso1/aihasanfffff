<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\StoreWorkerExperienceRequest;
use App\Http\Requests\Worker\SyncWorkerLanguagesRequest;
use App\Http\Requests\Worker\SyncWorkerSkillsRequest;
use App\Http\Requests\Worker\UpdateWorkerExperienceRequest;
use App\Services\Worker\WorkerTaxonomyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;

class WorkerTaxonomyController extends Controller
{
    public function __construct(
        private readonly WorkerTaxonomyService $taxonomy,
    ) {}

    public function syncSkills(SyncWorkerSkillsRequest $request): RedirectResponse
    {
        $this->taxonomy->syncSkills(
            $request->user(),
            array_map('intval', $request->validated('skill_ids')),
        );

        return back()->with('success', 'تم حفظ المهارات المهنية.');
    }

    public function syncLanguages(SyncWorkerLanguagesRequest $request): RedirectResponse
    {
        $this->taxonomy->syncLanguages(
            $request->user(),
            $request->validated('language_codes'),
        );

        return back()->with('success', 'تم حفظ اللغات.');
    }

    public function storeExperience(StoreWorkerExperienceRequest $request): RedirectResponse
    {
        $this->taxonomy->createExperience($request->user(), $request->safe()->only([
            'title',
            'organization_name',
            'started_on',
            'ended_on',
            'is_current',
            'description',
        ]));

        return back()->with('success', 'تمت إضافة الخبرة المهنية.');
    }

    public function updateExperience(UpdateWorkerExperienceRequest $request, int $experience): RedirectResponse
    {
        try {
            $this->taxonomy->updateExperience($request->user(), $experience, $request->safe()->only([
                'title',
                'organization_name',
                'started_on',
                'ended_on',
                'is_current',
                'description',
            ]));
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return back()->with('success', 'تم تحديث الخبرة المهنية.');
    }

    public function destroyExperience(int $experience): RedirectResponse
    {
        $user = request()->user();
        abort_unless($user?->workerProfile !== null, 403);

        try {
            $this->taxonomy->deleteExperience($user, $experience);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return back()->with('success', 'تم حذف الخبرة المهنية.');
    }
}
