<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetTaskTool;
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
        Read-only access to the SHIFT portal, scoped to the authenticated SHIFT user.
        Use these tools to inspect visible
        projects, tasks, task threads, collaborators, and the user's own notification
        records. Do not use this server for mutations; it intentionally exposes no
        write tools.
    MARKDOWN;

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        ListProjectsTool::class,
        SearchTasksTool::class,
        GetTaskTool::class,
        ListTaskThreadsTool::class,
        ListNotificationsTool::class,
    ];
}
