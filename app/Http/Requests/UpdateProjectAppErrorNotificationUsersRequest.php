<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Services\ProjectAppErrorNotificationService;
use App\Services\ShiftPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectAppErrorNotificationUsersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && app(ShiftPermissionService::class)->canManageTechnicalSettings($project, $this->user()?->id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
            'settings' => ['sometimes', 'array:recurrence_enabled,quiet_hours,daily_summary_enabled,daily_summary_time,daily_summary_timezone'],
            'settings.recurrence_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.quiet_hours' => ['sometimes', 'required', 'integer', 'between:1,168'],
            'settings.daily_summary_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.daily_summary_time' => ['sometimes', 'required', 'date_format:H:i'],
            'settings.daily_summary_timezone' => ['sometimes', 'required', 'timezone'],
        ];
    }

    public function messages(): array
    {
        return [
            'settings.quiet_hours.between' => 'Choose an alert cooldown between 1 and 168 hours.',
            'settings.quiet_hours.integer' => 'The alert cooldown must be a whole number of hours.',
            'settings.daily_summary_time.date_format' => 'Choose a valid daily summary time.',
            'settings.daily_summary_timezone.timezone' => 'Choose a valid time zone.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $project = $this->route('project');

            if (! $project instanceof Project) {
                return;
            }

            $submittedUserIds = collect($this->input('user_ids', []))
                ->map(fn ($userId) => (int) $userId)
                ->filter(fn (int $userId) => $userId > 0)
                ->unique()
                ->values();

            if ($submittedUserIds->isEmpty()) {
                return;
            }

            $eligibleUserIds = app(ProjectAppErrorNotificationService::class)->eligibleUserIds($project);

            if ($submittedUserIds->diff($eligibleUserIds)->isNotEmpty()) {
                $validator->errors()->add('user_ids', 'One or more selected recipients are not eligible for app error notifications.');
            }
        });
    }
}
