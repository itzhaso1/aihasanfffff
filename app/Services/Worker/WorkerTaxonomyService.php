<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Models\User;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerProfileLanguage;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerVerificationIssue;
use App\Models\Worker\WorkerWorkExperience;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class WorkerTaxonomyService
{
    /**
     * @param  list<int>  $skillIds
     */
    public function syncSkills(User $worker, array $skillIds): WorkerProfile
    {
        $profile = $this->profile($worker);
        $ids = WorkerSkill::query()
            ->whereIn('id', $skillIds)
            ->pluck('id')
            ->all();

        if (count($ids) !== count(array_unique($skillIds))) {
            throw new ModelNotFoundException('One or more worker skills do not exist.');
        }

        $profile->skills()->sync($ids);
        $this->resolveIssues($profile, WorkerVerificationIssue::TARGET_SKILLS);

        return $profile->load('skills');
    }

    /**
     * @param  list<string>  $languageCodes
     */
    public function syncLanguages(User $worker, array $languageCodes): WorkerProfile
    {
        $profile = $this->profile($worker);
        $codes = array_values(array_unique($languageCodes));

        DB::transaction(function () use ($profile, $codes): void {
            $profile->languages()->whereNotIn('language_code', $codes)->delete();

            foreach ($codes as $code) {
                WorkerProfileLanguage::query()->updateOrCreate(
                    [
                        'worker_profile_id' => $profile->id,
                        'language_code' => $code,
                    ],
                    [],
                );
            }
        });
        $this->resolveIssues($profile, WorkerVerificationIssue::TARGET_LANGUAGES);

        return $profile->load('languages');
    }

    /**
     * @param  array{title:string, organization_name:string, started_on:string, ended_on:?string, is_current:bool, description:?string}  $attributes
     */
    public function createExperience(User $worker, array $attributes): WorkerWorkExperience
    {
        $profile = $this->profile($worker);

        return $profile->experiences()->create($this->normalizeExperience($attributes));
    }

    /**
     * @param  array{title:string, organization_name:string, started_on:string, ended_on:?string, is_current:bool, description:?string}  $attributes
     */
    public function updateExperience(User $worker, int $experienceId, array $attributes): WorkerWorkExperience
    {
        $experience = $this->ownedExperience($worker, $experienceId);
        $experience->update($this->normalizeExperience($attributes));
        $this->resolveIssues(
            $experience->workerProfile,
            WorkerVerificationIssue::TARGET_EXPERIENCE,
            $experience->id,
        );

        return $experience->refresh();
    }

    public function deleteExperience(User $worker, int $experienceId): void
    {
        $experience = $this->ownedExperience($worker, $experienceId);
        $profile = $experience->workerProfile;
        $experience->delete();
        $this->resolveIssues($profile, WorkerVerificationIssue::TARGET_EXPERIENCE, $experienceId);
    }

    private function profile(User $worker): WorkerProfile
    {
        $profile = WorkerProfile::query()->where('user_id', $worker->id)->first();

        if ($profile === null) {
            throw new ModelNotFoundException('Worker profile was not found.');
        }

        return $profile;
    }

    private function ownedExperience(User $worker, int $experienceId): WorkerWorkExperience
    {
        $profile = $this->profile($worker);

        $experience = WorkerWorkExperience::query()
            ->whereKey($experienceId)
            ->where('worker_profile_id', $profile->id)
            ->first();

        if ($experience === null) {
            throw new ModelNotFoundException('Worker experience was not found.');
        }

        return $experience;
    }

    /**
     * @param  array{title:string, organization_name:string, started_on:string, ended_on:?string, is_current:bool, description:?string}  $attributes
     * @return array{title:string, organization_name:string, started_on:string, ended_on:?string, is_current:bool, description:?string}
     */
    private function normalizeExperience(array $attributes): array
    {
        $current = (bool) $attributes['is_current'];

        return [
            'title' => $attributes['title'],
            'organization_name' => $attributes['organization_name'],
            'started_on' => $attributes['started_on'],
            'ended_on' => $current ? null : $attributes['ended_on'],
            'is_current' => $current,
            'description' => $attributes['description'] ?? null,
        ];
    }

    private function resolveIssues(
        WorkerProfile $profile,
        string $targetType,
        ?int $targetId = null,
    ): void {
        $query = WorkerVerificationIssue::query()
            ->where('worker_profile_id', $profile->id)
            ->where('target_type', $targetType)
            ->where('status', WorkerVerificationIssue::STATUS_OPEN);

        if ($targetId === null) {
            $query->whereNull('target_id');
        } else {
            $query->where('target_id', $targetId);
        }

        $query->update([
            'status' => WorkerVerificationIssue::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }
}
