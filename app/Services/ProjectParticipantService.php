<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectParticipantService
{
    public function create(Project $project): ProjectParticipant
    {
        return ProjectParticipant::query()->create(['project_id' => $project->id]);
    }

    public function link(ExternalIdentity $source, ExternalIdentity $linked, ?User $actor = null): void
    {
        $this->assertSameProject($source, $linked);
        abort_if($source->id === $linked->id, 422, 'An external account cannot be linked to itself.');

        DB::transaction(function () use ($source, $linked, $actor): void {
            $project = Project::query()->lockForUpdate()->findOrFail($source->project_id);
            $identities = ExternalIdentity::query()->where('project_id', $project->id)
                ->whereIn('id', [$source->id, $linked->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $identities->get($source->id);
            $linked = $identities->get($linked->id);
            abort_unless($source && $linked, 404);

            $participant = $this->ensure($source, $project);
            if ($linked->project_participant_id === $participant->id) {
                return;
            }

            $previousParticipantId = $linked->project_participant_id;
            $group = ExternalIdentity::query()->where('project_id', $project->id);
            $previousParticipantId === null
                ? $group->whereKey($linked->id)
                : $group->where('project_participant_id', $previousParticipantId);
            $identityIds = $group->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $group->update(['project_participant_id' => $participant->id]);

            $this->record($participant, $actor, 'accounts_linked', $source->id, $identityIds, $previousParticipantId);
        }, 3);
    }

    public function unlink(ExternalIdentity $source, ExternalIdentity $linked, ?User $actor = null): void
    {
        $this->assertSameProject($source, $linked);
        abort_if($source->id === $linked->id, 422, 'An external account cannot be unlinked from itself.');

        DB::transaction(function () use ($source, $linked, $actor): void {
            $project = Project::query()->lockForUpdate()->findOrFail($source->project_id);
            $identities = ExternalIdentity::query()->where('project_id', $project->id)
                ->whereIn('id', [$source->id, $linked->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $identities->get($source->id);
            $linked = $identities->get($linked->id);
            abort_unless($source && $linked, 404);
            abort_unless($source->project_participant_id !== null
                && $source->project_participant_id === $linked->project_participant_id, 404);

            $previousParticipantId = $linked->project_participant_id;
            $participant = $this->create($project);
            $linked->forceFill(['project_participant_id' => $participant->id])->save();
            $this->record($participant, $actor, 'account_unlinked', $source->id, [$linked->id], $previousParticipantId);
        }, 3);
    }

    private function ensure(ExternalIdentity $identity, Project $project): ProjectParticipant
    {
        if ($identity->project_participant_id !== null) {
            return ProjectParticipant::query()->where('project_id', $project->id)
                ->findOrFail($identity->project_participant_id);
        }

        $participant = $this->create($project);
        $identity->forceFill(['project_participant_id' => $participant->id])->save();

        return $participant;
    }

    private function assertSameProject(ExternalIdentity $source, ExternalIdentity $linked): void
    {
        abort_unless($source->project_id !== null && $source->project_id === $linked->project_id, 404);
    }

    /** @param list<int> $identityIds */
    private function record(ProjectParticipant $participant, ?User $actor, string $event, int $sourceId, array $identityIds, ?int $previousParticipantId): void
    {
        activity('project_participant')->performedOn($participant)->causedBy($actor)->event($event)
            ->withProperties([
                'source_identity_id' => $sourceId,
                'identity_ids' => $identityIds,
                'previous_participant_id' => $previousParticipantId,
            ])->log($event);
    }
}
