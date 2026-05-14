<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
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
                'ai_enabled' => (bool) config('shift_ai.enabled', false),
            ],
            'sidebarOrganisations' => fn () => $request->user()
                ? Organisation::query()
                    ->where(function (Builder $query) use ($request) {
                        $query->where('author_id', $request->user()->id)
                            ->orWhereHas('organisationUsers', function (Builder $query) use ($request) {
                                $query->where('user_id', $request->user()->id);
                            });
                    })
                    ->orderBy('name')
                    ->limit(5)
                    ->get(['id', 'name', 'author_id'])
                    ->map(fn (Organisation $organisation) => [
                        'id' => $organisation->id,
                        'name' => $organisation->name,
                        'isOwner' => $organisation->author_id === $request->user()->id,
                    ])
                    ->values()
                : [],
        ];
    }
}
