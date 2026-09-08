<?php

declare(strict_types=1);

namespace App\Http\Requests\Questions;

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
            'q' => ['nullable', 'string', 'max:200'],
            'connection_id' => ['nullable', 'integer'],
            'tab' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: string, connection_id: int|null, tab: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $tab = trim((string) ($validated['tab'] ?? 'all')) ?: 'all';
        if (! in_array($tab, ['all', 'unanswered', 'answered'], true)) {
            $tab = 'all';
        }

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
            'tab' => $tab,
        ];
    }
}
