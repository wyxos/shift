<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('task updates are written to activity log', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $task->submitter()->associate($user)->save();

    // Ignore setup events so we assert only the actual business update.
    Activity::query()->delete();

    $this->actingAs($user);
    $task->update([
        'status' => 'in-progress',
        'priority' => 'high',
    ]);

    $activity = Activity::query()
        ->where('subject_type', Task::class)
        ->where('subject_id', $task->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    if (! $activity) {
        return;
    }

    expect($activity->log_name)->toBe('task');
    expect($activity->description)->toBe('updated');
    expect(data_get($activity->properties, 'old.status'))->toBe('pending');
    expect(data_get($activity->properties, 'old.priority'))->toBe('medium');
    expect(data_get($activity->properties, 'attributes.status'))->toBe('in-progress');
    expect(data_get($activity->properties, 'attributes.priority'))->toBe('high');
});
