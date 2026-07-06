<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddTaskThreadCommentTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\EditTaskThreadCommentTool;
use App\Mcp\Tools\EditTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\GetTaskWriteContextTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTaskThreadsTool;
use App\Mcp\Tools\SearchTasksTool;
use Laravel\Mcp\Server;

class ShiftServer extends Server
{
    protected string $name = 'SHIFT';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MARKDOWN'
        Access to the SHIFT portal, scoped to the authenticated SHIFT user.
        Use read tools to inspect visible projects, tasks, task threads, collaborators,
        writable fields, capabilities, and the user's own notification records. Mutation
        tools require the authenticated token to include mcp:write and should only be
        used after the user approves the specific task or thread change.
    MARKDOWN;

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        ListProjectsTool::class,
        SearchTasksTool::class,
        GetTaskTool::class,
        GetTaskWriteContextTool::class,
        ListTaskThreadsTool::class,
        ListNotificationsTool::class,
        CreateTaskTool::class,
        EditTaskTool::class,
        AddTaskThreadCommentTool::class,
        EditTaskThreadCommentTool::class,
    ];
}
