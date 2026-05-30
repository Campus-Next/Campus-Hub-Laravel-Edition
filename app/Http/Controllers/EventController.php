<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class EventController extends Controller
{
    public function __construct(private readonly EventImageService $eventImages)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'search'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort'     => ['sometimes', 'string', 'in:date,title'],
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

        $query = Event::query()->with(['organizer:id,name', 'category:id,name,slug', 'images'])
            ->withCount(['participants' => function ($q) {
                $q->whereIn('status', ['registered', 'attended']);
            }]);

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        } elseif ($categorySlug = $request->query('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $categorySlug));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $this->applyEventSort($query, $request->query('sort'));

        if ($this->wantsPagination($request)) {
            return $this->paginatedResponse(
                $query->paginate($this->resolvePerPage($request)),
                'Events retrieved successfully',
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Events retrieved successfully',
            'data'    => $query->get(),
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Event>  $query
     */
    private function applyEventSort($query, ?string $sort): void
    {
        match ($sort) {
            'date'  => $query->orderBy('start_date'),
            'title' => $query->orderBy('title'),
            default => $query->latest(),
        };
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title'                 => 'required|string|max:255',
            'description'           => 'required|string',
            'category_id'           => 'nullable|exists:categories,id',
            'start_date'            => 'required|date',
            'end_date'              => 'required|date',
            'location'              => 'required|string|max:255',
            'max_participants'      => 'nullable|integer|min:1',
            'registration_open'     => 'nullable|date',
            'registration_deadline' => 'nullable|date',
            'image'                 => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);
        $this->addEventTimelineValidation($validator, $request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $image = $request->file('image');
        unset($validated['image']);

        $event = Event::create([
            'organizer_id' => $request->user()->id,
            ...$validated,
        ]);

        // Handle image upload
        if ($image) {
            $this->eventImages->store($event, $image);
        }

        $this->syncAbsentSchedule($event->refresh());

        return response()->json([
            'success' => true,
            'message' => 'Event created successfully',
            'data'    => $event->load('images'),
        ], 201);
    }

    public function show(Event $event): JsonResponse
    {
        $event->loadCount(['participants' => function ($q) {
            $q->whereIn('status', ['registered', 'attended']);
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Event retrieved successfully',
            'data'    => $event->load(['organizer:id,name', 'category:id,name,slug', 'eventLinks', 'images']),
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'title'                 => 'sometimes|string|max:255',
            'description'           => 'sometimes|string',
            'category_id'           => 'sometimes|nullable|exists:categories,id',
            'start_date'            => 'sometimes|date',
            'end_date'              => 'sometimes|date',
            'location'              => 'sometimes|string|max:255',
            'max_participants'      => 'sometimes|integer|min:1',
            'registration_open'     => 'sometimes|nullable|date',
            'registration_deadline' => 'sometimes|nullable|date',
            'image'                 => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);
        $this->addEventTimelineValidation($validator, $request, $event);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $image = $request->file('image');
        unset($validated['image']);

        $event->update($validated);

        // Handle image replacement
        if ($image) {
            $this->eventImages->replace($event, $image);
        }

        $this->syncAbsentSchedule($event->refresh());

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully',
            'data'    => $event->load('images'),
        ]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $event->absentSchedule()->delete();

        // Delete associated images from storage
        $this->eventImages->deleteAllForEvent($event);

        $event->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event deleted successfully',
        ]);
    }

    public function getImages(Event $event): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Event images retrieved successfully',
            'data'    => $event->images()->get(),
        ]);
    }

    private function syncAbsentSchedule(Event $event): void
    {
        $schedule = $event->absentSchedule()->first();

        if ($schedule?->processed_at) {
            return;
        }

        $event->absentSchedule()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'run_at' => $event->end_date,
                'cancelled_at' => null,
            ],
        );
    }

    private function addEventTimelineValidation(ValidationValidator $validator, Request $request, ?Event $event = null): void
    {
        $validator->after(function (ValidationValidator $validator) use ($request, $event): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->dateInputOrExisting($request, 'start_date', $event);
            $end = $this->dateInputOrExisting($request, 'end_date', $event);
            $registrationOpen = $this->dateInputOrExisting($request, 'registration_open', $event);
            $registrationDeadline = $this->dateInputOrExisting($request, 'registration_deadline', $event);

            if ($start && $end && $end->lt($start)) {
                $validator->errors()->add('end_date', 'The end date must be after or equal to the start date.');
            }

            if ($registrationOpen && $registrationDeadline && $registrationDeadline->lt($registrationOpen)) {
                $validator->errors()->add('registration_deadline', 'The registration deadline must be after or equal to the registration open date.');
            }

            if ($registrationDeadline && $start && $registrationDeadline->gt($start)) {
                $validator->errors()->add('registration_deadline', 'The registration deadline must be before or equal to the event start date.');
            }
        });
    }

    private function dateInputOrExisting(Request $request, string $key, ?Event $event = null): ?Carbon
    {
        if ($request->exists($key)) {
            $value = $request->input($key);

            return $value === null || $value === '' ? null : Carbon::parse($value);
        }

        $value = $event?->{$key};

        return $value ? Carbon::parse($value) : null;
    }
}
