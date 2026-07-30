<?php

namespace App\Services;

use App\Enums\TaskCollaboratorKind;
use App\Enums\TaskThreadAudience;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\TaskThreadMention;
use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TaskThreadMentionService
{
    public function __construct(
        private readonly ExternalUserService $externalUsers,
        private readonly ShiftPermissionService $permissions,
        private readonly TaskCollaboratorService $collaborators,
    ) {}

    /**
     * @return array{existing: array<int, array<string, mixed>>, addable: array<int, array<string, mixed>>, addable_error: ?string}
     */
    public function candidates(
        Task $task,
        User $actor,
        TaskThreadAudience $audience,
        ?string $search = null,
    ): array {
        $task->loadMissing([
            'project',
            'metadata',
            'internalCollaborators:id,name,email',
            'externalCollaborators:id,external_id,name,email',
        ]);

        $term = trim((string) $search);
        $matches = static function (?string $name, ?string $email) use ($term): bool {
            if ($term === '') {
                return true;
            }

            $haystack = mb_strtolower(trim((string) $name).' '.trim((string) $email));

            return str_contains($haystack, mb_strtolower($term));
        };

        $existing = $task->internalCollaborators
            ->filter(fn (User $user): bool => $matches($user->name, $user->email))
            ->map(fn (User $user): array => $this->internalCandidate($user, true))
            ->values();

        if ($audience === TaskThreadAudience::All) {
            $existing = $existing
                ->concat(
                    $task->externalCollaborators
                        ->filter(fn (ExternalUser $user): bool => $matches($user->name, $user->email))
                        ->map(fn (ExternalUser $user): array => $this->externalCandidate($user, true))
                )
                ->values();
        }

        if (! $this->permissions->canManageTaskCollaborators($task, $actor->id)) {
            return [
                'existing' => $existing->all(),
                'addable' => [],
                'addable_error' => null,
            ];
        }

        $existingInternalIds = $task->internalCollaborators->pluck('id')->map(fn ($id) => (int) $id);
        $addable = $this->collaborators
            ->internalCandidates($task->project, $term)
            ->reject(fn (User $user): bool => $existingInternalIds->contains($user->id))
            ->map(fn (User $user): array => $this->internalCandidate($user, false))
            ->values();

        $addableError = null;

        if ($audience === TaskThreadAudience::All && mb_strlen($term) >= 2) {
            try {
                $externalLookup = $this->externalUsers->searchCollaborators(
                    $task->project,
                    $task->metadata?->environment,
                    $term,
                );
                $existingExternalIds = $task->externalCollaborators
                    ->pluck('external_id')
                    ->map(fn ($id) => (string) $id);

                $addable = $addable
                    ->concat(
                        collect($externalLookup['users'])
                            ->reject(fn (array $candidate): bool => $existingExternalIds->contains((string) $candidate['id']))
                            ->map(fn (array $candidate): array => [
                                'kind' => TaskCollaboratorKind::External->value,
                                'id' => (string) $candidate['id'],
                                'name' => (string) $candidate['name'],
                                'email' => (string) $candidate['email'],
                                'is_collaborator' => false,
                            ])
                    )
                    ->values();
            } catch (RuntimeException $exception) {
                $addableError = $exception->getMessage();
            }
        }

        return [
            'existing' => $existing->all(),
            'addable' => $addable->all(),
            'addable_error' => $addableError,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mentions
     * @param  array<int, array<string, mixed>>  $addCollaborators
     * @return array{user_ids: array<int, int>, external_user_ids: array<int, int>, add_user_ids: array<int, int>, add_external_users: Collection<int, ExternalUser>}
     */
    public function resolve(
        Task $task,
        User $actor,
        TaskThreadAudience $audience,
        array $mentions,
        array $addCollaborators = [],
        bool $allowCollaboratorAdditions = true,
    ): array {
        $task->loadMissing([
            'project',
            'metadata',
            'internalCollaborators:id',
            'externalCollaborators:id,external_id',
        ]);

        $normalizedMentions = $this->normalizeIdentities($mentions);
        $normalizedAdditions = $this->normalizeIdentities($addCollaborators);

        if ($audience === TaskThreadAudience::Team
            && ($normalizedMentions['external']->isNotEmpty() || $normalizedAdditions['external']->isNotEmpty())) {
            throw ValidationException::withMessages([
                'mentions' => 'Team messages can mention only Team collaborators.',
            ]);
        }

        if ($normalizedAdditions['internal']->isNotEmpty() || $normalizedAdditions['external']->isNotEmpty()) {
            if (! $allowCollaboratorAdditions || ! $this->permissions->canManageTaskCollaborators($task, $actor->id)) {
                throw ValidationException::withMessages([
                    'add_collaborators' => 'You do not have permission to add collaborators to this task.',
                ]);
            }
        }

        $additionMentionMismatch = $normalizedAdditions['internal']
            ->diff($normalizedMentions['internal'])
            ->isNotEmpty()
            || $normalizedAdditions['external']->diff($normalizedMentions['external'])->isNotEmpty();

        if ($additionMentionMismatch) {
            throw ValidationException::withMessages([
                'add_collaborators' => 'Every collaborator added with a reply must also be mentioned in that reply.',
            ]);
        }

        $addInternalIds = $this->collaborators->validateInternalCollaboratorIds(
            $task->project,
            $normalizedAdditions['internal']->all(),
        );

        $addExternalUsers = $normalizedAdditions['external']->isEmpty()
            ? collect()
            : $this->externalUsers->resolveCollaborators(
                $task->project,
                $task->metadata?->environment,
                $normalizedAdditions['external']
                    ->map(fn (string $id): array => ['id' => $id])
                    ->all(),
            );

        $availableInternalIds = $task->internalCollaborators
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->concat($addInternalIds)
            ->unique()
            ->values();

        $availableExternalUsers = $task->externalCollaborators
            ->concat($addExternalUsers)
            ->unique('id')
            ->keyBy(fn (ExternalUser $user): string => (string) $user->external_id);

        $missingInternal = $normalizedMentions['internal']->diff($availableInternalIds);
        $missingExternal = $normalizedMentions['external']->reject(
            fn (string $id): bool => $availableExternalUsers->has($id)
        );

        if ($missingInternal->isNotEmpty() || $missingExternal->isNotEmpty()) {
            throw ValidationException::withMessages([
                'mentions' => 'One or more mentioned people are not collaborators on this task.',
            ]);
        }

        return [
            'user_ids' => $normalizedMentions['internal']->map(fn ($id) => (int) $id)->all(),
            'external_user_ids' => $normalizedMentions['external']
                ->map(fn (string $id): int => (int) $availableExternalUsers->get($id)->id)
                ->all(),
            'add_user_ids' => $addInternalIds,
            'add_external_users' => $addExternalUsers,
        ];
    }

    /**
     * @param  array{user_ids: array<int, int>, external_user_ids: array<int, int>, add_user_ids: array<int, int>, add_external_users: Collection<int, ExternalUser>}  $resolved
     */
    public function persist(Task $task, TaskThread $thread, array $resolved): void
    {
        $this->collaborators->add($task, $resolved['add_user_ids'], $resolved['add_external_users']);

        $now = now();
        $records = collect($resolved['user_ids'])
            ->map(fn (int $userId): array => [
                'task_thread_id' => $thread->id,
                'kind' => TaskCollaboratorKind::Internal->value,
                'user_id' => $userId,
                'external_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->concat(
                collect($resolved['external_user_ids'])->map(fn (int $externalUserId): array => [
                    'task_thread_id' => $thread->id,
                    'kind' => TaskCollaboratorKind::External->value,
                    'user_id' => null,
                    'external_user_id' => $externalUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            )
            ->values()
            ->all();

        if ($records !== []) {
            TaskThreadMention::query()->insert($records);
        }
    }

    /**
     * @param  array{user_ids: array<int, int>, external_user_ids: array<int, int>}  $resolved
     */
    public function replace(TaskThread $thread, array $resolved): void
    {
        $thread->mentions()->delete();
        $this->persist($thread->task, $thread, [
            ...$resolved,
            'add_user_ids' => [],
            'add_external_users' => collect(),
        ]);
    }

    /**
     * @return array{user_ids: array<int, int>, external_user_ids: array<int, int>, add_user_ids: array<int, int>, add_external_users: Collection<int, ExternalUser>}
     */
    public function resolvedForThread(TaskThread $thread): array
    {
        $thread->loadMissing('mentions');

        return [
            'user_ids' => $thread->mentions
                ->where('kind', TaskCollaboratorKind::Internal)
                ->pluck('user_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'external_user_ids' => $thread->mentions
                ->where('kind', TaskCollaboratorKind::External)
                ->pluck('external_user_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'add_user_ids' => [],
            'add_external_users' => collect(),
        ];
    }

    /**
     * Canonicalize rendered labels while verifying that markup exactly matches
     * the already-authorized structured mention identities.
     *
     * @param  array{user_ids: array<int, int>, external_user_ids: array<int, int>}  $resolved
     */
    public function normalizeContent(string $content, array $resolved): string
    {
        $labels = User::query()
            ->whereIn('id', $resolved['user_ids'])
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => ['internal:'.(int) $id => $name])
            ->merge(
                ExternalUser::query()
                    ->whereIn('id', $resolved['external_user_ids'])
                    ->get(['id', 'external_id', 'name'])
                    ->mapWithKeys(fn (ExternalUser $user): array => ['external:'.(string) $user->external_id => $user->name])
            );

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="shift-thread-content-root">'.$content.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        $root = $loaded ? $document->documentElement : null;
        $seen = collect();

        if ($root instanceof DOMElement && $root->getAttribute('id') === 'shift-thread-content-root') {
            foreach ($root->getElementsByTagName('span') as $element) {
                if (! $element instanceof DOMElement || $element->getAttribute('data-shift-mention') !== 'true') {
                    continue;
                }

                $kind = $element->getAttribute('data-mention-kind');
                $identity = trim($element->getAttribute('data-mention-id'));
                $key = $kind === TaskCollaboratorKind::Internal->value
                    ? 'internal:'.(int) $identity
                    : 'external:'.$identity;
                $label = $labels->get($key);

                if (! is_string($label) || $label === '') {
                    $this->throwMentionMarkupValidationException();
                }

                while ($element->firstChild !== null) {
                    $element->removeChild($element->firstChild);
                }

                $element->appendChild($document->createTextNode('@'.$label));
                $seen->push($key);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $root instanceof DOMElement
            || $seen->unique()->sort()->values()->all() !== $labels->keys()->sort()->values()->all()) {
            $this->throwMentionMarkupValidationException();
        }

        $normalized = '';
        foreach ($root->childNodes as $child) {
            $normalized .= $document->saveHTML($child);
        }

        return $normalized;
    }

    /**
     * @return array<int, array{kind: string, id: int|string, name: string}>
     */
    public function serialize(TaskThread $thread): array
    {
        $thread->loadMissing(['mentions.user:id,name', 'mentions.externalUser:id,external_id,name']);

        return $thread->mentions
            ->map(function (TaskThreadMention $mention): ?array {
                if ($mention->kind === TaskCollaboratorKind::Internal && $mention->user instanceof User) {
                    return [
                        'kind' => TaskCollaboratorKind::Internal->value,
                        'id' => $mention->user->id,
                        'name' => $mention->user->name,
                    ];
                }

                if ($mention->kind === TaskCollaboratorKind::External && $mention->externalUser instanceof ExternalUser) {
                    return [
                        'kind' => TaskCollaboratorKind::External->value,
                        'id' => $mention->externalUser->external_id,
                        'name' => $mention->externalUser->name,
                    ];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $identities
     * @return array{internal: Collection<int, int>, external: Collection<int, string>}
     */
    private function normalizeIdentities(array $identities): array
    {
        $items = collect($identities)->filter(fn ($identity): bool => is_array($identity));

        return [
            'internal' => $items
                ->where('kind', TaskCollaboratorKind::Internal->value)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values(),
            'external' => $items
                ->where('kind', TaskCollaboratorKind::External->value)
                ->pluck('id')
                ->map(fn ($id) => trim((string) $id))
                ->filter()
                ->unique()
                ->values(),
        ];
    }

    private function throwMentionMarkupValidationException(): never
    {
        throw ValidationException::withMessages([
            'mentions' => 'Rendered mentions must match the selected task collaborators.',
        ]);
    }

    /**
     * @return array{kind: string, id: int, name: string, email: ?string, is_collaborator: bool}
     */
    private function internalCandidate(User $user, bool $isCollaborator): array
    {
        return [
            'kind' => TaskCollaboratorKind::Internal->value,
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_collaborator' => $isCollaborator,
        ];
    }

    /**
     * @return array{kind: string, id: string, name: string, email: ?string, is_collaborator: bool}
     */
    private function externalCandidate(ExternalUser $user, bool $isCollaborator): array
    {
        return [
            'kind' => TaskCollaboratorKind::External->value,
            'id' => (string) $user->external_id,
            'name' => $user->name,
            'email' => $user->email,
            'is_collaborator' => $isCollaborator,
        ];
    }
}
