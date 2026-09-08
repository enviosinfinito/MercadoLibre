<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'tab' => ['nullable', 'string', 'max:40'],
            'release_issue' => ['nullable', 'string', 'in:overdue,orphan,unreleased,held'],
            'page' => ['nullable', 'integer', 'min:1'],
            'since_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: string, status: string|null, connection_id: int|null, from: string, to: string, tab: string, release_issue: string|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $releaseIssue = isset($validated['release_issue']) ? trim((string) $validated['release_issue']) : '';

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'status' => isset($validated['status']) ? trim((string) $validated['status']) : null,
            'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
            'from' => trim((string) ($validated['from'] ?? '')),
            'to' => trim((string) ($validated['to'] ?? '')),
            'tab' => trim((string) ($validated['tab'] ?? 'all')) ?: 'all',
            'release_issue' => $releaseIssue !== '' ? $releaseIssue : null,
        ];
    }
}
