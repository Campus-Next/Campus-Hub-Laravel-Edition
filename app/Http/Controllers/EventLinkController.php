<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EventLinkController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Event links retrieved successfully',
            'data' => $event->eventLinks,
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'url' => 'required|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $link = $event->eventLinks()->create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Event link created successfully',
            'data' => $link,
        ], 201);
    }

    public function update(Request $request, Event $event, $linkId): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $link = $event->eventLinks()->find($linkId);

        if (!$link) {
            return response()->json([
                'success' => false,
                'message' => 'Event link not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'url' => 'sometimes|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $link->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Event link updated successfully',
            'data' => $link,
        ]);
    }

    public function destroy(Request $request, Event $event, $linkId): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $link = $event->eventLinks()->find($linkId);

        if (!$link) {
            return response()->json([
                'success' => false,
                'message' => 'Event link not found',
            ], 404);
        }

        $link->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event link deleted successfully',
        ]);
    }

}
