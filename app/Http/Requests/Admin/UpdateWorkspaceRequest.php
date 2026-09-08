<?php

namespace App\Http\Requests\Admin;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_platform_admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $workspaceId = $workspace instanceof Workspace
            ? $workspace->id
            : $workspace;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('workspaces', 'slug')->ignore($workspaceId),
            ],
            'reporting_currency' => ['required', 'string', 'size:3'],
            'default_costing_method' => ['required', 'string', 'in:fifo,weighted_average'],
        ];
    }
}
