<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

/**
 * The device-token permission shared by the POS and sync routes (§15.1, D-041).
 *
 * A valid token identifies the device; the guard then counts the request against
 * that device's minute budget. Failed token attempts are counted per client IP,
 * so a brute-force loop is stopped before it can walk the token space, without
 * throttling honest devices behind a shared shop connection.
 */
final class DeviceGuard
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** Permission callback for every device-token route. */
    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $device = $this->devices->authenticate((string) $request->get_header('device'));

        if ($device === null) {
            $limited = $this->authFailure($ip);

            if ($limited !== null) {
                return $this->tooMany($limited, 'Too many failed device sign-ins. Try again shortly.');
            }

            return new \WP_Error('invalid_device', 'This device is not registered or was revoked.', ['status' => 401]);
        }

        $throttled = $this->deviceRequest((int) $device['id']);

        if ($throttled !== null) {
            return $this->tooMany($throttled, 'This device is sending requests too quickly. Wait a moment and retry.');
        }

        $request->set_param('_device', $device);

        return true;
    }

    /**
     * Count one bad token for a client IP.
     *
     * @return array{allowed:bool,limit:int,remaining:int,retry_after:int,reset_at:string}|null null when still allowed
     */
    public function authFailure(string $ip): ?array
    {
        $result = $this->limiter->hit(
            'pos:auth-fail:' . substr($ip, 0, 100),
            RateLimiter::AUTH_FAILURES_PER_MINUTE
        );

        return $result['allowed'] ? null : $result;
    }

    /**
     * Count one request against a device's minute budget.
     *
     * @return array{allowed:bool,limit:int,remaining:int,retry_after:int,reset_at:string}|null null when still allowed
     */
    public function deviceRequest(int $deviceId): ?array
    {
        $result = $this->limiter->hit('pos:device:' . $deviceId, RateLimiter::DEVICE_REQUESTS_PER_MINUTE);

        return $result['allowed'] ? null : $result;
    }

    /** @param array{retry_after:int,limit:int} $result */
    private function tooMany(array $result, string $message): \WP_Error
    {
        return new \WP_Error('rate_limited', $message, [
            'status' => 429,
            'retry_after' => $result['retry_after'],
            'limit' => $result['limit'],
            'remaining' => 0,
        ]);
    }
}
