<?php

namespace App\Http\Controllers;

use App\Exceptions\ClamdUnavailableException;
use App\Exceptions\MalwareDetectedException;
use App\Models\Event;
use App\Models\Image;
use App\Services\EventImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    public function __construct(private readonly EventImageService $eventImages)
    {
    }

    public function show(string $id) {
        $image = Image::where('id', $id)->first();
        
        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found'
            ], 404);
        }

        $path = storage_path('app/public/' . $image->path);
        
        if (!file_exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Image file not found'
            ], 404);
        }
        
        return response()->download($path);
    }

    public function upload(Request $request, Event $event): JsonResponse
    {
        if ($response = $this->denyUnlessEventOrganizer($request, $event)) {
            return $response;
        }

        $request->validate([
            'image' => 'required|file',
        ]);

        if ($request->hasFile('image')) {
            try {
                $image = $this->eventImages->store($event, $request->file('image'));
            } catch (MalwareDetectedException $exception) {
                return $this->malwareDetectedResponse($exception);
            } catch (ClamdUnavailableException $exception) {
                return $this->clamdUnavailableResponse($exception);
            }

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => $image
            ], 201);
        }

        return response()->json([
            'success' => false,
            'message' => 'Upload failed'
        ], 400);
    }

    private function malwareDetectedResponse(MalwareDetectedException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Malware detected',
            'detail' => $exception->scanResult(),
            'quarantine_path' => $exception->quarantinePath(),
        ], 400);
    }

    private function clamdUnavailableResponse(ClamdUnavailableException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'ClamAV scanner unavailable',
            'detail' => $exception->getMessage(),
        ], 503);
    }

    public function destroy(Request $request, string $id)
    {
        $image = Image::with('event')->where('id', $id)->first();

        if (!$image || !$image->event) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found'
            ], 404);
        }

        if ($response = $this->denyUnlessEventOrganizer($request, $image->event)) {
            return $response;
        }

        $this->eventImages->delete($image);

        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully'
        ], 200);
    }
}
