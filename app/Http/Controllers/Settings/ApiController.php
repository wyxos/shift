<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mcp\Support\ShiftMcpAccess;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class ApiController extends Controller
{
    private const MCP_TOKEN_NAME = 'shift-mcp';

    private const MCP_TOKEN_ABILITY = ShiftMcpAccess::READ_ABILITY;

    private const MCP_TOKEN_ABILITIES = [
        ShiftMcpAccess::READ_ABILITY,
        ShiftMcpAccess::WRITE_ABILITY,
    ];

    private const SDK_INSTALL_TOKEN_PREFIX = 'shift-sdk-install:';

    public function edit(Request $request)
    {
        $tokens = $request->user()
            ->tokens()
            ->latest('created_at')
            ->get();

        return Inertia::render('settings/Api')
            ->with([
                'token' => session('token', ''),
                'mcpTokens' => $tokens
                    ->filter(fn (PersonalAccessToken $token) => $this->isMcpToken($token))
                    ->map(fn (PersonalAccessToken $token) => $this->tokenRecord($token))
                    ->values(),
                'sdkTokens' => $tokens
                    ->filter(fn (PersonalAccessToken $token) => $this->isSdkInstallToken($token))
                    ->map(fn (PersonalAccessToken $token) => $this->tokenRecord($token, includeProject: true))
                    ->values(),
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
            'token' => $token->plainTextToken,
        ]);
    }

    public function resetMcpToken(Request $request)
    {
        $request->user()
            ->tokens()
            ->get()
            ->each(function (PersonalAccessToken $token): void {
                if ($this->isMcpToken($token)) {
                    $token->delete();
                }
            });

        $token = $request->user()->createToken(self::MCP_TOKEN_NAME, self::MCP_TOKEN_ABILITIES);

        return response()->json($this->tokenResponse($token));
    }

    public function resetSdkToken(Request $request, PersonalAccessToken $token)
    {
        abort_unless($this->tokenBelongsToUser($token, $request->user()), 404);
        abort_unless($this->isSdkInstallToken($token), 404);

        $projectId = $this->sdkProjectId($token);

        abort_unless($projectId !== null, 404);

        $abilities = $token->abilities ?: ['*'];

        $token->delete();

        $newToken = $request->user()->createToken($this->sdkInstallTokenName($projectId), $abilities);

        return response()->json($this->tokenResponse($newToken, includeProject: true));
    }

    private function tokenResponse(NewAccessToken $token, bool $includeProject = false): array
    {
        return [
            'token' => $token->plainTextToken,
            'record' => $this->tokenRecord($token->accessToken, $includeProject),
        ];
    }

    private function tokenRecord(PersonalAccessToken $token, bool $includeProject = false): array
    {
        $record = [
            'id' => $token->id,
            'name' => $token->name,
            'created_at' => $token->created_at?->toISOString(),
            'last_used_at' => $token->last_used_at?->toISOString(),
        ];

        if ($includeProject) {
            $projectId = $this->sdkProjectId($token);
            $project = $projectId
                ? Project::query()->find($projectId, ['id', 'name'])
                : null;

            $record['project'] = $project
                ? [
                    'id' => $project->id,
                    'name' => $project->name,
                ]
                : null;
        }

        return $record;
    }

    private function isMcpToken(PersonalAccessToken $token): bool
    {
        return in_array(self::MCP_TOKEN_ABILITY, $token->abilities ?? [], true);
    }

    private function isSdkInstallToken(PersonalAccessToken $token): bool
    {
        return str_starts_with($token->name, self::SDK_INSTALL_TOKEN_PREFIX)
            && $this->sdkProjectId($token) !== null;
    }

    private function sdkProjectId(PersonalAccessToken $token): ?int
    {
        if (! preg_match('/^shift-sdk-install:(\d+):/', $token->name, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function sdkInstallTokenName(int $projectId): string
    {
        return sprintf('%s%d:%s', self::SDK_INSTALL_TOKEN_PREFIX, $projectId, now()->format('YmdHis'));
    }

    private function tokenBelongsToUser(PersonalAccessToken $token, $user): bool
    {
        return $token->tokenable_type === $user->getMorphClass()
            && (int) $token->tokenable_id === (int) $user->id;
    }
}
