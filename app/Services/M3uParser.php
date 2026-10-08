<?php

namespace App\Services;

use Generator;
use RuntimeException;

/**
 * Streams entries out of an M3U / M3U8 (extended) playlist without loading it into memory.
 */
class M3uParser
{
    private const VOD_EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'm4v'];

    /**
     * @return Generator<int, array{name: string, url: string, logo: ?string, group: ?string, tvg_id: ?string, user_agent: ?string, referrer: ?string}>
     */
    public function parseFile(string $path): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to open playlist file: {$path}");
        }

        try {
            yield from $this->parseLines((function () use ($handle) {
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }
            })());
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  iterable<string>  $lines
     * @return Generator<int, array{name: string, url: string, logo: ?string, group: ?string, tvg_id: ?string, user_agent: ?string, referrer: ?string}>
     */
    public function parseLines(iterable $lines): Generator
    {
        $current = null;
        $first = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($first) {
                // Strip a UTF-8 BOM if present
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                $first = false;
            }

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXTINF')) {
                $current = $this->parseExtinf($line);

                continue;
            }

            if (str_starts_with($line, '#EXTGRP:') && $current !== null && $current['group'] === null) {
                $current['group'] = trim(substr($line, 8)) ?: null;

                continue;
            }

            // VLC options the stream needs, e.g. #EXTVLCOPT:http-user-agent=Mozilla/5.0
            if (str_starts_with($line, '#EXTVLCOPT:') && $current !== null) {
                [$key, $value] = array_pad(explode('=', substr($line, 11), 2), 2, '');
                $key = $this->headerKey($key);
                if ($key && trim($value) !== '') {
                    $current[$key] = trim($value);
                }

                continue;
            }

            if (str_starts_with($line, '#')) {
                continue; // #EXTM3U, #KODIPROP, ...
            }

            // A URL line: belongs to the preceding #EXTINF, or is a bare URL entry
            $entry = $current ?? ['name' => null, 'logo' => null, 'group' => null, 'tvg_id' => null, 'user_agent' => null, 'referrer' => null];
            $entry['url'] = $line;
            $entry['name'] = $entry['name'] ?: $this->nameFromUrl($line);
            $current = null;

            yield $entry;
        }
    }

    /**
     * Whether an entry looks like a movie/series (VOD) rather than a live channel.
     */
    public function isVod(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (str_contains($path, '/movie/') || str_contains($path, '/series/')) {
            return true;
        }

        return in_array(pathinfo($path, PATHINFO_EXTENSION), self::VOD_EXTENSIONS, true);
    }

    private function parseExtinf(string $line): array
    {
        $attributes = [];
        preg_match_all('/([\w-]+)="([^"]*)"/', $line, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = trim($match[2]);
        }

        // The display name follows the last comma that is outside quoted attributes
        $withoutAttributes = preg_replace('/([\w-]+)="[^"]*"/', '', $line);
        $commaPos = strrpos($withoutAttributes, ',');
        $name = $commaPos !== false ? trim(substr($withoutAttributes, $commaPos + 1)) : '';

        return [
            'name' => $name !== '' ? $name : ($attributes['tvg-name'] ?? null),
            'logo' => ($attributes['tvg-logo'] ?? '') ?: null,
            'group' => ($attributes['group-title'] ?? '') ?: null,
            'tvg_id' => ($attributes['tvg-id'] ?? '') ?: null,
            'user_agent' => ($attributes['http-user-agent'] ?? '') ?: null,
            'referrer' => ($attributes['http-referrer'] ?? $attributes['http-referer'] ?? '') ?: null,
        ];
    }

    /**
     * Map a VLC option name to the entry key it fills, or null for options we don't keep.
     */
    private function headerKey(string $option): ?string
    {
        return match (strtolower(trim($option))) {
            'http-user-agent' => 'user_agent',
            'http-referrer', 'http-referer' => 'referrer',
            default => null,
        };
    }

    private function nameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return pathinfo($path, PATHINFO_FILENAME) ?: $url;
    }
}
