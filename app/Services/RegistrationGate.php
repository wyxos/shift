<?php

namespace App\Services;

use App\Enums\RegistrationPolicy;
use App\Models\OrganisationUser;
use App\Models\ProjectUser;
use Illuminate\Http\Request;

class RegistrationGate
{
    private const INVITATION_SESSION_KEY = 'registration.invitation';

    public function authorize(Request $request): void
    {
        $policy = RegistrationPolicy::tryFrom((string) config('shift.registration_mode'));

        abort_if($policy === null || $policy === RegistrationPolicy::Closed, 403);

        if ($request->isMethod('GET')) {
            $this->authorizePage($request, $policy);

            return;
        }

        $this->authorizeSubmission($request, $policy);
    }

    public function forgetInvitation(Request $request): void
    {
        $request->session()->forget(self::INVITATION_SESSION_KEY);
    }

    private function authorizePage(Request $request, RegistrationPolicy $policy): void
    {
        if (! $this->hasInvitationContext($request)) {
            abort_if($policy === RegistrationPolicy::InviteOnly, 403);

            return;
        }

        abort_unless($request->hasValidSignature() && $this->hasPendingInvitation($request), 403);

        $request->session()->put(self::INVITATION_SESSION_KEY, $this->invitationContext($request));
    }

    private function authorizeSubmission(Request $request, RegistrationPolicy $policy): void
    {
        if (! $this->hasInvitationContext($request)) {
            abort_if($policy === RegistrationPolicy::InviteOnly, 403);

            return;
        }

        abort_unless(
            $request->session()->get(self::INVITATION_SESSION_KEY) === $this->invitationContext($request)
                && $this->hasPendingInvitation($request),
            403,
        );
    }

    private function hasInvitationContext(Request $request): bool
    {
        return $request->filled('project_id') || $request->filled('organisation_id');
    }

    /**
     * @return array{email: string, project_id: ?int, organisation_id: ?int}
     */
    private function invitationContext(Request $request): array
    {
        return [
            'email' => strtolower(trim((string) $request->input('email'))),
            'project_id' => $request->filled('project_id') ? (int) $request->input('project_id') : null,
            'organisation_id' => $request->filled('organisation_id') ? (int) $request->input('organisation_id') : null,
        ];
    }

    private function hasPendingInvitation(Request $request): bool
    {
        $context = $this->invitationContext($request);

        if ($context['email'] === '') {
            return false;
        }

        if ($context['project_id'] !== null) {
            return ProjectUser::query()
                ->where('project_id', $context['project_id'])
                ->whereNull('user_id')
                ->where('registration_status', 'pending')
                ->whereRaw('LOWER(user_email) = ?', [$context['email']])
                ->exists();
        }

        if ($context['organisation_id'] !== null) {
            return OrganisationUser::query()
                ->where('organisation_id', $context['organisation_id'])
                ->whereNull('user_id')
                ->whereRaw('LOWER(user_email) = ?', [$context['email']])
                ->exists();
        }

        return false;
    }
}
