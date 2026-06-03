<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventAttachmentService
{
    public function store(Event $event, UploadedFile $file): void
    {
        $extension = trim($file->getClientOriginalExtension());
        $filename = (string) Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $path = $file->storeAs('event_attachments', $filename, 'public');

        $event->forceFill([
            'attachment_path' => $path,
            'attachment_name' => $file->getClientOriginalName(),
        ])->save();
    }

    public function delete(Event $event): void
    {
        if ($event->attachment_path) {
            Storage::disk('public')->delete($event->attachment_path);
        }

        $event->forceFill([
            'attachment_path' => null,
            'attachment_name' => null,
        ])->save();
    }

    public function replace(Event $event, UploadedFile $file): void
    {
        if ($event->attachment_path) {
            Storage::disk('public')->delete($event->attachment_path);
        }

        $this->store($event, $file);
    }
}
