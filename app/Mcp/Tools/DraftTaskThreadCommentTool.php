<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld(false)]
class DraftTaskThreadCommentTool extends AddTaskThreadCommentTool
{
    protected string $name = 'draft_task_thread_comment';

    protected string $description = 'Prepare an unpublished All or Team comment on a visible SHIFT task. The draft is private to the authenticated author, sends no notifications, and requires that person to publish it from the SHIFT conversation. Requires comment permission and the mcp:write OAuth scope.';

    protected bool $draft = true;
}
