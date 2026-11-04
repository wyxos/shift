<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Models\Attachment;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\ShiftPermissionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use RuntimeException;
use Throwable;

#[IsIdempotent(true)]
#[IsOpenWorld(false)]
class AttachFileToTaskThreadDraftTool extends Tool
{
    public const MAX_BYTES = 512 * 1024;

    protected string $name = 'attach_file_to_task_thread_draft';

    protected string $description = 'Attach an explicitly authorized file to your own private, unpublished SHIFT reply draft. Pass the file bytes as standard base64. The limit is 512 KiB per file. This does not publish the draft or send notifications. Requires comment permission and the mcp:write OAuth scope.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal || ! $access->canWrite($principal)) {
            return Response::error('This SHIFT MCP tool requires the mcp:write OAuth scope.');
        }

        $maxBase64Length = 4 * intdiv(self::MAX_BYTES + 2, 3);
        $input = $request->validate([
            'thread_id' => ['required', 'integer', 'min:1'],
            'filename' => ['required', 'string', 'max:255'],
            'content_base64' => ['required', 'string', 'max:'.$maxBase64Length],
        ]);

        $filename = $input['filename'];
        if (strlen($filename) > 255
            || in_array($filename, ['.', '..'], true)
            || str_contains($filename, '/')
            || str_contains($filename, chr(92))
            || preg_match('/[[:cntrl:]]/u', $filename) !== 0) {
            return Response::error('Provide a filename without a path or control characters.');
        }

        $encoded = $input['content_base64'];
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES || base64_encode($bytes) !== $encoded) {
            return Response::error('Provide valid standard base64 for a nonempty file of at most 512 KiB.');
        }

        $visibleTasks = $access->tasksFor($principal)->select('tasks.id');
        $writtenPath = null;

        try {
            $attachment = DB::transaction(function () use ($input, $principal, $visibleTasks, $filename, $bytes, &$writtenPath): ?Attachment {
                $draft = TaskThread::query()
                    ->withDraftsFor($principal->user)
                    ->where('is_draft', true)
                    ->where('sender_type', User::class)
                    ->where('sender_id', $principal->user->id)
                    ->whereIn('task_id', $visibleTasks)
                    ->lockForUpdate()
                    ->find($input['thread_id']);

                if (! $draft || ! app(ShiftPermissionService::class)->canCommentOnTask($draft->task, $principal->user->id)) {
                    return null;
                }

                $digest = hash('sha256', $filename."\0".$bytes);
                $prefix = "attachments/task_threads/{$draft->id}/mcp-{$digest}-";
                $existing = $draft->attachments()->where('path', 'like', $prefix.'%')->first();
                if ($existing && Storage::exists($existing->path)) {
                    return $existing;
                }

                $writtenPath = $existing?->path ?? $prefix.Str::uuid();
                if (! Storage::put($writtenPath, $bytes)) {
                    throw new RuntimeException('The draft attachment could not be stored.');
                }

                return $existing ?? $draft->attachments()->create([
                    'original_filename' => $filename,
                    'path' => $writtenPath,
                ]);
            });
        } catch (Throwable $exception) {
            if ($writtenPath !== null) {
                Storage::delete($writtenPath);
            }

            throw $exception;
        }

        if (! $attachment) {
            return Response::error('An editable draft owned by the authenticated user was not found.');
        }

        return Response::structured([
            'thread_id' => (int) $attachment->attachable_id,
            'is_draft' => true,
            'attachment' => [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()
                ->description('The private draft thread ID returned by draft_task_thread_comment.')
                ->required(),
            'filename' => $schema->string()
                ->description('The original filename, including its extension, without a directory path.')
                ->required(),
            'content_base64' => $schema->string()
                ->description('Standard padded base64 of the file bytes. Maximum decoded size is 512 KiB.')
                ->required(),
        ];
    }
}
