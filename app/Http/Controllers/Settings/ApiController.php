<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApiController extends Controller
{
    public function edit()
    {
        return Inertia::render('settings/Api')
            ->with([
                'token' => session('token', '')
            ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $token = $request->user()->createToken($request->name);

        return back()->with([
            'success' => 'API token created successfully.',
            'token' => $token->plainTextToken
        ]);
    }
}
