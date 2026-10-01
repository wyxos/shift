<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CollaboratorSearch
{
    /** @param Collection<int, User> $candidates */
    public function search(Project $project, Collection $candidates, string $term, int $limit = 10): ?Collection
    {
        if (! config('collaborator_search.enabled') || $candidates->isEmpty()) {
            return null;
        }

        $index = config('collaborator_search.index_prefix').'_project_'.$project->id;
        $cacheKey = 'collaborator-search:'.hash('sha256', config('collaborator_search.host').':'.$index);
        if (Cache::has($cacheKey.':unavailable')) {
            return null;
        }

        $documents = $candidates->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])->sortBy('id')->values();
        $fingerprint = hash('sha256', $documents->toJson());

        try {
            if (Cache::get($cacheKey) !== $fingerprint) {
                $lock = Cache::lock($cacheKey.':refresh', 15);
                if (! $lock->get()) {
                    return null;
                }

                try {
                    $this->refreshIndex($index, $documents, $fingerprint);
                    Cache::put($cacheKey, $fingerprint, now()->addDay());
                } finally {
                    $lock->release();
                }
            }

            $response = $this->client()->post('/indexes/'.$index.'/search', [
                'q' => $term,
                'limit' => $limit,
                'attributesToRetrieve' => ['id'],
                'filter' => 'generation = "'.$fingerprint.'"',
                'matchingStrategy' => 'all',
            ])->throw();
            $hits = $response->json('hits');
            if (! is_array($hits)) {
                throw new RuntimeException('Meilisearch returned an invalid collaborator response.');
            }

            $authorizedUsers = $candidates->keyBy('id');

            return collect($hits)
                ->map(fn (mixed $hit): ?User => is_array($hit) ? $authorizedUsers->get($hit['id'] ?? null) : null)
                ->filter()
                ->unique('id')
                ->take($limit)
                ->values();
        } catch (Throwable) {
            Cache::forget($cacheKey);
            Cache::put($cacheKey.':unavailable', true, now()->addSeconds(30));

            return null;
        }
    }

    /** @param Collection<int, array{id: int, name: string, email: string}> $documents */
    private function refreshIndex(string $index, Collection $documents, string $fingerprint): void
    {
        $existing = $this->client()->get('/indexes/'.$index);
        if ($existing->notFound()) {
            $this->awaitTask($this->client()->post('/indexes', ['uid' => $index, 'primaryKey' => 'id'])->throw());
        } else {
            $existing->throw();
        }

        $this->awaitTask($this->client()->patch('/indexes/'.$index.'/settings', [
            'searchableAttributes' => ['name', 'email'],
            'displayedAttributes' => ['id'],
            'filterableAttributes' => ['generation'],
        ])->throw());
        $this->awaitTask($this->client()->delete('/indexes/'.$index.'/documents')->throw());
        $this->awaitTask($this->client()->post('/indexes/'.$index.'/documents', $documents
            ->map(fn (array $document): array => [...$document, 'generation' => $fingerprint])
            ->all())->throw());
    }

    private function awaitTask(Response $response): void
    {
        $taskId = $response->json('taskUid');
        if (! is_int($taskId)) {
            throw new RuntimeException('Meilisearch returned an invalid indexing task.');
        }

        $deadline = microtime(true) + 2;
        do {
            $task = $this->client()->get('/tasks/'.$taskId)->throw();
            $status = $task->json('status');
            if ($status === 'succeeded') {
                return;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException('Meilisearch could not index collaborators.');
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Meilisearch collaborator indexing is still in progress.');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('collaborator_search.host'), '/'))
            ->acceptJson()
            ->asJson()
            ->when(filled(config('collaborator_search.key')), fn (PendingRequest $request): PendingRequest => $request->withToken(config('collaborator_search.key')))
            ->connectTimeout(1)
            ->timeout(2);
    }
}
