<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront\Otp;

/**
 * The placeholder sender used until a WhatsApp/SMS provider is chosen
 * (Q-005, Q-006). It writes the code to the PHP error log so an operator can
 * complete a sign-in while testing, and keeps the last code in a short-lived
 * transient so a preview install can show it on screen.
 *
 * It is **not** a delivery channel for real shoppers: no message leaves the
 * server. That is why the sign-in page tells the shopper to ask the shop if no
 * code arrives.
 */
final class LogOtpSender implements OtpSender
{
    public const PREVIEW_TRANSIENT = 'storekeeper_otp_preview_';

    public function send(string $phoneE164, string $code, string $purpose): void
    {
        error_log(sprintf('[Store Keeper] OTP (%s) for %s: %s', $purpose, $phoneE164, $code));

        if (function_exists('set_transient')) {
            set_transient(self::PREVIEW_TRANSIENT . hash('sha256', $phoneE164), $code, 10 * MINUTE_IN_SECONDS);
        }
    }

    /**
     * Only for a preview install: the code that was just "sent".
     *
     * Two switches must both be on — `WP_DEBUG` and the `otp_show_code` store
     * option — because showing a code on screen hands anyone who can type a
     * phone number that account's session. Never enable it in production.
     */
    public function previewCode(string $phoneE164): ?string
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG || !function_exists('get_option')) {
            return null;
        }

        if (!get_option(\StoreKeeper\Commerce\Core\Brand::OPTION_PREFIX . 'otp_show_code')) {
            return null;
        }

        $code = get_transient(self::PREVIEW_TRANSIENT . hash('sha256', $phoneE164));

        return is_string($code) && $code !== '' ? $code : null;
    }
}
