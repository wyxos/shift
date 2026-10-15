<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExternalInboxRequest;
use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Services\ExternalActorResolver;
use App\Services\ExternalInboxService;
use Illuminate\Http\JsonResponse;

class ExternalInboxController extends Controller
{
    public function __construct(
        private readonly ExternalActorResolver $actors,
        private readonly ExternalInboxService $inbox,
    ) {}

    public function index(ExternalInboxRequest $request): JsonResponse
    {
        return response()->json($this->inbox->page(
            $this->identity($request),
            $request->integer('page', 1),
            $request->integer('per_page', 20),
        ));
    }

    public function read(ExternalInboxRequest $request, int $notification): JsonResponse
    {
        $identity = $this->identity($request);
        abort_if($identity === null, 404);
        $this->inbox->read($identity, $notification);

        return response()->json(['unread_count' => $this->inbox->unreadCount($identity)]);
    }

    public function readAll(ExternalInboxRequest $request): JsonResponse
    {
        $identity = $this->identity($request);
        abort_if($identity === null, 404);
        $this->inbox->readAll($identity);

        return response()->json(['unread_count' => $this->inbox->unreadCount($identity)]);
    }

    private function identity(ExternalInboxRequest $request): ?ExternalIdentity
    {
        $project = Project::query()->visibleTo($request->user()?->id)
            ->where('token', $request->input('project'))->firstOrFail();

        return $this->actors->fromRequest($project, $request, false)?->identity;
    }
}
