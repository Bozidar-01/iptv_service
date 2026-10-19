<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Carbon;

/**
 * One message to every viewer (Playlist options → Message to viewers): shown by Xtream apps (TiviMate, IPTV Smarters…) from the
 * login's `message` field, and at the top of every viewer's own page. It can end by itself on a date.
 */
class AnnouncementService
{
    public const KEY = 'announcement';

    public const MAX_LENGTH = 300;

    /** @return array{text: string, until: ?string} */
    public function settings(): array
    {
        $saved = (array) AppSetting::get(self::KEY, []);

        return ['text' => (string) ($saved['text'] ?? ''), 'until' => isset($saved['until']) && $saved['until'] !== '' ? (string) $saved['until'] : null];
    }

    public function save(?string $text, ?string $until): void
    {
        AppSetting::put(self::KEY, ['text' => trim((string) $text), 'until' => $until ?: null]);
    }

    /** The message to show now, or null (none, or its last day has passed). */
    public function current(): ?string
    {
        $settings = $this->settings();
        if ($settings['text'] === '') {
            return null;
        }
        if ($settings['until'] !== null && Carbon::parse($settings['until'])->endOfDay()->isPast()) {
            return null;
        }

        return $settings['text'];
    }
}
