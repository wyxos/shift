<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class SdkInstallSessionService
{
    private const DEVICE_CODE_LENGTH = 80;

    private const USER_CODE_LENGTH = 8;

    private const USER_CODE_GROUP_LENGTH = 4;

    private const USER_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    public function create(?string $environment, ?string $url): array
    {
        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($environment);
        $normalizedUrl = $this->projectEnvironmentService->normalizeUrl($url);

        if ($normalizedEnvironment === null || $normalizedUrl === null) {
            throw ValidationException::withMessages([
                'environment' => 'Install sessions require both an environment name and URL.',
            ]);
        }

        $expiresAt = now()->addSeconds($this->sessionLifetimeSeconds());

        $session = [
            'device_code' => $this->generateUniqueDeviceCode(),
            'user_code' => $this->generateUniqueUserCode(),
            'environment' => $normalizedEnvironment,
            'url' => $normalizedUrl,
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'approved_user_id' => null,
            'approved_at' => null,
            'credentials_issued_at' => null,
            'project_id' => null,
        ];

        $this->persist($session);

        return $session;
    }

    public function pollIntervalSeconds(): int
    {
        return 5;
    }

    public function poll(string $deviceCode): array
    {
        $session = $this->findByDeviceCode($deviceCode);

        if ($session === null) {
            return [
                'state' => 'expired',
                'interval' => $this->pollIntervalSeconds(),
                'expires_at' => null,
            ];
        }

        return [
            'state' => $this->state($session),
            'interval' => $this->pollIntervalSeconds(),
            'expires_at' => $session['expires_at'],
        ];
    }

    public function detailsForUserCode(string $userCode, ?int $currentUserId = null): ?array
    {
        $session = $this->findByUserCode($userCode);

        if ($session === null) {
            return null;
        }

        return [
            'user_code' => $session['user_code'],
            'state' => $this->state($session),
            'environment' => $session['environment'],
            'environment_label' => $this->projectEnvironmentService->label($session['environment']),
            'url' => $session['url'],
            'expires_at' => $session['expires_at'],
            'approved' => $session['approved_user_id'] !== null ? [
                'at' => $session['approved_at'],
                'by_current_user' => (int) $session['approved_user_id'] === $currentUserId,
            ] : null,
        ];
    }

    public function approve(string $userCode, User $user): array
    {
        $normalizedUserCode = $this->normalizeUserCode($userCode);

        if ($normalizedUserCode === null) {
            throw new GoneHttpException('This install code is invalid or has expired.');
        }

        $deviceCode = Cache::get($this->userCodeKey($normalizedUserCode));

        if (! is_string($deviceCode) || $deviceCode === '') {
            throw new GoneHttpException('This install code is invalid or has expired.');
        }

        return $this->withLock($deviceCode, function () use ($deviceCode, $user) {
            $session = $this->findByDeviceCode($deviceCode);

            if ($session === null) {
                throw new GoneHttpException('This install code is invalid or has expired.');
            }

            if ($session['approved_user_id'] !== null) {
                if ((int) $session['approved_user_id'] !== $user->id) {
                    throw new ConflictHttpException('This install request has already been approved by another user.');
                }

                return $session;
            }

            $session['approved_user_id'] = $user->id;
            $session['approved_at'] = now()->toIso8601String();

            $this->persist($session);

            return $session;
        });
    }

    public function projects(string $deviceCode): Collection
    {
        $session = $this->requireApprovedSession($deviceCode);

        return $this->manageableProjectsQuery((int) $session['approved_user_id'])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client?->name,
                'organisation_name' => $project->organisation?->name ?? $project->client?->organisation?->name,
                'has_project_token' => filled($project->token),
            ]);
    }

    public function finalize(string $deviceCode, int $projectId): array
    {
        return $this->withLock($deviceCode, function () use ($deviceCode, $projectId) {
            $session = $this->requireApprovedSession($deviceCode);

            if ($session['credentials_issued_at'] !== null) {
                throw new ConflictHttpException('Credentials have already been issued for this install session.');
            }

            $project = $this->manageableProjectsQuery((int) $session['approved_user_id'])
                ->whereKey($projectId)
                ->first();

            if (! $project instanceof Project) {
                throw new AccessDeniedHttpException('You do not have permission to install against the selected project.');
            }

            $credentials = DB::transaction(function () use ($project, $session) {
                $lockedProject = Project::query()
                    ->with(['client.organisation', 'organisation'])
                    ->lockForUpdate()
                    ->findOrFail($project->id);

                if (! $lockedProject->isManagedByUser((int) $session['approved_user_id'])) {
                    throw new AccessDeniedHttpException('You do not have permission to install against the selected project.');
                }

                $projectToken = filled($lockedProject->token)
                    ? $lockedProject->token
                    : $lockedProject->generateApiToken();

                $this->projectEnvironmentService->register(
                    $lockedProject,
                    $session['environment'],
                    $session['url'],
                );

                $user = User::query()->findOrFail((int) $session['approved_user_id']);
                $userToken = $user->createToken($this->installerTokenName($lockedProject))->plainTextToken;

                return [
                    'project_id' => $lockedProject->id,
                    'project_name' => $lockedProject->name,
                    'project_token' => $projectToken,
                    'user_token' => $userToken,
                    'environment' => $session['environment'],
                    'url' => $session['url'],
                ];
            });

            $session['credentials_issued_at'] = now()->toIso8601String();
            $session['project_id'] = $project->id;

            $this->persist($session);

            return $credentials;
        });
    }

    public function normalizeUserCode(?string $userCode): ?string
    {
        if ($userCode === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($userCode));

        if ($normalized === null || strlen($normalized) !== self::USER_CODE_LENGTH) {
            return null;
        }

        return substr($normalized, 0, self::USER_CODE_GROUP_LENGTH)
            .'-'.
            substr($normalized, self::USER_CODE_GROUP_LENGTH);
    }

    private function manageableProjectsQuery(int $userId): Builder
    {
        return Project::query()
            ->with(['client:id,name,organisation_id', 'client.organisation:id,name,author_id', 'organisation:id,name,author_id'])
            ->where(function (Builder $query) use ($userId) {
                $query
                    ->whereHas('client.organisation', fn (Builder $subQuery) => $subQuery->where('author_id', $userId))
                    ->orWhereHas('organisation', fn (Builder $subQuery) => $subQuery->where('author_id', $userId))
                    ->orWhere('author_id', $userId);
            });
    }

    private function requireApprovedSession(string $deviceCode): array
    {
        $session = $this->findByDeviceCode($deviceCode);

        if ($session === null) {
            throw new GoneHttpException('Install session has expired.');
        }

        if ($session['approved_user_id'] === null) {
            throw new ConflictHttpException('Install session is still pending approval.');
        }

        return $session;
    }

    private function findByDeviceCode(string $deviceCode): ?array
    {
        $session = Cache::get($this->deviceCodeKey($deviceCode));

        if (! is_array($session)) {
            return null;
        }

        $expiresAt = Carbon::parse($session['expires_at']);

        if ($expiresAt->isPast()) {
            $this->forget($session);

            return null;
        }

        return $session;
    }

    private function findByUserCode(string $userCode): ?array
    {
        $normalizedUserCode = $this->normalizeUserCode($userCode);

        if ($normalizedUserCode === null) {
            return null;
        }

        $deviceCode = Cache::get($this->userCodeKey($normalizedUserCode));

        if (! is_string($deviceCode) || $deviceCode === '') {
            return null;
        }

        return $this->findByDeviceCode($deviceCode);
    }

    private function persist(array $session): void
    {
        $expiresAt = Carbon::parse($session['expires_at']);

        Cache::put($this->deviceCodeKey($session['device_code']), $session, $expiresAt);
        Cache::put($this->userCodeKey($session['user_code']), $session['device_code'], $expiresAt);
    }

    private function forget(array $session): void
    {
        Cache::forget($this->deviceCodeKey($session['device_code']));
        Cache::forget($this->userCodeKey($session['user_code']));
    }

    private function state(array $session): string
    {
        return $session['approved_user_id'] === null ? 'pending' : 'approved';
    }

    private function generateUniqueDeviceCode(): string
    {
        do {
            $deviceCode = Str::random(self::DEVICE_CODE_LENGTH);
        } while (Cache::has($this->deviceCodeKey($deviceCode)));

        return $deviceCode;
    }

    private function generateUniqueUserCode(): string
    {
        do {
            $characters = collect(range(1, self::USER_CODE_LENGTH))
                ->map(fn () => self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)])
                ->implode('');

            $userCode = substr($characters, 0, self::USER_CODE_GROUP_LENGTH)
                .'-'.
                substr($characters, self::USER_CODE_GROUP_LENGTH);
        } while (Cache::has($this->userCodeKey($userCode)));

        return $userCode;
    }

    private function installerTokenName(Project $project): string
    {
        return sprintf('shift-sdk-install:%d:%s', $project->id, now()->format('YmdHis'));
    }

    private function sessionLifetimeSeconds(): int
    {
        return 15 * 60;
    }

    private function deviceCodeKey(string $deviceCode): string
    {
        return 'sdk-install-session:device:'.$deviceCode;
    }

    private function userCodeKey(string $userCode): string
    {
        return 'sdk-install-session:user:'.$userCode;
    }

    private function withLock(string $deviceCode, callable $callback): mixed
    {
        return Cache::lock('sdk-install-session-lock:'.$deviceCode, 10)->block(5, $callback);
    }
}
