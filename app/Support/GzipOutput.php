<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Compresses a streamed response (playlists, channel lists, TV guides) for apps that accept gzip: a 10 MB playlist becomes about
 * 1 MB, so apps load it several times faster. Off when PHP already compresses its output (zlib.output_compression).
 */
final class GzipOutput
{
    /** @var \DeflateContext|null */
    private $context = null;

    public function __construct(Request $request)
    {
        if (function_exists('deflate_init') && !ini_get('zlib.output_compression')
            && str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip')) {
            $this->context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 5]);
        }
    }

    /** Headers to add to the response. */
    public function headers(): array
    {
        return $this->context ? ['Content-Encoding' => 'gzip', 'Vary' => 'Accept-Encoding'] : ['Vary' => 'Accept-Encoding'];
    }

    public function write(string $chunk): void
    {
        echo $this->context ? deflate_add($this->context, $chunk, ZLIB_NO_FLUSH) : $chunk;
    }

    /** Call once at the end. */
    public function finish(): void
    {
        if ($this->context) {
            echo deflate_add($this->context, '', ZLIB_FINISH);
        }
    }
}
