<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectAppErrorNotificationUsersRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAppErrorNotificationService;
use App\Services\ShiftPermissionService;
use Illuminate\Http\JsonResponse;

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

        $this->notifications->sync($project, $attributes['user_ids']);

        return response()->json([
            ...$this->payload($project),
            'message' => 'App error notification recipients updated successfully.',
        ]);
    }

    /**
     * @return array{project_id: int, users: array<int, array{id: int, name: string, email: string}>, selected_user_ids: array<int, int>}
     */
    private function payload(Project $project): array
    {
        return [
            'project_id' => $project->id,
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
