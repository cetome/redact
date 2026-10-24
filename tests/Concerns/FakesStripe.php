<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * No real Stripe call in tests: the stripe-php HTTP client is replaced by a stub that answers
 * canned objects per "METHOD /v1/path" and records every request (Cashier's code runs as is).
 */
trait FakesStripe
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    protected array $stripeRequests = [];

    /** @var array<string, array<string, mixed>|callable> */
    protected array $stripeResponses = [];

    protected function fakeStripe(): void
    {
        config([
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
            'cashier.webhook.secret' => 'whsec_test_fake',
            'redact.billing.price' => 'price_product_monthly',
        ]);

        $test = $this;
        ApiRequestor::setHttpClient(new class($test) implements ClientInterface
        {
            public function __construct(private readonly object $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                $query = [];
                parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);

                return $this->test->answerStripe(strtoupper($method), $path, is_array($params) && $params !== [] ? $params : $query);
            }
        });
    }

    /** @param  array<string, mixed>|callable  $response  object, or fn (array $params): array */
    protected function stripeReturns(string $route, array|callable $response): void
    {
        $this->stripeResponses[$route] = $response;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    public function answerStripe(string $method, string $path, array $params): array
    {
        $this->stripeRequests[] = ['method' => $method, 'path' => $path, 'params' => $params];
        $response = $this->stripeResponses[$method.' '.$path] ?? null;
        if (is_callable($response)) {
            $response = $response($params);
        }

        return $response === null
            ? [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Not stubbed: '.$method.' '.$path]]), 404, new CaseInsensitiveArray([])]
            : [json_encode($response), 200, new CaseInsensitiveArray(['request-id' => 'req_test'])];
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>}> */
    protected function stripeCalls(string $method, string $path): array
    {
        return array_values(array_filter($this->stripeRequests, fn ($r) => $r['method'] === $method && $r['path'] === $path));
    }

    /** @return array<string, mixed> a Stripe subscription object */
    protected function stripeSubscription(string $customer, int $quantity = 1, string $status = 'active', string $id = 'sub_test'): array
    {
        $end = now()->addMonth()->timestamp;

        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customer,
            'status' => $status,
            'quantity' => $quantity,
            'cancel_at_period_end' => false,
            'metadata' => ['type' => 'products', 'name' => 'products'],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test',
                    'object' => 'subscription_item',
                    'quantity' => $quantity,
                    'current_period_end' => $end,
                    'price' => ['id' => 'price_product_monthly', 'object' => 'price', 'product' => 'prod_test'],
                ]],
            ],
        ];
    }

    /** A webhook request signed like Stripe does (Stripe-Signature: t=…,v1=HMAC-SHA256). */
    protected function postStripeWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, $secret ?? (string) config('cashier.webhook.secret'));

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
