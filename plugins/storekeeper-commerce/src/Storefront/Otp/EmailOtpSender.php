<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront\Otp;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Notifications\Channel;
use StoreKeeper\Commerce\Notifications\ChannelFailed;

/**
 * Delivers a one-time code by email (§7.4, §22.5).
 *
 * The address is the one on the customer's record (or the one typed at sign-up),
 * so no provider account is needed: the message goes through the same
 * `email` channel the order notifications use. A customer with no address on
 * file, or a mail transport that refuses the message, falls back to
 * `LogOtpSender` so the code is never silently lost; the sign-in page tells the
 * shopper only what actually happened.
 */
final class EmailOtpSender implements OtpSender
{
    public function __construct(
        private readonly Channel $email,
        private readonly OtpSender $fallback,
    ) {
    }

    public function send(string $phoneE164, string $code, string $purpose, ?string $email = null): string
    {
        $to = $email === null ? '' : trim($email);

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fallback->send($phoneE164, $code, $purpose);
        }

        try {
            $this->email->send($to, $this->subject($purpose), $this->body($purpose, $code));

            return 'email';
        } catch (ChannelFailed $error) {
            error_log(sprintf('[Store Keeper] OTP email to %s failed: %s', $to, $error->getMessage()));

            // The address is already on the log line above; the fallback is told
            // the phone only, so it reports itself as the log channel.
            return $this->fallback->send($phoneE164, $code, $purpose);
        }
    }

    private function subject(string $purpose): string
    {
        return sprintf(
            '%s: your %s code',
            Brand::NAME,
            $purpose === 'register' ? 'sign-up' : 'sign-in'
        );
    }

    private function body(string $purpose, string $code): string
    {
        return sprintf(
            "Hello,\n\n"
            . "Your %s code is %s. It works once and expires in %d minutes.\n\n"
            . "If you did not ask for this code, you can ignore this email.\n",
            $purpose === 'register' ? 'sign-up' : 'sign-in',
            $code,
            10
        );
    }
}
