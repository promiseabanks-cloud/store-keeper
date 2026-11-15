<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

use StoreKeeper\Commerce\Core\Exception\GatewayError;

/**
 * The production transport: wp_remote_request, so proxies, timeouts and the
 * site's HTTP filters behave as the rest of WordPress does.
 */
final class WordPressHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 20)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $this->timeoutSeconds,
        ]);

        if (is_wp_error($response)) {
            throw new GatewayError('The payment gateway could not be reached.', [
                'reason' => $response->get_error_message(),
            ]);
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
