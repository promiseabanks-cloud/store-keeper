<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Customers\StorefrontAuth;

/**
 * "Continue with Google" for storefront customers (§11.1).
 *
 * The standard OAuth 2.0 authorization-code flow: we send the shopper to Google,
 * Google sends them back with a code, and we swap that code for an id_token on
 * the server, over TLS, straight from Google. The id_token is a JWT whose
 * claims (subject, email, name) we read to find or create the customer.
 *
 * Because the token is fetched server-to-server from Google's own endpoint we
 * trust its contents after checking the audience, issuer and expiry; we do not
 * re-verify the signature against Google's keys. A customer is linked by Google
 * subject id first, then by a matching email, and otherwise a new retail account
 * is created — wholesale still registers through the form so a business name and
 * approval can be captured.
 *
 * The client id and secret are the owner's; until they are set the button is
 * hidden and this class reports itself not configured.
 */
final class GoogleAuth
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerService $service,
        private readonly StorefrontAuth $sessions,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /** The exact URL to register as an authorized redirect URI in Google Cloud. */
    public function redirectUri(): string
    {
        return add_query_arg('action', 'storekeeper_google_callback', admin_url('admin-post.php'));
    }

    /** Where to send the shopper to choose their Google account. */
    public function authorizationUrl(string $state): string
    {
        return add_query_arg(
            [
                'client_id' => rawurlencode($this->clientId()),
                'redirect_uri' => rawurlencode($this->redirectUri()),
                'response_type' => 'code',
                'scope' => rawurlencode('openid email profile'),
                'state' => rawurlencode($state),
                'access_type' => 'online',
                'prompt' => 'select_account',
            ],
            self::AUTH_ENDPOINT
        );
    }

    /**
     * Swap the authorization code for the shopper's verified Google claims.
     *
     * @return array{sub:string,email:string,name:string}
     */
    public function exchange(string $code): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationFailed('Google sign-in is not set up for this shop.');
        }

        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 15,
            'body' => [
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new ValidationFailed('We could not reach Google. Please try again.');
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $idToken = is_array($body) && isset($body['id_token']) ? (string) $body['id_token'] : '';

        if ($idToken === '') {
            throw new ValidationFailed('Google did not confirm your sign-in. Please try again.');
        }

        return $this->claims($idToken);
    }

    /**
     * Find or create the customer for a set of Google claims and open a session.
     *
     * @param array{sub:string,email:string,name:string} $claims
     * @return array{token:string, customer:array<string,mixed>}
     */
    public function signIn(array $claims, ?string $ip = null, ?string $userAgent = null): array
    {
        $customer = $this->customers->customerByGoogleSub($claims['sub']);

        if ($customer === null && $claims['email'] !== '') {
            $byEmail = $this->customers->customerByEmail($claims['email']);

            if ($byEmail !== null) {
                $this->customers->setGoogleSub((int) $byEmail['id'], $claims['sub']);
                $customer = $this->customers->customer((int) $byEmail['id']);
            }
        }

        if ($customer === null) {
            $default = $this->customers->defaultGroup();

            if ($default === null) {
                throw new ValidationFailed('This shop is not taking new accounts right now.');
            }

            $customer = $this->service->create(
                [
                    'name' => $claims['name'] !== '' ? $claims['name'] : $this->nameFromEmail($claims['email']),
                    'email' => $claims['email'],
                    'group_id' => (int) $default['id'],
                    'google_sub' => $claims['sub'],
                ],
                Actor::system(),
                'storefront'
            );
        }

        if (($customer['approval_status'] ?? 'approved') === 'rejected') {
            throw new ValidationFailed('This account cannot sign in. Please contact the shop.');
        }

        return [
            'token' => $this->sessions->openSession((int) $customer['id'], $ip, $userAgent),
            'customer' => $customer,
        ];
    }

    /**
     * Read and sanity-check the claims in a Google id_token.
     *
     * @return array{sub:string,email:string,name:string}
     */
    private function claims(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new ValidationFailed('Google sent an unreadable sign-in. Please try again.');
        }

        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);

        if (!is_array($payload)) {
            throw new ValidationFailed('Google sent an unreadable sign-in. Please try again.');
        }

        $issuer = (string) ($payload['iss'] ?? '');
        $audience = (string) ($payload['aud'] ?? '');
        $expires = (int) ($payload['exp'] ?? 0);

        $issuerOk = in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true);

        if (!$issuerOk || $audience !== $this->clientId() || $expires < time()) {
            throw new ValidationFailed('That Google sign-in could not be verified. Please try again.');
        }

        $sub = (string) ($payload['sub'] ?? '');
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = ($payload['email_verified'] ?? false) === true
            || ($payload['email_verified'] ?? '') === 'true';

        if ($sub === '') {
            throw new ValidationFailed('Google did not return an account id. Please try again.');
        }

        return [
            'sub' => $sub,
            'email' => $emailVerified && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'name' => trim((string) ($payload['name'] ?? '')),
        ];
    }

    private function nameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0] ?? '';
        $local = trim(str_replace(['.', '_', '-'], ' ', $local));

        return $local === '' ? 'Customer' : ucwords($local);
    }

    private function clientId(): string
    {
        return trim((string) get_option(Brand::OPTION_PREFIX . 'google_client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) get_option(Brand::OPTION_PREFIX . 'google_client_secret', ''));
    }
}
