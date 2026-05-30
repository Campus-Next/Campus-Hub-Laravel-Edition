<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EventParticipantController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $validator = Validator::make($request->query(), [
            'search'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'   => ['sometimes', 'string', 'in:all,registered,attended,absent,cancelled'],
            'sort'     => ['sometimes', 'string', 'in:date,name'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $search = trim((string) $request->query('search', ''));

        // Search-constrained base query, BEFORE applying the status filter, so
        // the per-tab counts reflect the search but stay whole across tabs.
        $base = $event->participants()->when($search !== '', function ($q) use ($search) {
            $q->whereHas('user', function ($u) use ($search) {
                $u->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        });

        $counts = $this->wantsPagination($request) ? $this->statusCountsFrom($base) : null;

        $query = $base->with('user');

        if (($status = $request->query('status')) && $status !== 'all') {
            $query->where('status', $status);
        }

        $this->applyParticipantSort($query, $request->query('sort'));

        if ($this->wantsPagination($request)) {
            return $this->paginatedResponse(
                $query->paginate($this->resolvePerPage($request)),
                'Participants retrieved successfully',
                ['counts' => $counts],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Participants retrieved successfully',
            'data' => $query->get(),
        ]);
    }

    /**
     * Sort participants by join date (default) or by participant name.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\HasMany<EventParticipant, Event>  $query
     */
    private function applyParticipantSort($query, ?string $sort): void
    {
        match ($sort) {
            'name'  => $query->orderBy(
                User::select('name')->whereColumn('users.id', 'event_participants.user_id')
            ),
            default => $query->latest(),
        };
    }

    /**
     * Group-by status counts for the tab badges, including an "all" total.
     * Honors any constraints already applied to the given query.
     *
     * @return array<string, int>
     */
    private function statusCountsFrom($query): array
    {
        $counts = ['all' => 0, 'registered' => 0, 'attended' => 0, 'absent' => 0, 'cancelled' => 0];

        $rows = (clone $query)
            ->toBase()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            $counts['all'] += (int) $count;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $count;
            }
        }

        return $counts;
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();

        if ($event->registration_open && now()->lt($event->registration_open)) {
            return response()->json([
                'success' => false,
                'message' => 'Registration for this event has not opened yet',
            ], 422);
        }

        if ($event->registration_deadline && now()->gt($event->registration_deadline)) {
            return response()->json([
                'success' => false,
                'message' => 'Registration for this event has closed',
            ], 422);
        }

        $result = DB::transaction(function () use ($event, $user) {
            // Serialize concurrent enrollments for this event so the capacity
            // check and the insert cannot interleave.
            Event::whereKey($event->id)->lockForUpdate()->first();

            $existingParticipant = $event->participants()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingParticipant && $existingParticipant->status !== 'cancelled') {
                return ['outcome' => 'already_enrolled'];
            }

            if ($event->max_participants > 0) {
                $current = $event->participants()->whereIn('status', ['registered', 'attended'])->count();
                if ($current >= $event->max_participants) {
                    return ['outcome' => 'full'];
                }
            }

            if ($existingParticipant && $existingParticipant->status === 'cancelled') {
                $existingParticipant->update([
                    'status' => 'registered',
                    'unique_code' => EventParticipant::generateUniqueCode($event),
                    'cancelled_at' => null,
                ]);

                return ['outcome' => 'reenrolled', 'participant' => $existingParticipant->fresh()];
            }

            return ['outcome' => 'created', 'participant' => $event->participants()->create([
                'user_id' => $user->id,
                'status' => 'registered',
                'unique_code' => EventParticipant::generateUniqueCode($event),
                'cancelled_at' => null,
            ])];
        });

        if ($result['outcome'] === 'already_enrolled') {
            return response()->json([
                'success' => false,
                'message' => 'You are already enrolled in this event',
            ], 409);
        }

        if ($result['outcome'] === 'full') {
            return response()->json([
                'success' => false,
                'message' => 'This event has reached its maximum participants',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Successfully enrolled in event',
            'data' => $result['participant'],
        ], $result['outcome'] === 'reenrolled' ? 200 : 201);
    }

    public function show(Request $request, Event $event, EventParticipant $participant): JsonResponse
    {
        if ($participant->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Participant not found',
            ], 404);
        }

        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'message' => 'Participant retrieved successfully',
            'data' => $participant->load('user'),
        ]);
    }

    public function update(Request $request, Event $event, EventParticipant $participant): JsonResponse
    {
        if ($participant->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Participant not found',
            ], 404);
        }

        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['attended'])],
        ]);

        $allowedTransitions = [
            'registered' => ['attended'],
            'attended' => [],
            'absent' => [],
            'cancelled' => [],
        ];

        if (!in_array($validated['status'], $allowedTransitions[$participant->status], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid participant status transition',
                'errors' => [
                    'status' => ['The selected status is invalid for the participant\'s current state.'],
                ],
            ], 422);
        }

        $participant->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Participant status updated successfully',
            'data' => $participant,
        ]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $participant = $event->participants()->where('user_id', $request->user()->id)->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not enrolled in this event',
            ], 404);
        }

        if ($participant->status === 'cancelled') {
            return response()->json([
                'success' => true,
                'message' => 'Registration already cancelled',
                'data' => $participant,
            ]);
        }

        if ($participant->status !== 'registered') {
            return response()->json([
                'success' => false,
                'message' => 'Only registered participants can cancel registration',
            ], 422);
        }

        $participant->update([
            'status' => 'cancelled',
            'unique_code' => null,
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Successfully cancelled registration',
            'data' => $participant->fresh(),
        ]);
    }

    public function myEvents(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'scope'    => ['sometimes', Rule::in(['registered', 'organized'])],
            'search'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'   => ['sometimes', 'string', 'in:all,registered,attended,absent,cancelled'],
            'sort'     => ['sometimes', 'string', 'in:date,title'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $scope = $validator->validated()['scope'] ?? 'registered';
        $search = trim((string) $request->query('search', ''));

        if ($scope === 'organized') {
            if (!$request->user()->can('update events')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to view organized events',
                ], 403);
            }

            $query = $request->user()
                ->events()
                ->with(['category:id,name,slug', 'images'])
                ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.$search.'%'));

            match ($request->query('sort')) {
                'date'  => $query->orderBy('updated_at', 'desc'),
                'title' => $query->orderBy('title'),
                default => $query->latest(),
            };

            if ($this->wantsPagination($request)) {
                return $this->paginatedResponse(
                    $query->paginate($this->resolvePerPage($request)),
                    'Organized events retrieved successfully',
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Organized events retrieved successfully',
                'data' => $query->get(),
            ]);
        }

        // Search-constrained base (event title), BEFORE the status filter, so
        // tab counts reflect the search but stay whole across tabs.
        $base = $request->user()->participants()->when($search !== '', function ($q) use ($search) {
            $q->whereHas('event', fn ($e) => $e->where('title', 'like', '%'.$search.'%'));
        });

        $counts = $this->wantsPagination($request) ? $this->statusCountsFrom($base) : null;

        $query = $base->with(['event.category:id,name,slug', 'event.images']);

        if (($status = $request->query('status')) && $status !== 'all') {
            $query->where('status', $status);
        }

        match ($request->query('sort')) {
            'title' => $query->orderBy(
                Event::select('title')->whereColumn('events.id', 'event_participants.event_id')
            ),
            default => $query->latest(),
        };

        if ($this->wantsPagination($request)) {
            return $this->paginatedResponse(
                $query->paginate($this->resolvePerPage($request)),
                'Enrolled events retrieved successfully',
                ['counts' => $counts],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Enrolled events retrieved successfully',
            'data' => $query->get(),
        ]);
    }

    public function myCode(Request $request, Event $event): JsonResponse
    {
        $participant = $event->participants()
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not enrolled in this event',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Unique code retrieved successfully',
            'data' => [
                'unique_code' => $participant->unique_code,
                'status' => $participant->status,
            ],
        ]);
    }

    public function checkIn(Request $request, Event $event): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $validated = $request->validate([
            'code' => 'required|string|size:4',
        ]);

        $participant = $event->participants()
            ->where('unique_code', strtoupper($validated['code']))
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid check-in code',
            ], 404);
        }

        if ($participant->status === 'attended') {
            return response()->json([
                'success' => false,
                'message' => 'Participant has already checked in',
            ], 409);
        }

        if ($participant->status !== 'registered') {
            return response()->json([
                'success' => false,
                'message' => 'Participant cannot be checked in from current status',
            ], 422);
        }

        $participant->update(['status' => 'attended']);

        return response()->json([
            'success' => true,
            'message' => 'Check-in successful',
            'data' => $participant->load('user:id,name,email'),
        ]);
    }
}
