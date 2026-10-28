<?php

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disks.local.root' => sys_get_temp_dir().'/shift-deletion-'.bin2hex(random_bytes(8))]);
    Storage::forgetDisk('local');
    $this->deletionDisk = Storage::disk('local');
});

afterEach(function () {
    $this->deletionDisk->deleteDirectory('attachments');
});

function attachmentDeletionFixture(Task|TaskThread $owner, string $name): Attachment
{
    $path = 'attachments/'.$name;
    Storage::put($path, 'Synthetic confidential document');

    return $owner->attachments()->create(['path' => $path, 'original_filename' => $name]);
}

function attachmentDeletionThread(Task $task, User $user, bool $draft): TaskThread
{
    return $task->threads()->create([
        'type' => 'internal', 'content' => 'Synthetic comment', 'sender_name' => 'Example User',
        'sender_type' => User::class, 'sender_id' => $user->id, 'is_draft' => $draft,
    ]);
}

test('task deletion removes direct, published comment and private draft attachments only for that task', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $published = attachmentDeletionThread($task, $user, false);
    $draft = attachmentDeletionThread($task, $user, true);
    $attachments = collect([
        attachmentDeletionFixture($task, 'task.pdf'),
        attachmentDeletionFixture($published, 'comment.pdf'),
        attachmentDeletionFixture($draft, 'draft.pdf'),
    ]);
    $unrelated = attachmentDeletionFixture(Task::factory()->create(), 'other-task.pdf');

    $task->delete();

    $attachments->each(function (Attachment $attachment) {
        Storage::assertMissing($attachment->path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    });
    $this->assertDatabaseMissing('task_threads', ['id' => $published->id]);
    $this->assertDatabaseMissing('task_threads', ['id' => $draft->id]);
    Storage::assertExists($unrelated->path);
    $this->assertDatabaseHas('attachments', ['id' => $unrelated->id]);
});

test('deleting a comment or draft directly removes its attachments', function (bool $draft) {
    $user = User::factory()->create();
    $thread = attachmentDeletionThread(Task::factory()->create(), $user, $draft);
    $attachment = attachmentDeletionFixture($thread, 'thread.pdf');

    $thread->delete();

    Storage::assertMissing($attachment->path);
    $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
})->with([false, true]);

test('deleting an attachment model removes its file and tolerates an already absent file', function () {
    $task = Task::factory()->create();
    $present = attachmentDeletionFixture($task, 'present.pdf');
    $absent = attachmentDeletionFixture($task, 'absent.pdf');
    Storage::delete($absent->path);

    $present->delete();
    $absent->delete();

    Storage::assertMissing($present->path);
    expect($task->attachments()->count())->toBe(0);
});

test('a storage deletion failure does not report a deleted task or attachment', function () {
    $task = Task::factory()->create();
    $attachment = attachmentDeletionFixture($task, 'failure.pdf');
    Storage::shouldReceive('exists')->with($attachment->path)->once()->andReturn(true);
    Storage::shouldReceive('delete')->with($attachment->path)->once()->andReturn(false);

    expect(fn () => $task->delete())->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    $this->deletionDisk->assertExists($attachment->path);
});

test('task deletion fetches comment attachments in a single batch', function (int $comments) {
    $task = Task::factory()->create();
    $user = User::factory()->create();
    foreach (range(1, $comments) as $index) {
        $thread = attachmentDeletionThread($task, $user, $index % 2 === 0);
        attachmentDeletionFixture($thread, 'comment-'.$index.'.pdf');
    }
    $attachmentReads = 0;
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$attachmentReads) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"attachments"')) {
            $attachmentReads++;
        }
    });

    $task->delete();

    expect($attachmentReads)->toBe(1);
})->with([1, 8]);

test('parent deletion also removes task, comment and draft files before database cascades', function (string $parent) {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    $client = Client::factory()->create(['organisation_id' => $organisation->id]);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $attachments = collect([
        attachmentDeletionFixture($task, 'parent-task.pdf'),
        attachmentDeletionFixture(attachmentDeletionThread($task, $user, false), 'parent-comment.pdf'),
        attachmentDeletionFixture(attachmentDeletionThread($task, $user, true), 'parent-draft.pdf'),
    ]);
    $unrelated = attachmentDeletionFixture(Task::factory()->create(), 'unrelated-parent.pdf');

    (match ($parent) {
        'project' => $project,
        'client' => $client,
        'organisation' => $organisation,
        'user' => $user,
    })->delete();

    $attachments->each(function (Attachment $attachment) {
        Storage::assertMissing($attachment->path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    });
    Storage::assertExists($unrelated->path);
    $this->assertDatabaseHas('attachments', ['id' => $unrelated->id]);
})->with(['project', 'client', 'organisation', 'user']);
