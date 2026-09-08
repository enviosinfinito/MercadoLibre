<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RespondsForEmbeddedEditor
{
    protected function isEmbeddedRequest(Request $request): bool
    {
        return ($request->wantsJson() || $request->expectsJson())
            && ! $request->header('X-Inertia');
    }

    protected function respondEmbeddedSave(
        Request $request,
        string $message,
        array $data = [],
        ?string $redirectRoute = null,
        array $redirectParams = []
    ): JsonResponse|RedirectResponse {
        if ($this->isEmbeddedRequest($request)) {
            return response()->json(array_merge([
                'success' => true,
                'message' => $message,
            ], $data));
        }

        if ($redirectRoute) {
            return redirect()->route($redirectRoute, $redirectParams)
                ->with('success', $message);
        }

        return redirect()->back()->with('success', $message);
    }

    protected function respondEmbeddedError(
        Request $request,
        string $message,
        int $status = 422,
        array $errors = [],
        ?string $redirectRoute = null
    ): JsonResponse|RedirectResponse {
        if ($this->isEmbeddedRequest($request)) {
            return response()->json([
                'message' => $message,
                'errors' => $errors ?: ['error' => [$message]],
            ], $status);
        }

        if ($redirectRoute) {
            return redirect()->route($redirectRoute)->withErrors($errors ?: ['message' => $message]);
        }

        return redirect()->back()->withInput()->withErrors($errors ?: ['message' => $message]);
    }
}
