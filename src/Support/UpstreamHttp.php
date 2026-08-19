<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP defaults for catalog upstreams. Guzzle already decodes gzip
 * when decode_content is enabled; the Accept-Encoding header makes the
 * compressed transfer explicit for CurseForge and Modrinth.
 */
final class UpstreamHttp
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function json(array $headers = []): PendingRequest
    {
        return Http::asJson()
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                ...$headers,
            ])
            ->withOptions(['decode_content' => true]);
    }
}
