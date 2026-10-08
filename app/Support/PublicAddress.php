<?php

namespace App\Support;

/**
 * Tells whether an address points to the public internet. The stream proxy fetches addresses that
 * come from provider playlists, so without this a playlist could aim a channel at the server's own
 * network (127.0.0.1, the database, cloud metadata at 169.254.169.254) and viewers would read the answer.
 * Set PROXY_ALLOW_PRIVATE=true when a provider really is on your local network.
 */
class PublicAddress
{
    public static function allows(string $url): bool
    {
        if (config('iptv.proxy_allow_private')) {
            return true;
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '' || strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.localhost')) {
            return false;
        }

        // An IP written out, or every address the name resolves to
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        // A name that doesn't resolve is let through: fetching it fails by itself
        return true;
    }
}
