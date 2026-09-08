<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class FullOperationsIndexFilterRequest extends FormRequest
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
            'variant_id' => ['nullable', 'integer'],
            'operation_type' => ['nullable', 'string', 'max:80'],
            'tab' => ['nullable', 'string', 'max:40'],
            'connection_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{
     *     q: string,
     *     variant_id: int|null,
     *     operation_type: string,
     *     tab: string,
     *     connection_id: int|null,
     *     from: string,
     *     to: string
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $tab = strtolower(trim((string) ($validated['tab'] ?? 'all')));
        if ($tab === '') {
            $tab = 'all';
        }

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'variant_id' => isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            'operation_type' => trim((string) ($validated['operation_type'] ?? '')),
            'tab' => $tab,
            'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
            'from' => trim((string) ($validated['from'] ?? '')),
            'to' => trim((string) ($validated['to'] ?? '')),
        ];
    }
}
