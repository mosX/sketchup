<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectConnectionRequest extends FormRequest
{
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_verified' => ['sometimes', 'boolean'],
            'parameters' => ['sometimes', 'array:primary_face,secondary_face,primary_center_u,primary_center_v,secondary_center_u,secondary_center_v,joint_angle,joint_length,joint_width,depth_ratio,tenon_width,tenon_thickness,tenon_length,dowel_diameter,dowel_count,dowel_depth,dowel_spacing'],
            'parameters.primary_face' => ['sometimes', Rule::in(['top', 'bottom', 'left', 'right', 'start', 'end'])],
            'parameters.secondary_face' => ['sometimes', Rule::in(['top', 'bottom', 'left', 'right', 'start', 'end'])],
            'parameters.primary_center_u' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'parameters.primary_center_v' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'parameters.secondary_center_u' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'parameters.secondary_center_v' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'parameters.joint_angle' => ['sometimes', 'numeric', 'between:-180,180'],
            'parameters.joint_length' => ['sometimes', 'numeric', 'min:0.1', 'max:100000'],
            'parameters.joint_width' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            'parameters.depth_ratio' => ['sometimes', 'numeric', 'between:0.1,0.9'],
            'parameters.tenon_width' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            'parameters.tenon_thickness' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            'parameters.tenon_length' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            'parameters.dowel_diameter' => ['sometimes', 'numeric', 'min:0.1', 'max:100'],
            'parameters.dowel_count' => ['sometimes', 'integer', 'between:1,100'],
            'parameters.dowel_depth' => ['sometimes', 'numeric', 'min:0.1', 'max:1000'],
            'parameters.dowel_spacing' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
        ];
    }
}
