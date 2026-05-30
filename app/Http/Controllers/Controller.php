<?php

namespace App\Http\Controllers;

use App\Models\Event;
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
}
