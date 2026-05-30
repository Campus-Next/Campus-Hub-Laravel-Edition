<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $carts = $request->user()
            ->carts()
            ->with('event:id,title,start_date,end_date,location,registration_fee')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Cart retrieved successfully',
            'data' => $carts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $cart = DB::transaction(function () use ($request) {
            return $request->user()->carts()->lockForUpdate()
                ->firstOrCreate(['event_id' => $request->event_id]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Event added to cart',
            'data' => $cart->load('event:id,title,start_date,end_date,location,registration_fee'),
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $cart = $request->user()->carts()->find($id);

        if (!$cart) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found',
            ], 404);
        }

        $cart->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart item removed successfully',
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();

        $result = DB::transaction(function () use ($user) {
            $carts = $user->carts()->lockForUpdate()->get();

            if ($carts->isEmpty()) {
                return ['empty' => true];
            }

            $eventsById = Event::query()
                ->whereKey($carts->pluck('event_id')->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $enrolled = [];
            $skipped = [];
            $now = now();

            foreach ($carts as $cart) {
                $event = $eventsById->get($cart->event_id);
                if (!$event) {
                    $skipped[] = ['event_id' => $cart->event_id, 'reason' => 'Event no longer exists'];
                    continue;
                }

                $participant = $event->participants()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($participant && $participant->status !== 'cancelled') {
                    $skipped[] = ['event_id' => $event->id, 'reason' => 'Already enrolled'];
                    continue;
                }

                if ($event->registration_open && $now->lt($event->registration_open)) {
                    $skipped[] = ['event_id' => $event->id, 'reason' => 'Registration has not opened'];
                    continue;
                }

                if ($event->registration_deadline && $now->gt($event->registration_deadline)) {
                    $skipped[] = ['event_id' => $event->id, 'reason' => 'Registration has closed'];
                    continue;
                }

                if ($event->max_participants > 0) {
                    $current = $event->participants()->whereIn('status', ['registered', 'attended'])->count();
                    if ($current >= $event->max_participants) {
                        $skipped[] = ['event_id' => $event->id, 'reason' => 'Maximum participants reached'];
                        continue;
                    }
                }

                if ($participant) {
                    $participant->update([
                        'status' => 'registered',
                        'unique_code' => EventParticipant::generateUniqueCode($event),
                        'cancelled_at' => null,
                    ]);

                    $enrolled[] = $participant->fresh();
                    continue;
                }

                $enrolled[] = $event->participants()->create([
                    'user_id' => $user->id,
                    'status' => 'registered',
                    'unique_code' => EventParticipant::generateUniqueCode($event),
                    'cancelled_at' => null,
                ]);
            }

            $user->carts()->delete();

            return ['enrolled' => $enrolled, 'skipped' => $skipped];
        });

        if (!empty($result['empty'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Checkout completed',
            'data' => [
                'enrolled' => $result['enrolled'],
                'skipped' => $result['skipped'],
            ],
        ]);
    }
}
