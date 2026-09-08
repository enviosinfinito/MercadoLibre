<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithJsonPaginator
{
    /**
     * Infinite-scroll / AJAX list request: wants JSON but is not an Inertia visit.
     */
    protected function wantsJsonWithoutInertia(Request $request): bool
    {
        return ($request->wantsJson() || $request->ajax() || $request->expectsJson())
            && ! $request->header('X-Inertia');
    }

    /**
     * Return LengthAwarePaginator (or compatible) as JSON for page 2+ / filter AJAX.
     */
    protected function jsonPaginator(Paginator $paginator): JsonResponse
    {
        return response()->json(
            method_exists($paginator, 'toArray')
                ? $paginator->toArray()
                : [
                    'data' => $paginator->items(),
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'prev_page_url' => $paginator->previousPageUrl(),
                ]
        );
    }
}
