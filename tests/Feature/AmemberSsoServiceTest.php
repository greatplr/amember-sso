<?php

namespace Greatplr\AmemberSso\Tests\Feature;

use Greatplr\AmemberSso\Api\AmemberApiClient;
use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Greatplr\AmemberSso\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class AmemberSsoServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('amember-sso.api.url', 'https://default.example.com/api');
        $app['config']->set('amember-sso.api.key', 'default-key-123');
    }

    protected function service(): AmemberSsoService
    {
        return app(AmemberSsoService::class);
    }

    protected function installation(): AmemberInstallation
    {
        return AmemberInstallation::create([
            'name' => 'Partner',
            'slug' => 'partner',
            'api_url' => 'https://partner.example.com/amember/api',
            'api_key' => 'partner-key-123',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function api_calls_use_the_configured_default_without_an_installation(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'user_id' => 5])]);

        $this->assertSame(5, $this->service()->checkAccessByLogin('jane')['user_id']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://default.example.com/api/check-access/by-login'
            && $r->header('X-API-Key') === ['default-key-123']
            && $r['login'] === 'jane');
    }

    #[Test]
    public function api_calls_use_the_installations_own_url_and_key(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $installation = $this->installation();

        $this->service()->checkAccessByEmail('jane@example.com', $installation);
        $this->service()->checkAccessByEmail('jane@example.com', $installation->id);

        $this->assertCount(2, Http::recorded(fn (Request $r) => $r->url() === 'https://partner.example.com/amember/api/check-access/by-email'
            && $r->header('X-API-Key') === ['partner-key-123']));
    }

    #[Test]
    public function client_returns_the_client_for_the_installation(): void
    {
        $client = $this->service()->client($this->installation());

        $this->assertInstanceOf(AmemberApiClient::class, $client);
        $this->assertSame('https://partner.example.com/amember/api', $client->baseUrl());
        $this->assertSame('https://default.example.com/api', $this->service()->client()->baseUrl());
    }

    #[Test]
    public function authenticate_uses_by_login_pass_ip_only_when_an_ip_is_given(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'user_id' => 5])]);
        $installation = $this->installation();

        $this->assertNotNull($this->service()->authenticateByLoginPass('jane', 'secret'));
        $this->assertNotNull($this->service()->authenticateByLoginPass('jane', 'secret', '203.0.113.9', $installation));

        $recorded = Http::recorded()->map(fn (array $pair) => [$pair[0]->url(), $pair[0]->data()])->all();

        $this->assertSame([
            ['https://default.example.com/api/check-access/by-login-pass', ['login' => 'jane', 'pass' => 'secret']],
            ['https://partner.example.com/amember/api/check-access/by-login-pass-ip', ['login' => 'jane', 'pass' => 'secret', 'ip' => '203.0.113.9']],
        ], $recorded);
    }

    #[Test]
    public function an_ok_false_response_returns_null(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'code' => -2, 'msg' => 'Wrong password'])]);

        $this->assertNull($this->service()->authenticateByLoginPass('jane', 'wrong'));
    }

    #[Test]
    public function a_permission_error_returns_null_and_logs_the_status(): void
    {
        Http::fake(['*' => Http::response('API Error 10002 - API Key not found', 401)]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context) => $context['status'] === 401 && str_contains($message, 'HTTP 401')
        );

        $this->assertNull($this->service()->checkAccessByLogin('jane'));
    }

    #[Test]
    public function get_user_by_login_returns_the_first_record(): void
    {
        Http::fake(['*' => Http::response('{"_total":1,"0":{"user_id":"5","login":"jane","pass":null}}')]);

        $this->assertSame('5', $this->service()->getUserByLogin('jane')['user_id']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '_filter%5Blogin%5D=jane') && str_contains($r->url(), '_count=1&'));
    }

    #[Test]
    public function access_records_are_merged_with_their_products_without_asking_for_nested_products(): void
    {
        Http::fake([
            'default.example.com/api/access*' => Http::response(
                '{"_total":3,"0":{"access_id":"1","product_id":"4"},"1":{"access_id":"2","product_id":"7"},"2":{"access_id":"3","product_id":"4"}}'
            ),
            'default.example.com/api/products/4*' => Http::response('{"0":{"product_id":"4","title":"Basic"}}'),
            'default.example.com/api/products/7*' => Http::response('Not found', 404),
        ]);

        $records = $this->service()->getAccessRecords(5);

        $this->assertSame([
            ['access_id' => '1', 'product_id' => '4', 'product' => ['product_id' => '4', 'title' => 'Basic']],
            ['access_id' => '2', 'product_id' => '7', 'product' => null],
            ['access_id' => '3', 'product_id' => '4', 'product' => ['product_id' => '4', 'title' => 'Basic']],
        ], $records);

        // One access call and one per distinct product; none asks for _nested
        $this->assertCount(3, Http::recorded());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '_nested'));
    }

    #[Test]
    public function generate_sso_url_uses_the_package_config(): void
    {
        config(['amember-sso.sso.login_url' => null]);
        $this->assertSame(
            'https://default.example.com/login?amember_redirect_url=%2Fdashboard',
            $this->service()->generateSsoUrl('jane')
        );

        config(['amember-sso.sso.login_url' => 'https://members.example.com/login']);
        $this->assertSame(
            'https://members.example.com/login?amember_redirect_url=%2Fhome',
            $this->service()->generateSsoUrl('jane', '/home')
        );
    }

    #[Test]
    public function installation_login_url_strips_only_a_trailing_api_segment(): void
    {
        $installation = new AmemberInstallation(['api_url' => 'https://example.com/wiki/api']);

        $this->assertSame('https://example.com/wiki/login', $installation->getLoginUrl());
    }
}
