<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Image;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventImageService
{
    public function __construct(private readonly ClamdService $clamd)
    {
    }

    public function store(Event $event, UploadedFile $file): Image
    {
        $this->clamd->scanUploadedFile($file, [
            'event_id' => $event->id,
            'field' => 'image',
            'operation' => 'store',
        ]);

        $extension = trim($file->getClientOriginalExtension());
        $filename = (string) Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $path = $file->storeAs('events', $filename, 'public');

        return Image::create([
            'event_id' => $event->id,
            'path' => $path,
        ]);
    }

    public function delete(Image $image): void
    {
        Storage::disk('public')->delete($image->path);
        $image->delete();
    }

    public function deleteAllForEvent(Event $event): void
    {
        $event->images()->get()->each(fn (Image $image) => $this->delete($image));
    }

    public function replace(Event $event, UploadedFile $file): Image
    {
        $this->clamd->scanUploadedFile($file, [
            'event_id' => $event->id,
            'field' => 'image',
            'operation' => 'replace',
        ]);

        $this->deleteAllForEvent($event);

        $extension = trim($file->getClientOriginalExtension());
        $filename = (string) Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $path = $file->storeAs('events', $filename, 'public');

        return Image::create([
            'event_id' => $event->id,
            'path' => $path,
        ]);
    }
}
