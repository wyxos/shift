<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectAppErrorNotificationUsersRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\AppErrors\AppErrorSummaryScheduler;
use App\Services\ProjectAppErrorNotificationService;
use App\Services\ShiftPermissionService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProjectAppErrorNotificationController extends Controller
{
    public function __construct(
        private readonly ShiftPermissionService $permissions,
        private readonly ProjectAppErrorNotificationService $notifications,
    ) {}

    public function show(Project $project): JsonResponse
    {
        abort_unless(
            $this->permissions->canManageTechnicalSettings($project, auth()->id()),
            403,
        );

        return response()->json($this->payload($project));
    }

    public function update(UpdateProjectAppErrorNotificationUsersRequest $request, Project $project): JsonResponse
    {
        $attributes = $request->validated();

        $project->getConnection()->transaction(function () use ($project, $attributes): void {
            $settings = $attributes['settings'] ?? [];
            $columns = [
                'recurrence_enabled' => 'app_error_recurrence_enabled',
                'quiet_hours' => 'app_error_quiet_hours',
                'daily_summary_enabled' => 'app_error_daily_summary_enabled',
                'daily_summary_time' => 'app_error_daily_summary_time',
                'daily_summary_timezone' => 'app_error_daily_summary_timezone',
            ];

            foreach ($columns as $setting => $column) {
                if (array_key_exists($setting, $settings)) {
                    $project->setAttribute($column, $settings[$setting]);
                }
            }

            $project->save();
            $this->notifications->sync($project, $attributes['user_ids']);
        });

        $schedulingWarning = null;
        try {
            app(AppErrorSummaryScheduler::class)->schedule($project->refresh());
        } catch (Throwable $exception) {
            report($exception);
            $schedulingWarning = 'Settings saved, but the daily summary could not be scheduled. Try saving again.';
        }

        return response()->json([
            ...$this->payload($project),
            'scheduling_warning' => $schedulingWarning,
            'message' => 'Error alert settings updated successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'settings' => [
                'recurrence_enabled' => (bool) $project->app_error_recurrence_enabled,
                'quiet_hours' => (int) $project->app_error_quiet_hours,
                'daily_summary_enabled' => (bool) $project->app_error_daily_summary_enabled,
                'daily_summary_time' => $project->app_error_daily_summary_time,
                'daily_summary_timezone' => $project->app_error_daily_summary_timezone,
            ],
            'users' => $this->notifications
                ->eligibleUsers($project)
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'selected_user_ids' => $this->notifications->selectedUserIds($project)->all(),
        ];
    }
}
