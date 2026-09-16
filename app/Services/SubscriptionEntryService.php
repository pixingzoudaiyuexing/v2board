<?php

namespace App\Services;

use InvalidArgumentException;

class SubscriptionEntryService
{
    public function entries(): array
    {
        $configured = config('v2board.subscribe_url');
        if ($configured === null || $configured === '') {
            return [];
        }
        if (!is_string($configured)) {
            throw new InvalidArgumentException('Invalid subscription entry configuration');
        }

        $entries = [];
        $seen = [];
        foreach (explode(',', $configured) as $item) {
            $url = trim($item);
            if ($url === '') {
                continue;
            }

            $parts = parse_url($url);
            if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false
                || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || $parts['host'] === ''
                || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
                || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
                throw new InvalidArgumentException('Invalid subscription entry configuration');
            }

            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $entries[] = ['base_url' => $url];
            }
        }

        return $entries;
    }
}
