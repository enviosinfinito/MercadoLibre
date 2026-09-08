<?php

declare(strict_types=1);

namespace App\Http\Filters\Questions;

use Illuminate\Database\Eloquent\Builder;

final class QuestionFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (! empty($filters['connection_id'])) {
            $query->where('connection_id', (int) $filters['connection_id']);
        }

        $tab = $filters['tab'] ?? 'all';
        if ($tab === 'unanswered') {
            $query->where('status', 'unanswered');
        } elseif ($tab === 'answered') {
            $query->where('status', 'answered');
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('external_question_id', 'like', '%'.$q.'%')
                    ->orWhere('external_item_id', 'like', '%'.$q.'%')
                    ->orWhere('buyer_external_id', 'like', '%'.$q.'%')
                    ->orWhere('question_text', 'like', '%'.$q.'%')
                    ->orWhere('id', $q);
            });
        }

        return $query;
    }
}
