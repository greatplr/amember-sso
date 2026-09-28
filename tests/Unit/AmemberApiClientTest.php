<?php

namespace Greatplr\AmemberSso\Tests\Unit;

use Greatplr\AmemberSso\Api\AmemberApiClient;
use Greatplr\AmemberSso\Api\AmemberApiException;
use Greatplr\AmemberSso\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class AmemberApiClientTest extends TestCase
{
    protected function client(): AmemberApiClient
    {
        return new AmemberApiClient('https://members.example.com/api/', 'api-key-1234567890');
    }

    #[Test]
    public function a_second_call_from_the_same_instance_hits_the_right_url(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'subscriptions' => []])]);

        $client = $this->client();
        $client->checkAccess('by-login', ['login' => 'jane']);
        $client->checkAccess('by-email', ['email' => 'jane@example.com']);

        $urls = Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all();

        $this->assertSame([
            'https://members.example.com/api/check-access/by-login',
            'https://members.example.com/api/check-access/by-email',
        ], $urls);
    }

    #[Test]
    public function it_sends_the_api_key_header(): void
    {
        Http::fake(['*' => Http::response(['_total' => 0])]);

        $this->client()->list('users');

        Http::assertSent(fn (Request $request) => $request->header('X-API-Key') === ['api-key-1234567890']);
    }

    #[Test]
    public function check_access_posts_a_form_body(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client()->checkAccess('by-login-pass', ['login' => 'jane', 'pass' => 'p&ss=word']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->isForm()
                && $request->body() === 'login=jane&pass=p%26ss%3Dword';
        });
    }

    #[Test]
    public function list_encodes_filter_paging_and_nested_parameters(): void
    {
        Http::fake(['*' => Http::response(['_total' => 0])]);

        $this->client()->list('users', ['login' => 'jane doe', 'email' => '%@example.com'], 5000, 2, ['access', 'invoices']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://members.example.com/api/users'
                    . '?_filter%5Blogin%5D=jane%20doe'
                    . '&_filter%5Bemail%5D=%25%40example.com'
                    . '&_count=1000'
                    . '&_page=2'
                    . '&_nested%5B%5D=access&_nested%5B%5D=invoices';
        });
    }

    #[Test]
    public function list_strips_total_and_returns_the_records_as_a_list(): void
    {
        Http::fake(['*' => Http::response('{"_total":2,"0":{"user_id":1,"login":"a"},"1":{"user_id":2,"login":"b"}}')]);

        $client = $this->client();

        $this->assertSame([
            ['user_id' => 1, 'login' => 'a'],
            ['user_id' => 2, 'login' => 'b'],
        ], $client->list('users'));

        $this->assertSame(2, $client->listWithTotal('users')['total']);
    }

    #[Test]
    public function find_returns_the_single_record(): void
    {
        Http::fake(['https://members.example.com/api/products/7' => Http::response('{"0":{"product_id":7,"title":"Pro"}}')]);

        $this->assertSame(['product_id' => 7, 'title' => 'Pro'], $this->client()->find('products', 7));
    }

    #[Test]
    public function check_access_keeps_product_ids_as_subscription_keys(): void
    {
        Http::fake(['*' => Http::response('{"ok":true,"user_id":5,"subscriptions":{"4":"2037-12-31","7":"2026-10-31"}}')]);

        $response = $this->client()->checkAccess('by-login', ['login' => 'jane']);

        $this->assertSame([4 => '2037-12-31', 7 => '2026-10-31'], $response['subscriptions']);
    }

    #[Test]
    public function an_ok_false_response_is_returned_not_thrown(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'code' => -2, 'msg' => 'The user name or password is incorrect'])]);

        $response = $this->client()->checkAccess('by-login-pass', ['login' => 'jane', 'pass' => 'x']);

        $this->assertFalse($response['ok']);
        $this->assertSame(-2, $response['code']);
    }

    #[Test]
    public function a_non_json_4xx_throws_with_the_status(): void
    {
        Http::fake(['*' => Http::response('<html><body><h1>Error</h1><p>API Error 10003 - no permission for users-index</p></body></html>', 403)]);

        try {
            $this->client()->list('users');
            $this->fail('Expected AmemberApiException');
        } catch (AmemberApiException $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringContainsString('10003', $e->getMessage());
            $this->assertStringNotContainsString('<', $e->getMessage());
            $this->assertStringNotContainsString('api-key-1234567890', $e->getMessage());
        }
    }

    #[Test]
    public function a_non_json_200_throws(): void
    {
        Http::fake(['*' => Http::response('No API Action set', 200)]);

        $this->expectException(AmemberApiException::class);

        $this->client()->get('nonsense');
    }

    #[Test]
    public function from_config_uses_the_api_settings(): void
    {
        config(['amember-sso.api.url' => 'https://default.example.com/api', 'amember-sso.api.key' => 'default-key-123']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        AmemberApiClient::fromConfig()->checkAccess('by-login', ['login' => 'jane']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://default.example.com/api/check-access/by-login'
            && $request->header('X-API-Key') === ['default-key-123']);
    }

    #[Test]
    public function from_config_throws_when_unconfigured(): void
    {
        config(['amember-sso.api.url' => null, 'amember-sso.api.key' => null]);

        $this->expectException(AmemberApiException::class);

        AmemberApiClient::fromConfig();
    }
}
