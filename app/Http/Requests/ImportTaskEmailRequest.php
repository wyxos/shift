<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Services\ShiftPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImportTaskEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'email' => ['required', 'file', 'max:20480'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $project = Project::query()
                ->visibleTo($this->user()?->id)
                ->find($this->integer('project_id'));

            if (! $project instanceof Project) {
                $validator->errors()->add('project_id', 'The selected project is invalid.');

                return;
            }

            if (! app(ShiftPermissionService::class)->canCreateTaskForProject($project, $this->user()?->id)) {
                $validator->errors()->add('project_id', 'You do not have permission to create tasks for this project.');
            }
        });
    }
}
