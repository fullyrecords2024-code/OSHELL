<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MpesaDarajaGateway
{
    public function isConfigured(): bool
    {
        return filled(config('services.mpesa.consumer_key'))
            && filled(config('services.mpesa.consumer_secret'))
            && filled(config('services.mpesa.shortcode'))
            && filled(config('services.mpesa.passkey'))
            && $this->isHttpsUrl(config('services.mpesa.callback_url'))
            && $this->isHttpsUrl(config('services.mpesa.base_url'));
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function initiate(array $order, string $phone, string $merchantReference): array
    {
        $this->assertConfigured();
        $amountCents = (int) round((float) $order['total_amount'] * 100);
        if ($amountCents % 100 !== 0) {
            throw new PaymentGatewayException('M-Pesa STK Push supports whole-KES amounts only; this order total contains cents.');
        }
        $shortcode = (string) config('services.mpesa.shortcode');
        $timestamp = now('Africa/Nairobi')->format('YmdHis');
        $response = $this->request('post', '/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $shortcode,
            'Password' => base64_encode($shortcode.(string) config('services.mpesa.passkey').$timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) ($amountCents / 100),
            'PartyA' => $this->normalizePhone($phone),
            'PartyB' => $shortcode,
            'PhoneNumber' => $this->normalizePhone($phone),
            'CallBackURL' => config('services.mpesa.callback_url'),
            'AccountReference' => substr($merchantReference, 0, 12),
            'TransactionDesc' => 'Oshel order payment',
        ]);
        if ((string) ($response['ResponseCode'] ?? '') !== '0'
            || ! filled($response['CheckoutRequestID'] ?? null)
            || ! filled($response['MerchantRequestID'] ?? null)) {
            throw new PaymentGatewayException('M-Pesa did not accept the STK Push request.');
        }

        return [
            'redirect_url' => null,
            'provider_transaction_id' => (string) $response['MerchantRequestID'],
            'provider_reference' => (string) $response['MerchantRequestID'],
            'checkout_request_id' => (string) $response['CheckoutRequestID'],
        ];
    }

    /**
     * @return array{status: string, amount: ?float, currency_code: ?string, provider_reference: ?string}
     */
    public function verify(string $checkoutRequestId): array
    {
        $this->assertConfigured();
        $shortcode = (string) config('services.mpesa.shortcode');
        $timestamp = now('Africa/Nairobi')->format('YmdHis');
        $response = $this->request('post', '/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $shortcode,
            'Password' => base64_encode($shortcode.(string) config('services.mpesa.passkey').$timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);
        $resultCode = $response['ResultCode'] ?? null;
        $status = $resultCode === null
            ? 'pending'
            : ((string) $resultCode === '0' ? 'paid' : 'failed');

        return [
            'status' => $status,
            'amount' => null,
            'currency_code' => 'KES',
            'provider_reference' => isset($response['MpesaReceiptNumber']) ? (string) $response['MpesaReceiptNumber'] : null,
        ];
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '254'.substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '254'.$digits;
        }
        if (! preg_match('/^254[17]\d{8}$/', $digits)) {
            throw new PaymentGatewayException('Enter a valid Kenyan mobile number for M-Pesa STK Push.');
        }

        return $digits;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new PaymentGatewayException('M-Pesa is not configured. Add the server-side Daraja credentials and callback URL.');
        }
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('services.mpesa.base_url'), '/');
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->withToken($this->accessToken())
                ->{$method}($baseUrl.$path, $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new PaymentGatewayException('M-Pesa could not be reached. The payment remains unconfirmed.', previous: $exception);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new PaymentGatewayException('M-Pesa returned an unsuccessful or invalid response.');
        }

        return $response->json();
    }

    private function accessToken(): string
    {
        return Cache::remember(
            'payments.mpesa.token.'.hash('sha256', (string) config('services.mpesa.base_url').'|'.(string) config('services.mpesa.consumer_key')),
            3300,
            function (): string {
                $baseUrl = rtrim((string) config('services.mpesa.base_url'), '/');
                try {
                    $response = Http::acceptJson()
                        ->withBasicAuth(
                            (string) config('services.mpesa.consumer_key'),
                            (string) config('services.mpesa.consumer_secret'),
                        )
                        ->timeout(20)
                        ->get($baseUrl.'/oauth/v1/generate?grant_type=client_credentials');
                } catch (ConnectionException|RequestException $exception) {
                    throw new PaymentGatewayException('M-Pesa authentication could not be reached.', previous: $exception);
                }
                $token = $response->json('access_token');
                if (! $response->successful() || ! is_string($token) || $token === '') {
                    throw new PaymentGatewayException('M-Pesa authentication failed.');
                }

                return $token;
            },
        );
    }
}
