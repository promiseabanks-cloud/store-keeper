<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront\Otp;

/**
 * How a one-time code reaches the shopper.
 *
 * `EmailOtpSender` is the implementation a store uses today: the code goes to
 * the email address on the customer's record. The interface also carries the
 * phone number and the purpose, so a WhatsApp or SMS adapter (Q-005, Q-006) can
 * replace one binding without touching sign-in, sign-up or the code rules.
 */
interface OtpSender
{
    /**
     * @param string      $phoneE164 the number being verified
     * @param string      $email     the address to email, when the store has one
     * @param string      $purpose   `login` or `register`
     *
     * @return string the channel that carried the code: `email` when it was
     *                handed to the mail transport, `log` when it only reached
     *                the error log. The pages say where it went from this.
     */
    public function send(string $phoneE164, string $code, string $purpose, ?string $email = null): string;
}
