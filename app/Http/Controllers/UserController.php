<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserController extends Controller
{
    public function index()
    {
        $sortBy = request('sort_by');

        $users = User::query()
            ->when(
                request('search'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $term = '%'.$search.'%';

                    $query->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                        ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]);
                })
            );

        switch ($sortBy) {
            case 'name':
                $users->orderBy('name');
                break;
            case 'oldest':
                $users->oldest();
                break;
            default:
                $users->latest();
                break;
        }

        return inertia('Users/Index')
            ->with([
                'filters' => request()->only(['search', 'sort_by']),
                'users' => $users
                    ->paginate(10)
                    ->withQueryString(),
            ]);
    }
}
