<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function denyUnlessEventOrganizer(Request $request, Event $event): ?JsonResponse
    {
        if ((int) $event->organizer_id === (int) $request->user()?->id) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'You are not allowed to manage this event',
        ], 403);
    }

    /**
     * Whether the client opted into server-side pagination.
     *
     * Listing endpoints stay backward compatible: they only paginate (and
     * expose a "meta" envelope) when the request carries "page" or "per_page".
     */
    protected function wantsPagination(Request $request): bool
    {
        return $request->query('page') !== null || $request->query('per_page') !== null;
    }

    /**
     * Resolve a safe per-page size, capped to avoid abusive requests.
     */
    protected function resolvePerPage(Request $request, int $default = 12, int $max = 50): int
    {
        $perPage = (int) $request->query('per_page', $default);

        if ($perPage < 1) {
            $perPage = $default;
        }

        return min($perPage, $max);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<string, mixed>  $extraMeta
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, string $message, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $paginator->items(),
            'meta'    => array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ], $extraMeta),
        ]);
    }
}
