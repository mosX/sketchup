<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApiTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->tokenCan('*') || $this->user()?->tokenCan('tokens:manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1', 'max:2'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(['projects:read', 'projects:write'])],
            'project_id' => ['nullable', 'integer'],
            'expires_in_days' => ['sometimes', 'integer', 'between:1,365'],
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('project_id') === null) {
                return;
            }

            $ownsProject = Project::query()
                ->whereKey($this->integer('project_id'))
                ->whereBelongsTo($this->user())
                ->exists();

            if (! $ownsProject) {
                $validator->errors()->add('project_id', 'The selected project is unavailable.');
            }
        }];
    }
}
