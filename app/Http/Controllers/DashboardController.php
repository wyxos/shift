<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        $baseQuery = Task::query()
            ->where(function ($query) use ($userId) {
                $query
                    ->whereHas('project.projectUser', function ($query) use ($userId) {
                        $query->where('user_id', $userId);
                    })
                    ->orWhereHas('project', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHas('project.organisation', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHas('project.client.organisation', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHasMorph('submitter', [User::class], function ($query) use ($userId) {
                        $query->where('users.id', $userId);
                    });
            });

        $metrics = [
            'total' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'in_progress' => (clone $baseQuery)->where('status', 'in-progress')->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
        ];

        return Inertia::render('Dashboard', [
            'metrics' => $metrics,
        ]);
    }
}
