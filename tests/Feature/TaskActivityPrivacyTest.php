<?php

use App\Models\Task;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('task lifecycle auditing records changed fields without copying private text', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);
    $task = Task::factory()->create([
        'title' => 'PRIVATE-TITLE-ORIGINAL',
        'description' => 'PRIVATE-DESCRIPTION-ORIGINAL',
        'status' => 'pending',
    ]);
    $task->update([
        'title' => 'PRIVATE-TITLE-REVISED',
        'description' => 'PRIVATE-DESCRIPTION-REVISED',
        'status' => 'in-progress',
    ]);
    $task->delete();

    $logs = Activity::query()->where('subject_type', Task::class)->where('subject_id', $task->id)->get();
    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted']);
    foreach (['PRIVATE-TITLE-ORIGINAL', 'PRIVATE-TITLE-REVISED', 'PRIVATE-DESCRIPTION-ORIGINAL', 'PRIVATE-DESCRIPTION-REVISED'] as $secret) {
        expect($logs->toJson())->not->toContain($secret);
    }
    $updated = $logs->firstWhere('event', 'updated');
    expect($updated->properties->get('changed_fields'))->toContain('title', 'description', 'status')
        ->and($updated->properties->get('attributes')['status'])->toBe('in-progress')
        ->and($updated->properties->get('old')['status'])->toBe('pending')
        ->and($updated->causer_id)->toBe($actor->id);
});

test('a text-only task change remains auditable without saving the text', function () {
    $task = Task::factory()->create()->fresh();
    Activity::query()->delete();

    $task->update(['description' => 'PRIVATE-TEXT-ONLY-UPDATE']);

    $log = Activity::query()->sole();
    expect($log->event)->toBe('updated')
        ->and($log->properties->get('changed_fields'))->toBe(['description'])
        ->and($log->toJson())->not->toContain('PRIVATE-TEXT-ONLY-UPDATE');
});
