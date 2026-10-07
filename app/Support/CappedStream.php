<?php

namespace App\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that keeps only the first bytes of a download.
 *
 * Once full, write() reports a short write, which makes curl abort the transfer.
 * That stops live streams (which never end) after the first few KB.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct(private int $limit)
    {
        $this->clear();
    }

    public function write($string): int
    {
        $room = $this->limit - (int) $this->stream->getSize();

        return $room > 0 ? $this->stream->write(substr($string, 0, $room)) : 0;
    }

    /**
     * Drop what was written so far (the body of a redirect before the real response).
     */
    public function clear(): void
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'w+'));
    }

    public function contents(): string
    {
        $this->stream->rewind();

        return $this->stream->getContents();
    }
}
