<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerWorkExperience;
use App\Services\Worker\WorkerVerificationRequirementCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin WorkerProfile */
class WorkerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $country = (string) $this->country_code;
        $required = in_array($country, WorkerProfile::COUNTRIES, true)
            ? app(WorkerVerificationRequirementCatalog::class)->requiredDocumentTypes($country)
            : [];

        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'profile_photo' => filled($this->profile_photo)
                ? Storage::disk('local')->url($this->profile_photo)
                : null,
            'country_code' => $this->country_code,
            'nationality' => $this->nationality,
            'verification_status' => $this->verification_status,
            'verification_status_label' => $this->verificationStatusLabel(),
            'lifecycle_status' => $this->lifecycle_status,
            'lifecycle_status_label' => $this->lifecycleStatusLabel(),
            'availability_status' => $this->availability_status,
            'availability_status_label' => $this->availabilityStatusLabel(),
            'verification_notice' => $this->relationLoaded('latestVerification')
                ? $this->workerVisibleVerificationNotice()
                : null,
            'professional_summary' => $this->professional_summary,
            'sales_experience_level' => $this->sales_experience_level,
            'required_document_types' => $required,
            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn (WorkerSkill $skill): array => [
                'id' => $skill->id,
                'skill_key' => $skill->skill_key,
                'display_name' => $skill->display_name,
            ])->values()->all()),
            'languages' => $this->whenLoaded('languages', fn () => $this->languages->map(fn ($language): array => [
                'language_code' => $language->language_code,
            ])->values()->all()),
            'experiences' => $this->whenLoaded('experiences', fn () => $this->experiences->map(fn (WorkerWorkExperience $experience): array => [
                'id' => $experience->id,
                'title' => $experience->title,
                'organization_name' => $experience->organization_name,
                'started_on' => $experience->started_on?->toDateString(),
                'ended_on' => $experience->ended_on?->toDateString(),
                'is_current' => (bool) $experience->is_current,
                'description' => $experience->description,
            ])->values()->all()),
            'verification_issues' => WorkerVerificationIssueResource::collection(
                $this->whenLoaded('openVerificationIssues', fn () => $this->openVerificationIssues, collect()),
            ),
        ];
    }
}
