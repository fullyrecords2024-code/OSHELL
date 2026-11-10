<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PesapalGateway
{
    public function isConfigured(): bool
    {
        return filled(config('services.pesapal.consumer_key'))
            && filled(config('services.pesapal.consumer_secret'))
            && $this->isHttpsUrl(config('services.pesapal.callback_url'))
            && (filled(config('services.pesapal.ipn_id')) || $this->isHttpsUrl(config('services.pesapal.ipn_url')))
            && $this->isHttpsUrl(config('services.pesapal.base_url'));
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function initiate(array $order, array $customer, string $merchantReference): array
    {
        $this->assertConfigured();
        $notificationId = $this->notificationId();
        $nameParts = preg_split('/\s+/', trim((string) ($customer['name'] ?? 'Customer')), 2) ?: ['Customer'];
        $payload = [
            'id' => $merchantReference,
            'currency' => $order['currency_code'],
            'amount' => number_format((float) $order['total_amount'], 2, '.', ''),
            'description' => 'Oshel order '.$order['order_number'],
            'callback_url' => config('services.pesapal.callback_url'),
            'notification_id' => $notificationId,
            'billing_address' => [
                'email_address' => $customer['email'] ?? '',
                'phone_number' => $customer['phone'] ?? '',
                'country_code' => 'KE',
                'first_name' => $nameParts[0] ?? 'Customer',
                'last_name' => $nameParts[1] ?? '',
            ],
        ];
        $response = $this->request('post', '/Transactions/SubmitOrderRequest', $payload);
        $redirectUrl = $response['redirect_url'] ?? null;
        $trackingId = $response['order_tracking_id'] ?? null;
        $redirectHost = is_string($redirectUrl) ? parse_url($redirectUrl, PHP_URL_HOST) : null;
        if (! is_string($redirectUrl)
            || ! is_string($trackingId)
            || ! filter_var($redirectUrl, FILTER_VALIDATE_URL)
            || ! is_string($redirectHost)
            || ! (str_ends_with($redirectHost, '.pesapal.com') || $redirectHost === 'pesapal.com')
            || ! str_starts_with($redirectUrl, 'https://')) {
            throw new PaymentGatewayException('The card provider returned an invalid checkout response.');
        }

        return [
            'redirect_url' => $redirectUrl,
            'provider_transaction_id' => $trackingId,
            'provider_reference' => $response['merchant_reference'] ?? $merchantReference,
            'checkout_request_id' => null,
        ];
    }

    /**
     * @return array{status: string, amount: ?float, currency_code: ?string, provider_reference: ?string}
     */
    public function verify(string $trackingId): array
    {
        $this->assertConfigured();
        $status = $this->request(
            'get',
            '/Transactions/GetTransactionStatus?orderTrackingId='.rawurlencode($trackingId),
            null,
        );
        $code = (int) ($status['status_code'] ?? 0);

        return [
            'status' => match ($code) {
                1 => 'paid',
                2 => 'failed',
                3 => 'reversed',
                default => 'pending',
            },
            'amount' => isset($status['amount']) && is_numeric($status['amount']) ? (float) $status['amount'] : null,
            'currency_code' => isset($status['currency']) ? (string) $status['currency'] : null,
            'provider_reference' => isset($status['confirmation_code']) ? (string) $status['confirmation_code'] : null,
        ];
    }

    private function notificationId(): string
    {
        $configuredId = config('services.pesapal.ipn_id');
        if (filled($configuredId)) {
            return (string) $configuredId;
        }
        $url = config('services.pesapal.ipn_url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            throw new PaymentGatewayException('Pesapal requires a public HTTPS IPN URL or a registered IPN ID.');
        }

        return Cache::rememberForever(
            'payments.pesapal.ipn.'.hash('sha256', $url),
            function () use ($url): string {
                $response = $this->request('post', '/URLSetup/RegisterIPN', [
                    'url' => $url,
                    'ipn_notification_type' => 'GET',
                ]);
                $id = $response['ipn_id'] ?? null;
                if (! is_string($id) || $id === '') {
                    throw new PaymentGatewayException('Pesapal did not return a registered IPN ID.');
                }

                return $id;
            },
        );
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new PaymentGatewayException('Pesapal is not configured. Add the server-side credentials and callback URLs.');
        }
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $token = Cache::remember(
            'payments.pesapal.token.'.hash('sha256', (string) config('services.pesapal.base_url').'|'.(string) config('services.pesapal.consumer_key')),
            3300,
            function (): string {
                $response = $this->send('post', '/Auth/RequestToken', [
                    'consumer_key' => config('services.pesapal.consumer_key'),
                    'consumer_secret' => config('services.pesapal.consumer_secret'),
                ], null);
                $token = $response['token'] ?? null;
                if (! is_string($token) || $token === '') {
                    throw new PaymentGatewayException('Pesapal authentication returned no access token.');
                }

                return $token;
            },
        );

        return $this->send($method, $path, $payload, $token);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload, ?string $token): array
    {
        $baseUrl = rtrim((string) config('services.pesapal.base_url'), '/');
        try {
            $request = Http::acceptJson()->asJson()->timeout(20);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $request->{$method}($baseUrl.$path, $payload ?? []);
        } catch (ConnectionException|RequestException $exception) {
            throw new PaymentGatewayException('Pesapal could not be reached. The payment remains unconfirmed.', previous: $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new PaymentGatewayException('Pesapal returned an unsuccessful or invalid response.');
        }

        return $response->json();
    }
}
