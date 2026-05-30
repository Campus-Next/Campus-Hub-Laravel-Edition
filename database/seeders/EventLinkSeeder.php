<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventLink;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class EventLinkSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        foreach (Event::query()->select('id')->cursor() as $event) {
            $templates = [
                ['Google Form', 'https://forms.gle/' . fake()->regexify('[A-Za-z0-9]{15}')],
                ['Instagram Official', 'https://instagram.com/' . fake()->userName()],
                ['Linktree', 'https://linktr.ee/' . fake()->userName()],
                ['Tokopedia (Merchandise)', 'https://tokopedia.com/toko-' . fake()->domainWord()],
                ['Shopee (Tiket & Merch)', 'https://shopee.co.id/' . fake()->userName()],
                ['WhatsApp Admin', 'https://wa.me/' . fake()->numerify('6281#########')],
                ['Website Resmi', 'https://www.' . fake()->domainName()],
                ['TikTok', 'https://tiktok.com/@' . fake()->userName()],
                ['X (Twitter)', 'https://x.com/' . fake()->userName()],
            ];

            foreach ($templates as [$title, $url]) {
                EventLink::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'title' => $title,
                    ],
                    [
                        'url' => $url,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }
}
