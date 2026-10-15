<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class ExternalCollaboratorService
{
    public function __construct(
        private readonly ExternalIdentityService $identities,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
    ) {}

    public function searchCollaborators(
        Project $project,
        ?string $environment,
        ?string $search = null,
        bool $paginate = false,
        int $page = 1,
        int $perPage = 15,
    ): array {
        $registration = $this->environmentRegistration($project, $environment);

        if ($registration === null) {
            throw new RuntimeException('External collaborators are unavailable because this environment is not registered for the selected project.');
        }

        if ($registration->callback_trusted_at === null) {
            throw new RuntimeException('External collaborators are unavailable because the registered callback destination has not been trusted.');
        }

        if (! filled($project->token)) {
            throw new RuntimeException('External collaborators are unavailable because this project does not have a callback token.');
        }

        try {
            $destination = $this->outboundUrlPolicy->approveRequest($registration->url);
            $requestOptions = $this->outboundUrlPolicy->requestOptions($destination);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                'External collaborators are unavailable because the registered callback destination is not trusted.',
                previous: $exception,
            );
        }

        $query = [];
        $term = trim((string) $search);
        if ($term !== '') {
            $query['search'] = $term;
        }

        if ($paginate) {
            $query['paginate'] = 1;
            $query['page'] = $page;
            $query['per_page'] = $perPage;
        }

        try {
            $callbackUrl = $destination['url'].'/shift/api/collaborators/external';
            $response = Http::withOptions($requestOptions)
                ->withToken($project->token)
                ->acceptJson()
                ->connectTimeout((int) config('shift.notifications.callback_connect_timeout_seconds', 5))
                ->timeout(10)
                ->get($callbackUrl, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('External collaborators are unavailable because the client app could not be reached.', previous: $exception);
        }

        if ($this->outboundUrlPolicy->responseWasRedirected($response, $callbackUrl)) {
            throw new RuntimeException('External collaborators are unavailable because the callback destination attempted a redirect.');
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? 'External collaborators are unavailable.';

            throw new RuntimeException((string) $message);
        }

        $payload = $response->json();
        $returnedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($payload['environment'] ?? null);
        $returnedUrl = $this->projectEnvironmentService->normalizeBaseUrl($payload['url'] ?? null);

        if ($returnedEnvironment === null || $returnedUrl === null) {
            throw new RuntimeException('External collaborators are unavailable because the client app returned an invalid identity.');
        }

        if ($returnedEnvironment !== $registration->environment || $returnedUrl !== $registration->url) {
            throw new RuntimeException('External collaborators are unavailable because the client app identity does not match the registered environment.');
        }

        $users = collect($payload['users'] ?? [])
            ->filter(fn ($user) => is_array($user))
            ->map(function (array $user) {
                return [
                    'id' => $this->identities->normalizeExternalId($user['id'] ?? null),
                    'name' => $this->projectEnvironmentService->normalizeString($user['name'] ?? null),
                    'email' => $this->projectEnvironmentService->normalizeString($user['email'] ?? null),
                ];
            })
            ->filter(fn (array $user) => $user['id'] !== null && $user['name'] !== null && $user['email'] !== null)
            ->values()
            ->all();

        $result = [
            'environment' => $registration->environment,
            'url' => $registration->url,
            'users' => $users,
        ];

        if (! $paginate) {
            return $result;
        }

        $pagination = $this->normalizeCollaboratorPagination($payload['pagination'] ?? null);

        if ($pagination === null) {
            $total = count($users);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $result['users'] = array_slice($users, ($page - 1) * $perPage, $perPage);
            $pagination = [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($page * $perPage, $total),
            ];
        }

        $result['pagination'] = $pagination;

        return $result;
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}|null
     */
    private function normalizeCollaboratorPagination(mixed $pagination): ?array
    {
        if (! is_array($pagination)) {
            return null;
        }

        $currentPage = filter_var($pagination['current_page'] ?? null, FILTER_VALIDATE_INT);
        $lastPage = filter_var($pagination['last_page'] ?? null, FILTER_VALIDATE_INT);
        $perPage = filter_var($pagination['per_page'] ?? null, FILTER_VALIDATE_INT);
        $total = filter_var($pagination['total'] ?? null, FILTER_VALIDATE_INT);

        if ($currentPage === false || $lastPage === false || $perPage === false || $total === false) {
            return null;
        }

        if ($currentPage < 1 || $lastPage < 1 || $perPage < 1 || $total < 0) {
            return null;
        }

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => is_numeric($pagination['from'] ?? null) ? (int) $pagination['from'] : null,
            'to' => is_numeric($pagination['to'] ?? null) ? (int) $pagination['to'] : null,
        ];
    }

    /**
     * @return array{users: array, available: bool, error: string|null}
     */
    public function searchProjectCollaborators(Project $project, ?string $environment, string $search): array
    {
        $environments = $project->environments()->orderBy('environment')->get();
        if (filled($environment)) {
            $environments = $environments->where('environment', $this->projectEnvironmentService->normalizeEnvironment($environment));
        }

        $users = [];
        $errors = [];
        $available = false;
        foreach ($environments as $registration) {
            try {
                $lookup = $this->searchCollaborators($project, $registration->environment, $search, paginate: true, perPage: 10);
                foreach (array_slice($lookup['users'], 0, 10) as $user) {
                    $users[] = [...$user, 'environment' => $registration->environment];
                }
                $available = true;
            } catch (RuntimeException $exception) {
                $errors[] = $this->projectEnvironmentService->label($registration->environment).': '.$exception->getMessage();
            }
        }

        return [
            'users' => $users,
            'available' => $available,
            'error' => $environments->isEmpty()
                ? (filled($environment) ? 'The selected environment is not registered for this project.' : 'No environments are registered for this project.')
                : ($errors === [] ? null : implode(' ', $errors)),
        ];
    }

    public function resolveCollaborators(Project $project, ?string $environment, array $collaborators, iterable $existing = []): Collection
    {
        $existing = collect($existing)->filter(fn (ExternalIdentity $user) => $user->project_id === $project->id);
        $groups = collect($collaborators)
            ->filter(fn ($item) => is_array($item))
            ->groupBy(function (array $item) use ($existing, $environment): string {
                $source = $this->projectEnvironmentService->normalizeEnvironment($item['environment'] ?? null);
                if ($source !== null) {
                    return $source;
                }

                $matches = $existing->filter(fn (ExternalIdentity $user): bool => (string) $user->external_id === $this->identities->normalizeExternalId($item['id'] ?? null));
                if ($matches->count() > 1) {
                    throw ValidationException::withMessages([
                        'external_collaborators' => 'This selection matches multiple source accounts. Refresh and select each collaborator with its source environment.',
                    ]);
                }

                return $matches->first()?->environment
                    ?? $this->projectEnvironmentService->normalizeEnvironment($environment)
                    ?? '';
            });
        $resolved = collect();

        foreach ($groups as $source => $selection) {
            $ids = $selection->map(fn (array $item) => $this->identities->normalizeExternalId($item['id'] ?? null))->filter()->unique()->values();
            $retained = $existing->filter(fn (ExternalIdentity $user) => $user->environment === $source && $ids->contains((string) $user->external_id));
            $resolved = $resolved->merge($retained);
            $newIds = $ids->diff($retained->pluck('external_id')->map(fn ($id) => (string) $id));
            if ($newIds->isEmpty()) {
                continue;
            }
            if ($source === '') {
                throw ValidationException::withMessages([
                    'external_collaborators' => 'Select a collaborator with a verified source environment.',
                ]);
            }

            $lookup = $this->searchCollaborators($project, (string) $source);
            $available = collect($lookup['users'])->keyBy(fn (array $user) => $user['id']);
            if ($newIds->contains(fn (string $id) => ! $available->has($id))) {
                throw ValidationException::withMessages([
                    'external_collaborators' => 'One or more external collaborators are no longer available for this project.',
                ]);
            }
            foreach ($newIds as $id) {
                $candidate = $available->get($id);
                $resolved->push($this->identities->upsert($project, [
                    'external_id' => $candidate['id'],
                    'name' => $candidate['name'],
                    'email' => $candidate['email'],
                    'environment' => $lookup['environment'],
                    'url' => $lookup['url'],
                ]));
            }
        }

        return $resolved->unique('id')->values();
    }

    public function environmentRegistration(Project $project, ?string $environment): ?ProjectEnvironment
    {
        return $this->projectEnvironmentService->find($project, $environment);
    }
}
