<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class LedgerIndexFilterRequest extends FormRequest
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
            'movement_type' => ['nullable', 'string', 'max:40'],
            'warehouse_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: string, variant_id: int|null, movement_type: string, warehouse_id: int|null, from: string, to: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'variant_id' => isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            'movement_type' => trim((string) ($validated['movement_type'] ?? '')),
            'warehouse_id' => isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            'from' => trim((string) ($validated['from'] ?? '')),
            'to' => trim((string) ($validated['to'] ?? '')),
        ];
    }
}
