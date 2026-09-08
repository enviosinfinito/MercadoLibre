<?php

declare(strict_types=1);

namespace App\Http\Requests\Publications;

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
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'connection_id' => ['nullable', 'integer'],
            'matched' => ['nullable', 'in:yes,no,any'],
            'without_cost' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: string, status: string, connection_id: int|null, matched: string, without_cost: bool}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'status' => trim((string) ($validated['status'] ?? '')),
            'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
            'matched' => $this->normalizedMatched($validated['matched'] ?? null),
            'without_cost' => $this->boolean('without_cost'),
        ];
    }

    private function normalizedMatched(mixed $matched): string
    {
        $value = trim((string) ($matched ?? ''));

        return ($value === '' || $value === 'any') ? 'any' : $value;
    }
}
