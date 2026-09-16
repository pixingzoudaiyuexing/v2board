<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionEntryTest extends TestCase
{
    private const ROUTE = '/api/v1/user/getSubscribeEntries';
    private const AUTH = 'subscription-entry-test-auth';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => str_repeat('x', 32)]);
        Cache::put(self::AUTH, ['id' => 1, 'email' => 'test@example.com'], 60);
        config(['v2board.subscribe_path' => '/private-subscribe-path',
            'v2board.admin_secret' => 'private-admin-secret',
            'v2board.unrelated_config' => 'private-other-config']);
    }

    public function testExistingUserAuthenticationIsRequired(): void
    {
        config(['v2board.subscribe_url' => 'https://entry.example.com']);
        $this->get(self::ROUTE)->assertStatus(403);
        $this->withHeader('authorization', 'invalid-auth')->get(self::ROUTE)->assertStatus(403);
        $this->withHeader('authorization', self::AUTH)->get(self::ROUTE)
            ->assertStatus(200)
            ->assertExactJson(['data' => ['entries' => [['base_url' => 'https://entry.example.com']]]]);
        $this->withHeader('authorization', '')->get(self::ROUTE . '?auth_data=' . self::AUTH)
            ->assertStatus(200)
            ->assertExactJson(['data' => ['entries' => [['base_url' => 'https://entry.example.com']]]]);
    }

    /** @dataProvider validConfigurations */
    public function testReturnsOnlySafeConfiguredEntries($configured, array $expected): void
    {
        config(['v2board.subscribe_url' => $configured]);
        $this->withHeader('authorization', self::AUTH)->get(self::ROUTE)
            ->assertStatus(200)
            ->assertExactJson(['data' => ['entries' => $expected]]);
    }

    public function validConfigurations(): array
    {
        return [
            'missing' => [null, []],
            'empty' => ['', []],
            'whitespace' => ['  ', []],
            'single' => ['https://a.example.com', [['base_url' => 'https://a.example.com']]],
            'stable ordering and empty components' => [
                'https://a.example.com, https://b.example.com ,,https://a.example.com, https://c.example.com,',
                [['base_url' => 'https://a.example.com'], ['base_url' => 'https://b.example.com'], ['base_url' => 'https://c.example.com']],
            ],
            'preserves case path port and trailing slash' => [
                ' HTTPS://A.Example.com:8443/p/,https://a.example.com:8443/p/',
                [['base_url' => 'HTTPS://A.Example.com:8443/p/'], ['base_url' => 'https://a.example.com:8443/p/']],
            ],
        ];
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidConfigurationFailsClosedWithoutEchoingIt($configured): void
    {
        config(['v2board.subscribe_url' => $configured]);
        $this->withHeader('authorization', self::AUTH)->get(self::ROUTE)
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Subscription entry configuration is invalid']);
    }

    public function invalidConfigurations(): array
    {
        return [
            'scheme' => ['ftp://a.example.com'],
            'malformed' => ['https://bad host'],
            'missing host' => ['https:///path'],
            'userinfo' => ['https://user:password@a.example.com'],
            'empty userinfo' => ['https://@a.example.com'],
            'query' => ['https://a.example.com/?token=private-token'],
            'fragment' => ['https://a.example.com/#private-fragment'],
            'non-string' => [['https://a.example.com']],
            'no partial list' => ['https://a.example.com,https://user:password@b.example.com'],
        ];
    }

    public function testExistingNormalAndOtpSubscriptionGenerationRemainsSeparate(): void
    {
        config(['v2board.subscribe_url' => 'https://a.example.com',
            'v2board.subscribe_path' => '/old-subscribe-path',
            'v2board.show_subscribe_method' => 0]);
        $this->assertSame('https://a.example.com/old-subscribe-path?token=old-user-token',
            Helper::getSubscribeUrl('old-user-token'));

        config(['v2board.show_subscribe_method' => 1]);
        Cache::put('otp_old-user-token', 'old-otp', 60);
        $this->assertSame('https://a.example.com/old-subscribe-path?token=old-otp',
            Helper::getSubscribeUrl('old-user-token'));
        $this->withHeader('authorization', self::AUTH)->get(self::ROUTE)
            ->assertExactJson(['data' => ['entries' => [['base_url' => 'https://a.example.com']]]]);
    }

    public function testExistingTimeBasedSubscriptionCredentialUsesConfiguredBaseAndPath(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://legacy.example.com:8443/base',
            'v2board.subscribe_path' => '/original-subscribe',
            'v2board.show_subscribe_method' => 2,
            'v2board.show_subscribe_expire' => 7]);

        $before = time();
        $url = Helper::getSubscribeUrl($user->token);
        $authData = (new AuthService($user))->generateAuthData(Request::create(self::ROUTE, 'GET'))['auth_data'];
        $this->withHeader('authorization', $authData)->get(self::ROUTE)
            ->assertStatus(200)
            ->assertExactJson(['data' => ['entries' => [['base_url' => 'https://legacy.example.com:8443/base']]]]);
        $afterUrl = Helper::getSubscribeUrl($user->token);
        $after = time();

        $expected = [];
        for ($second = $before; $second <= $after; $second++) {
            $counter = floor($second / (7 * 60));
            $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $user->token);
            $expected[] = $user->id . ':' . $hash;
        }
        foreach ([$url, $afterUrl] as $generatedUrl) {
            $this->assertSame('https://legacy.example.com:8443/base/original-subscribe',
                strtok($generatedUrl, '?'));
            parse_str(parse_url($generatedUrl, PHP_URL_QUERY), $query);
            $this->assertSame(['token'], array_keys($query));
            $decoded = base64_decode(strtr($query['token'], '-_', '+/'), true);
            $this->assertNotFalse($decoded);
            $this->assertContains($decoded, $expected);
        }
    }

    public function testExistingGetSubscribeEndpointStillReturnsNormalSubscriptionUrl(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://legacy.example.com',
            'v2board.subscribe_path' => '/original-subscribe',
            'v2board.show_subscribe_method' => 0]);
        $authData = (new AuthService($user))->generateAuthData(Request::create('/api/v1/user/getSubscribe', 'GET'))['auth_data'];

        $this->withHeader('authorization', $authData)->get(self::ROUTE)
            ->assertExactJson(['data' => ['entries' => [['base_url' => 'https://legacy.example.com']]]]);
        $response = $this->withHeader('authorization', $authData)->get('/api/v1/user/getSubscribe')
            ->assertStatus(200);

        $expectedUrl = 'https://legacy.example.com/original-subscribe?token=' . $user->token;
        $this->assertSame($expectedUrl, Helper::getSubscribeUrl($user->token));
        $this->assertSame($expectedUrl, $response->json('data.subscribe_url'));
        $this->assertSame($user->token, $response->json('data.token'));
    }

    private function makeSubscriptionUser(): User
    {
        config(['database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('token')->unique();
            $table->string('email');
            $table->string('uuid');
            $table->unsignedInteger('plan_id')->nullable();
            $table->integer('expired_at')->nullable();
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->unsignedBigInteger('transfer_enable')->default(0);
            $table->unsignedInteger('device_limit')->default(0);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        $user = new User();
        $user->forceFill([
            'token' => 'legacy-regression-token',
            'email' => 'legacy@example.com',
            'uuid' => 'legacy-test-uuid',
            'plan_id' => null,
        ]);
        $user->save();

        return $user;
    }
}
