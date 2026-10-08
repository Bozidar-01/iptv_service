<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Removes secrets from events before they leave the server (config/sentry.php `before_send`).
 * Playlist links, play links and logins carry the user's token or password in the address,
 * and provider addresses in error messages often carry the provider's username and password.
 */
class SentryScrubber
{
    public const MASK = '[hidden]';

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        if ($request = $event->getRequest()) {
            if (isset($request['url'])) {
                $request['url'] = self::url($request['url']);
            }
            // Query strings hold username / password / tokens
            unset($request['query_string'], $request['cookies'], $request['data']);
            $request['headers'] = array_diff_key($request['headers'] ?? [], array_flip(['authorization', 'cookie', 'x-xsrf-token', 'Authorization', 'Cookie', 'X-XSRF-TOKEN']));
            $event->setRequest($request);
        }

        if ($event->getMessage()) {
            $event->setMessage(self::text($event->getMessage()), $event->getMessageParams(), self::text((string) $event->getMessageFormatted()));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
        }

        return $event;
    }

    /**
     * Keep only scheme, host and the route's fixed first segment of one of our own addresses.
     */
    public static function url(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'])) {
            return self::MASK;
        }

        $path = $parts['path'] ?? '/';
        // /play/{id}/{token}, /playlist/{token}.m3u, /epg/{token}.xml, /live/{user}/{pass}/{id}, /xmltv.php ...
        if (preg_match('~^/(play|playlist|epg|live)(/|\.|$)~', $path, $m)) {
            $path = '/'.$m[1].'/'.self::MASK;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }

    /**
     * Cut every address in a message down to scheme://host, dropping login, path and query.
     */
    public static function text(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback('~\b([a-z][a-z0-9+.-]*)://[^\s"\'<>]*[^\s"\'<>.,:;)\]]~i', function ($m) {
            $host = parse_url($m[0], PHP_URL_HOST);

            return $host ? $m[1].'://'.$host.'/'.self::MASK : $m[0];
        }, $text);
    }
}
