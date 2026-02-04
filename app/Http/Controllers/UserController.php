<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        return inertia('Users/Index')
            ->with([
                'filters' => request()->only(['search']),
                'users' => User::query()
                    ->latest()
                    ->when(
                        request('search'),
                        fn ($query) => $query->where(function ($query) {
                            $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                                ->orWhereRaw('LOWER(email) LIKE LOWER(?)', ['%' . request('search') . '%']);
                        })
                    )
                    ->paginate(10)
                    ->withQueryString(),
            ]);
    }
}
