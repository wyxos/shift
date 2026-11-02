<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld(false)]
class DraftTaskThreadCommentTool extends PublishTaskThreadCommentTool
{
    protected string $name = 'draft_task_thread_comment';

    protected string $description = 'Save a private, unpublished All or Team reply draft. Use this tool by default for ticket updates, including updates after implementation or release. It sends no notifications; the author reviews and publishes it from the SHIFT conversation. Requires comment permission and the mcp:write OAuth scope.';

    protected bool $draft = true;
}
