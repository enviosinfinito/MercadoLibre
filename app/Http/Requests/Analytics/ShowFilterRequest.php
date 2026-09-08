<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class ShowFilterRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'connection_id' => ['nullable'],
            'date_field' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, connection_id: string|null, date_field: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'date_from' => trim((string) ($validated['date_from'] ?? '')),
            'date_to' => trim((string) ($validated['date_to'] ?? '')),
            'connection_id' => isset($validated['connection_id']) && $validated['connection_id'] !== ''
                ? (string) $validated['connection_id']
                : null,
            'date_field' => trim((string) ($validated['date_field'] ?? 'ordered_at')) ?: 'ordered_at',
        ];
    }
}
