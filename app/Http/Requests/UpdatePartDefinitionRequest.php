<?php

namespace App\Http\Requests;

use App\Models\PartDefinition;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePartDefinitionRequest extends FormRequest
{
    use ValidatesPartOperations;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'material' => ['nullable', 'string', 'max:255'],
            'length' => ['sometimes', 'required', 'numeric', 'min:1', 'max:100000'],
            'width' => ['sometimes', 'required', 'numeric', 'min:1', 'max:100000'],
            'thickness' => ['sometimes', 'required', 'numeric', 'min:1', 'max:10000'],
            'grain_axis' => ['sometimes', 'required', 'string', 'in:length,width'],
            ...$this->operationRules(),
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        $partDefinition = $this->route('partDefinition');
        $partDefinition = $partDefinition instanceof PartDefinition ? $partDefinition : null;

        return [function (Validator $validator) use ($partDefinition): void {
            $operations = $this->input('operations', []);
            if (! is_array($operations) || $operations === []) {
                return;
            }

            if ($partDefinition !== null) {
                $managedIds = $partDefinition->mortiseTenonConnections()
                    ->whereIn('machining_status', ['generated', 'outdated'])
                    ->get(['generated_operation_ids'])
                    ->flatMap(fn ($connection): array => $connection->generated_operation_ids ?? [])
                    ->where('part_definition_id', $partDefinition->id)
                    ->pluck('operation_id')
                    ->flip();
                $saved = collect($partDefinition->operations)->keyBy('id');
                $operations = array_filter($operations, function (mixed $operation) use ($managedIds, $saved): bool {
                    $id = is_array($operation) ? ($operation['id'] ?? null) : null;

                    return ! is_string($id) || ! $managedIds->has($id) || $operation != $saved->get($id);
                });
            }

            $this->validatePartOperations(
                $validator,
                $operations,
                (float) $this->input('length', $partDefinition?->length ?? 0),
                (float) $this->input('width', $partDefinition?->width ?? 0),
                (float) $this->input('thickness', $partDefinition?->thickness ?? 0),
            );
        }];
    }
}
