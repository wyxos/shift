<?php

use App\Mcp\Servers\ShiftServer;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTaskErrorOccurrencesTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->operator = User::factory()->create();
    $this->project = Project::factory()->withAuthor($this->operator->id)->create(['mcp_enabled' => true]);
    $this->errorTask = Task::factory()->for($this->project)->create([
        'error_signature' => 'diagnostic-signature',
        'error_source' => 'laravel',
        'error_environment' => 'production',
        'error_release' => 'v2.1.0',
        'error_git_sha' => 'abcdef123456',
        'error_exception_class' => 'RuntimeException',
        'error_culprit_file' => 'app/Services/Heating.php',
        'error_culprit_line' => 42,
        'error_occurrences_count' => 3,
        'error_first_seen_at' => '2026-10-20 10:00:00',
        'error_last_seen_at' => '2026-10-22 10:00:00',
    ]);
});

function mcpErrorEvent(Task $task, int $number, array $attributes = []): \App\Models\TaskErrorOccurrence
{
    return $task->errorOccurrences()->create([
        'number' => $number,
        'source' => 'laravel',
        'environment' => 'production',
        'release' => 'v2.1.0',
        'git_sha' => 'abcdef123456',
        'message' => "Failure event {$number}",
        'received_at' => '2026-10-22 10:00:00',
        'occurred_at' => '2026-10-22 09:59:59',
        'payload' => [],
        ...$attributes,
    ]);
}

test('mcp task details expose error identity and point to event retrieval', function () {
    ShiftServer::actingAs($this->operator)->tool(GetTaskTool::class, ['task_id' => $this->errorTask->id])
        ->assertOk()
        ->assertSee(['error_report', 'diagnostic-signature', 'production', 'v2.1.0', 'abcdef123456', 'RuntimeException', 'Heating.php', 'occurrences_count', 'first_seen_at', 'last_seen_at', 'list_task_error_occurrences']);
});

test('mcp can find only error reports while preserving normal task searches', function () {
    $normal = Task::factory()->for($this->project)->create(['title' => 'Ordinary request']);

    ShiftServer::actingAs($this->operator)->tool(SearchTasksTool::class, ['error_only' => true])
        ->assertOk()->assertSee('diagnostic-signature')->assertDontSee('Ordinary request');
    ShiftServer::actingAs($this->operator)->tool(GetTaskTool::class, ['task_id' => $normal->id])
        ->assertOk()->assertSee('"error_report":null');
    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $normal->id])
        ->assertOk()->assertSee(['"occurrences":[]', '"total":0']);
});

test('mcp error events include sufficient diagnostic context and scrub stored secrets', function () {
    mcpErrorEvent($this->errorTask, 1, [
        'message' => 'Failed with password=synthetic-message-secret',
        'exception_class' => 'RuntimeException',
        'culprit_file' => 'app/Services/Heating.php',
        'culprit_line' => 42,
        'request_method' => 'POST',
        'request_url' => 'https://app.example.test/heating?token=synthetic-url-secret',
        'request_path' => '/heating',
        'request_referrer' => 'https://app.example.test?secret=synthetic-referrer-secret',
        'stacktrace' => ['frames' => [['file' => 'app/Services/Heating.php', 'line' => 42, 'function' => 'install']]],
        'context' => ['request' => ['body' => ['boiler_id' => 31, 'token' => 'synthetic-body-secret'], 'headers' => ['Proxy-Authorization' => 'Basic synthetic-proxy-secret', 'Set-Cookie' => 'synthetic-cookie-secret']]],
        'user' => ['id' => 'diagnostic-user'],
        'metadata' => ['trace_id' => 'diagnostic-trace'],
        'payload' => ['exception' => ['message' => 'diagnostic-message'], 'context' => ['api_key' => 'synthetic-key-secret']],
    ]);

    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id])
        ->assertOk()
        ->assertSee(['RuntimeException', 'Heating.php', 'install', 'boiler_id', 'diagnostic-user', 'diagnostic-trace', 'diagnostic-message', 'abcdef123456', 'occurred_at', 'received_at', '[Filtered]'])
        ->assertDontSee(['synthetic-message-secret', 'synthetic-url-secret', 'synthetic-referrer-secret', 'synthetic-body-secret', 'synthetic-proxy-secret', 'synthetic-cookie-secret', 'synthetic-key-secret']);
});

test('mcp error events paginate in newest received order with a stable tie break', function () {
    mcpErrorEvent($this->errorTask, 1, ['received_at' => '2026-10-22 11:00:00']);
    mcpErrorEvent($this->errorTask, 2);
    mcpErrorEvent($this->errorTask, 3);

    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id, 'per_page' => 1])
        ->assertOk()->assertSee(['Failure event 1', '"total":3', '"has_more":true', '"next_page":2'])->assertDontSee(['Failure event 2', 'Failure event 3']);
    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id, 'per_page' => 1, 'page' => 2])
        ->assertOk()->assertSee('Failure event 3')->assertDontSee(['Failure event 1', 'Failure event 2']);
    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id, 'per_page' => 1, 'page' => 3])
        ->assertOk()->assertSee(['Failure event 2', '"has_more":false', '"next_page":null']);
});

test('mcp event retrieval fails closed for anonymous hidden and disabled project access', function () {
    mcpErrorEvent($this->errorTask, 1, ['message' => 'Restricted diagnostic']);
    ShiftServer::tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id])
        ->assertHasErrors(['authenticated user'])->assertDontSee('Restricted diagnostic');
    ShiftServer::actingAs(User::factory()->create())->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id])
        ->assertHasErrors(['not found or is not visible'])->assertDontSee('Restricted diagnostic');
    $this->project->update(['mcp_enabled' => false]);
    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id])
        ->assertHasErrors(['not found or is not visible'])->assertDontSee('Restricted diagnostic');
});

test('mcp error events preserve task only collaborator access', function () {
    $collaborator = User::factory()->create();
    $this->errorTask->internalCollaborators()->attach($collaborator->id);
    mcpErrorEvent($this->errorTask, 1);
    ShiftServer::actingAs($collaborator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id])
        ->assertOk()->assertSee('Failure event 1');
});

test('mcp error event pagination validates bounds', function (array $arguments) {
    ShiftServer::actingAs($this->operator)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $this->errorTask->id, ...$arguments])
        ->assertHasErrors();
})->with([[['per_page' => 0]], [['per_page' => 21]], [['page' => 0]]]);
