<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class IndexFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archived' => ['nullable', 'boolean'],
            'without_cost' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{archived: bool, without_cost: bool}
     */
    public function filters(): array
    {
        return [
            'archived' => $this->boolean('archived'),
            'without_cost' => $this->boolean('without_cost'),
        ];
    }
}
