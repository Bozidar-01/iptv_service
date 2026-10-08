<?php

namespace App\Services;

use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\EpgSource;
use App\Models\Source;
use App\Support\SentryScrubber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use XMLReader;

/**
 * Imports an XMLTV guide (plain or .gz) without loading it into memory: guide channels into
 * epg_channels (for matching, see EpgService) and programmes into epg_programmes.
 * Only programmes inside the guide window are kept (iptv.epg_days ahead, 6 hours back).
 * The source's old data is replaced in one transaction, so the guide is never half empty.
 */
class EpgImportService
{
    private const CHUNK_SIZE = 1000;

    /** Filler entries some guides use when they have no data: not worth showing. */
    private const PLACEHOLDER_TITLES = '/^(no data|no information|no programme information|to be announced|tba|n\/a)$/i';

    public function sync(EpgSource $source): void
    {
        $source->update(['status' => Source::STATUS_SYNCING, 'last_error' => null]);
        $path = null;

        try {
            $path = $this->download($source->url);
            [$channels, $programmes] = $this->import($source, $path);

            $source->update([
                'status' => Source::STATUS_SUCCESS,
                'channel_count' => $channels,
                'programme_count' => $programmes,
                'last_synced_at' => now(),
            ]);
        } catch (Throwable $e) {
            $source->update(['status' => Source::STATUS_FAILED, 'last_error' => mb_substr(SentryScrubber::text($e->getMessage()), 0, 1000)]);

            throw $e;
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    private function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epg');
        $response = Http::timeout(300)->withUserAgent('NeboTV')->sink($path)->get($url);

        if ($response->failed()) {
            @unlink($path);
            throw new RuntimeException("Download failed with HTTP {$response->status()}.");
        }

        return $path;
    }

    /**
     * @return array{0: int, 1: int} channels and programmes stored
     */
    public function import(EpgSource $source, string $path): array
    {
        $handle = fopen($path, 'rb');
        $gzip = fread($handle, 2) === "\x1f\x8b";
        fclose($handle);

        $reader = new XMLReader;
        if (!$reader->open(($gzip ? 'compress.zlib://' : '').$path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The guide is not a readable XMLTV file.');
        }

        $from = now()->subHours(6);
        $until = now()->addDays((int) config('iptv.epg_days'));
        $channels = $programmes = 0;

        try {
            DB::transaction(function () use ($reader, $source, $from, $until, &$channels, &$programmes) {
                EpgProgramme::where('epg_source_id', $source->id)->delete();
                EpgChannel::where('epg_source_id', $source->id)->delete();

                $channelRows = $programmeRows = [];
                $seen = [];
                $sawTv = false;

                while (@$reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT) {
                        continue;
                    }

                    if ($reader->name === 'tv') {
                        $sawTv = true;
                    } elseif ($reader->name === 'channel') {
                        $node = @simplexml_load_string($reader->readOuterXml());
                        $id = trim((string) ($node['id'] ?? ''));
                        // Ids differing only in case count as one (MySQL compares them case-insensitively)
                        if ($id === '' || isset($seen[mb_strtolower($id)])) {
                            continue;
                        }
                        $seen[mb_strtolower($id)] = true;
                        $name = trim((string) ($node->{'display-name'}[0] ?? $id));
                        $channelRows[] = [
                            'epg_source_id' => $source->id,
                            'xmltv_id' => mb_substr($id, 0, 255),
                            'name' => mb_substr($name, 0, 255),
                            'name_key' => mb_substr(EpgService::nameKey($name), 0, 255),
                        ];
                        if (count($channelRows) >= self::CHUNK_SIZE) {
                            EpgChannel::insert($channelRows);
                            $channels += count($channelRows);
                            $channelRows = [];
                        }
                    } elseif ($reader->name === 'programme') {
                        $start = self::parseTime($reader->getAttribute('start'));
                        $stop = self::parseTime($reader->getAttribute('stop'));
                        $channel = trim((string) $reader->getAttribute('channel'));
                        if (!$start || !$channel || $start->gt($until) || ($stop ?? $start)->lt($from)) {
                            continue;
                        }
                        $node = @simplexml_load_string($reader->readOuterXml());
                        $title = trim((string) ($node->title[0] ?? ''));
                        if ($title === '' || preg_match(self::PLACEHOLDER_TITLES, $title)) {
                            continue;
                        }
                        $description = trim((string) ($node->desc[0] ?? ''));
                        $programmeRows[] = [
                            'epg_source_id' => $source->id,
                            'xmltv_id' => mb_substr($channel, 0, 255),
                            'start' => $start->utc()->toDateTimeString(),
                            'stop' => ($stop ?? $start->copy()->addHour())->utc()->toDateTimeString(),
                            'title' => mb_substr($title, 0, 500),
                            'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
                        ];
                        if (count($programmeRows) >= self::CHUNK_SIZE) {
                            EpgProgramme::insert($programmeRows);
                            $programmes += count($programmeRows);
                            $programmeRows = [];
                        }
                    }
                }

                if (!$sawTv) {
                    throw new RuntimeException('The guide is not a valid XMLTV file.');
                }

                if ($channelRows) {
                    EpgChannel::insert($channelRows);
                    $channels += count($channelRows);
                }
                if ($programmeRows) {
                    EpgProgramme::insert($programmeRows);
                    $programmes += count($programmeRows);
                }
            });
        } finally {
            $reader->close();
        }

        return [$channels, $programmes];
    }

    /**
     * XMLTV time: "20261007200000 +0200" (offset optional, then UTC).
     */
    public static function parseTime(?string $value): ?Carbon
    {
        if (!$value || !preg_match('/^(\d{14})(?:\s*([+-]\d{4}))?/', trim($value), $m)) {
            return null;
        }

        return Carbon::createFromFormat('YmdHis O', $m[1].' '.($m[2] ?? '+0000'));
    }
}
