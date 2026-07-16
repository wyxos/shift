<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use App\Services\ShiftPermissionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
        $sidebarOrganisations = null;
        $resolveSidebarOrganisations = function () use ($request, &$sidebarOrganisations) {
            return $sidebarOrganisations ??= $this->sidebarOrganisations($request);
        };

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'ziggy' => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'shift' => [
                'ai_rewrite_enabled' => (bool) config('ai_features.rewrite.enabled', false),
                'ai_email_import_enabled' => (bool) config('ai_features.email_import.enabled', false),
            ],
            'sidebarOrganisations' => fn () => $resolveSidebarOrganisations()['items'],
            'sidebarOrganisationsHasMore' => fn () => $resolveSidebarOrganisations()['hasMore'],
        ];
    }

    /**
     * @return array{items: Collection<int, array{id: int, name: string, isOwner: bool}>, hasMore: bool}
     */
    private function sidebarOrganisations(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [
                'items' => collect(),
                'hasMore' => false,
            ];
        }

        $organisations = Organisation::query()
            ->visibleToUser($user->id)
            ->orderBy('name')
            ->limit(6)
            ->get(['id', 'name', 'author_id']);
        $permissions = app(ShiftPermissionService::class);
        $items = $organisations->take(5);
        $selectedOrganisationId = $this->selectedOrganisationId($request);

        if ($selectedOrganisationId && ! $items->contains('id', $selectedOrganisationId)) {
            $selectedOrganisation = Organisation::query()
                ->visibleToUser($user->id)
                ->whereKey($selectedOrganisationId)
                ->first(['id', 'name', 'author_id']);

            if ($selectedOrganisation) {
                $items->push($selectedOrganisation);
            }
        }

        return [
            'items' => $items
                ->map(fn (Organisation $organisation) => [
                    'id' => $organisation->id,
                    'name' => $organisation->name,
                    'isOwner' => $organisation->author_id === $user->id,
                    ...$permissions->organisationCapabilities($organisation, $user->id),
                ])
                ->values(),
            'hasMore' => $organisations->count() > 5,
        ];
    }

    private function selectedOrganisationId(Request $request): ?int
    {
        if (preg_match('/^organisation\/(\d+)(?:\/|$)/', $request->path(), $matches) === 1) {
            return (int) $matches[1];
        }

        foreach (['organisation_id', 'team', 'manage', 'settings'] as $key) {
            $value = $request->query($key);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
