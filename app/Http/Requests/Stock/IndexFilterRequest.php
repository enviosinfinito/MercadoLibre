<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

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
            'warehouse_id' => ['nullable', 'integer'],
            'low_stock' => ['nullable', 'boolean'],
            'unmatched' => ['nullable', 'boolean'],
            'channel_mismatch' => ['nullable', 'boolean'],
            'tab' => ['nullable', 'string', 'in:internal,marketplace'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: string, warehouse_id: int|null, low_stock: bool, unmatched: bool, channel_mismatch: bool, tab: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'warehouse_id' => isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            'low_stock' => $this->boolean('low_stock'),
            'unmatched' => $this->boolean('unmatched'),
            'channel_mismatch' => $this->boolean('channel_mismatch'),
            'tab' => trim((string) ($validated['tab'] ?? 'internal')) ?: 'internal',
        ];
    }
}
