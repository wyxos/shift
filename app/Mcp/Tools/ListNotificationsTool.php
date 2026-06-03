<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListNotificationsTool extends Tool
{
    use FormatsShiftRecords;

    protected string $name = 'list_notifications';

    protected string $description = 'List sanitized SHIFT database notification summaries, optionally filtered by internal user email, task ID, notification type, or unread status. Raw notification payload content is not returned.';

    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'notifiable_email' => ['nullable', 'string', 'max:255'],
            'task_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:255'],
            'unread_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        $limit = (int) ($validated['limit'] ?? 20);

        $notifications = DatabaseNotification::query()
            ->with('notifiable')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $principal->user->id)
            ->when($validated['notifiable_email'] ?? null, function ($query, string $email) use ($principal): void {
                $query->whereHasMorph('notifiable', [User::class], fn ($userQuery) => $userQuery
                    ->whereKey($principal->user->id)
                    ->where('email', 'like', "%{$email}%"));
            })
            ->when($validated['task_id'] ?? null, fn ($query, int $taskId) => $query->where('data->task_id', $taskId))
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when((bool) ($validated['unread_only'] ?? false), fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->notification($notification))
            ->values()
            ->all();

        return Response::structured([
            'notifications' => $notifications,
            'count' => count($notifications),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'notifiable_email' => $schema->string()
                ->description('Optional internal user email filter. Partial matches are accepted.'),
            'task_id' => $schema->integer()
                ->description('Optional task ID from the notification data payload.'),
            'type' => $schema->string()
                ->description('Optional fully-qualified notification class name.'),
            'unread_only' => $schema->boolean()
                ->description('Only return unread notifications.')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Maximum number of notifications to return, between 1 and 50.')
                ->default(20),
        ];
    }
}
