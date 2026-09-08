<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptIndexFilterRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: string, variant_id: int|null, warehouse_id: int|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'variant_id' => isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            'warehouse_id' => isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
        ];
    }
}
