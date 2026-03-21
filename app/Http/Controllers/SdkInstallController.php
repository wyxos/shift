<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SdkInstallSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SdkInstallController extends Controller
{
    public function __construct(
        private readonly SdkInstallSessionService $sdkInstallSessionService,
    ) {}

    public function show(Request $request): Response
    {
        $userCode = strtoupper(trim((string) $request->input('user_code', '')));

        /** @var User|null $user */
        $user = $request->user();

        $session = $userCode !== ''
            ? $this->sdkInstallSessionService->detailsForUserCode($userCode, $user?->id)
            : null;

        return Inertia::render('SdkInstall/Verify', [
            'userCode' => $userCode !== '' ? $userCode : null,
            'lookupError' => $userCode !== '' && $session === null
                ? 'This install code is invalid or has expired.'
                : null,
            'session' => $session,
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $attributes = $request->validate([
            'user_code' => ['required', 'string', 'max:32'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $session = $this->sdkInstallSessionService->approve($attributes['user_code'], $user);
        } catch (HttpExceptionInterface $exception) {
            throw ValidationException::withMessages([
                'user_code' => $exception->getMessage(),
            ]);
        }

        return to_route('sdk-install.verify', [
            'user_code' => $session['user_code'],
        ]);
    }
}
