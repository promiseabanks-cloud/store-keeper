<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront\Otp;

/**
 * The fallback sender: no message leaves the server, the code goes to the PHP
 * error log so an operator can complete a sign-in while testing. It is used
 * when a customer has no email on file, when the mail transport refuses a
 * message, and by a preview host whose store option selects the `log`
 * transport.
 *
 * It keeps the last code in a short-lived transient so a preview install can
 * show it on screen. That is not a delivery channel for real shoppers.
 */
final class LogOtpSender implements OtpSender
{
    public const PREVIEW_TRANSIENT = 'storekeeper_otp_preview_';

    public function send(string $phoneE164, string $code, string $purpose, ?string $email = null): string
    {
        error_log(sprintf(
            '[Store Keeper] OTP (%s) for %s%s: %s',
            $purpose,
            $phoneE164,
            $email === null || $email === '' ? '' : ' <' . $email . '>',
            $code
        ));

        if (function_exists('set_transient')) {
            set_transient(self::PREVIEW_TRANSIENT . hash('sha256', $phoneE164), $code, 10 * MINUTE_IN_SECONDS);
        }

        return 'log';
    }

    /**
     * Only for a preview install: the code that was just "sent".
     *
     * Two switches must both be on — `WP_DEBUG` and the `otp_show_code` store
     * option — because showing a code on screen hands anyone who can type a
     * phone number that account's session. Never enable it in production.
     */
    public static function previewCode(string $phoneE164): ?string
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
