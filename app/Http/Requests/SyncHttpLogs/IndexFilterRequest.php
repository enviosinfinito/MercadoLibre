<?php

declare(strict_types=1);

namespace App\Http\Requests\SyncHttpLogs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'http_direction' => ['nullable', 'string', 'in:in,out'],
            // Legacy alias (tabs antiguos); se normaliza a http_direction.
            'direction' => ['nullable', 'string', 'in:in,out'],
            'status' => ['nullable', 'string', 'in:success,error'],
            'topic' => ['nullable', 'string', 'max:64'],
            'connection_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'orphan' => ['nullable'],
            'body_search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'since_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bodySearch = trim((string) $this->input('body_search', ''));
            if ($bodySearch === '') {
                return;
            }

            if (! $this->filled('connection_id')) {
                $validator->errors()->add('body_search', 'La búsqueda en body requiere una conexión.');
            }

            if (! $this->filled('from') || ! $this->filled('to')) {
                $validator->errors()->add('body_search', 'La búsqueda en body requiere un rango de fechas (desde y hasta).');
            }
        });
    }

    /**
     * @return array{
     *     q: string,
     *     http_direction: string,
     *     status: string,
     *     topic: string,
     *     connection_id: int|null,
     *     from: string,
     *     to: string,
     *     orphan: bool,
     *     body_search: string
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $httpDirection = trim((string) ($validated['http_direction'] ?? ''));
        if ($httpDirection === '') {
            $httpDirection = trim((string) ($validated['direction'] ?? ''));
        }

        $orphanRaw = $validated['orphan'] ?? $this->input('orphan');
        $orphan = in_array($orphanRaw, [1, '1', true, 'true', 'on'], true);

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'http_direction' => $httpDirection,
            'status' => trim((string) ($validated['status'] ?? '')),
            'topic' => trim((string) ($validated['topic'] ?? '')),
            'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
            'from' => trim((string) ($validated['from'] ?? '')),
            'to' => trim((string) ($validated['to'] ?? '')),
            'orphan' => $orphan,
            'body_search' => trim((string) ($validated['body_search'] ?? '')),
        ];
    }
}
