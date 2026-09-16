<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionEntryTest extends TestCase
{
    private const ROUTE = '/api/v1/user/getSubscribeEntries';
    private const SELECT_ROUTE = '/api/v1/user/getSubscribeForEntry';
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

    public function testSelectedEntryEndpointRequiresExistingUserAuthentication(): void
    {
        config(['v2board.subscribe_url' => 'https://entry.example.com']);

        $this->postJson(self::SELECT_ROUTE, ['base_url' => 'https://entry.example.com'])
            ->assertStatus(403);
        $this->withHeader('authorization', 'invalid-auth')
            ->postJson(self::SELECT_ROUTE, ['base_url' => 'https://entry.example.com'])
            ->assertStatus(403);
    }

    /** @dataProvider invalidSelectedValueProvider */
    public function testSelectedEntryRequiresStringBaseUrl($payload): void
    {
        config(['v2board.subscribe_url' => 'https://entry.example.com']);

        $this->withHeader('authorization', self::AUTH)->postJson(self::SELECT_ROUTE, $payload)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Selected subscription entry is invalid']);
    }

    public function invalidSelectedValueProvider(): array
    {
        return [
            'missing' => [[]],
            'null' => [['base_url' => null]],
            'integer' => [['base_url' => 123]],
            'array' => [['base_url' => ['https://entry.example.com']]],
        ];
    }

    /** @dataProvider selectedEntryProvider */
    public function testSelectedEntryUsesExactCanonicalConfiguredBase(
        string $configured,
        string $selected,
        string $expected
    ): void {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => $configured,
            'v2board.subscribe_path' => '/custom-subscribe',
            'v2board.show_subscribe_method' => 0]);

        Http::fake();
        $this->postSelected($user, $selected)
            ->assertStatus(200)
            ->assertExactJson(['data' => ['subscribe_url' => $expected]]);
        Http::assertNothingSent();
    }

    public function selectedEntryProvider(): array
    {
        return [
            'single' => [
                'https://a.example.com',
                'https://a.example.com',
                'https://a.example.com/custom-subscribe?token=legacy-regression-token',
            ],
            'multiple A' => [
                'https://a.example.com,https://b.example.com,https://c.example.com',
                'https://a.example.com',
                'https://a.example.com/custom-subscribe?token=legacy-regression-token',
            ],
            'multiple B' => [
                'https://a.example.com,https://b.example.com,https://c.example.com',
                'https://b.example.com',
                'https://b.example.com/custom-subscribe?token=legacy-regression-token',
            ],
            'multiple C' => [
                'https://a.example.com,https://b.example.com,https://c.example.com',
                'https://c.example.com',
                'https://c.example.com/custom-subscribe?token=legacy-regression-token',
            ],
            'port' => [
                'https://a.example.com:8443',
                'https://a.example.com:8443',
                'https://a.example.com:8443/custom-subscribe?token=legacy-regression-token',
            ],
            'path' => [
                'https://a.example.com/base',
                'https://a.example.com/base',
                'https://a.example.com/base/custom-subscribe?token=legacy-regression-token',
            ],
            'trailing slash' => [
                'https://a.example.com/',
                'https://a.example.com/',
                'https://a.example.com//custom-subscribe?token=legacy-regression-token',
            ],
            'trimmed canonical entry' => [
                ' https://a.example.com ',
                'https://a.example.com',
                'https://a.example.com/custom-subscribe?token=legacy-regression-token',
            ],
            'exact duplicate' => [
                'https://a.example.com,https://a.example.com',
                'https://a.example.com',
                'https://a.example.com/custom-subscribe?token=legacy-regression-token',
            ],
        ];
    }

    public function testPrefixOverlapUsesSelectedEntryWithoutInference(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://x.example.com,https://x.example.com/p',
            'v2board.subscribe_path' => '/sub',
            'v2board.show_subscribe_method' => 0]);

        $this->postSelected($user, 'https://x.example.com')
            ->assertExactJson(['data' => ['subscribe_url' =>
                'https://x.example.com/sub?token=legacy-regression-token']]);
        $this->postSelected($user, 'https://x.example.com/p')
            ->assertExactJson(['data' => ['subscribe_url' =>
                'https://x.example.com/p/sub?token=legacy-regression-token']]);
    }

    public function testFormRequestAlsoUsesExactUntrimmedSelectionIdentity(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => ' https://a.example.com ',
            'v2board.subscribe_path' => '/sub',
            'v2board.show_subscribe_method' => 0]);

        $this->postSelectedForm($user, 'https://a.example.com')
            ->assertStatus(200)
            ->assertExactJson(['data' => ['subscribe_url' =>
                'https://a.example.com/sub?token=legacy-regression-token']]);
        $this->postSelectedForm($user, ' https://a.example.com ')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Selected subscription entry is invalid']);
    }

    /** @dataProvider rejectedSelectionProvider */
    public function testSelectedEntryRejectsUnknownOrNonCanonicalValues(
        string $configured,
        string $selected
    ): void {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => $configured]);

        $this->postSelected($user, $selected)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Selected subscription entry is invalid']);
    }

    public function rejectedSelectionProvider(): array
    {
        return [
            'arbitrary URL' => ['https://a.example.com', 'https://attacker.example.com'],
            'noncanonical whitespace' => [' https://a.example.com ', ' https://a.example.com '],
            'case difference' => ['https://A.example.com', 'https://a.example.com'],
            'prefix only' => ['https://x.example.com/p', 'https://x.example.com'],
        ];
    }

    public function testEntryDeletedAfterDiscoveryIsRejectedAsStale(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://a.example.com,https://deleted.example.com']);
        $request = Request::create(self::ROUTE, 'GET');
        $authData = (new AuthService($user))->generateAuthData($request)['auth_data'];
        $this->withHeader('authorization', $authData)->get(self::ROUTE)
            ->assertExactJson(['data' => ['entries' => [
                ['base_url' => 'https://a.example.com'],
                ['base_url' => 'https://deleted.example.com'],
            ]]]);

        config(['v2board.subscribe_url' => 'https://a.example.com']);
        $this->postSelected($user, 'https://deleted.example.com')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Selected subscription entry is invalid']);
    }

    /** @dataProvider invalidSelectedConfigurationProvider */
    public function testSelectedEntryPreservesInvalidConfigurationError($configured): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => $configured]);

        $this->postSelected($user, 'https://a.example.com')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Subscription entry configuration is invalid']);
    }

    public function invalidSelectedConfigurationProvider(): array
    {
        return [
            'userinfo' => ['https://user:password@a.example.com'],
            'query' => ['https://a.example.com/?token=private-token'],
            'fragment' => ['https://a.example.com/#private-fragment'],
        ];
    }

    public function testSelectedEntryUsesAndReusesAuthoritativeOtpState(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://otp.example.com',
            'v2board.subscribe_path' => '/otp-subscribe',
            'v2board.show_subscribe_method' => 1]);

        $oldUrl = Helper::getSubscribeUrl($user->token);
        $otp = Cache::get('otp_' . $user->token);
        $this->assertNotEmpty($otp);
        $this->assertSame($user->token, Cache::get('otpn_' . $otp));

        $response = $this->postSelected($user, 'https://otp.example.com')
            ->assertStatus(200)
            ->assertExactJson(['data' => ['subscribe_url' =>
                'https://otp.example.com/otp-subscribe?token=' . $otp]]);
        $this->assertSame($oldUrl, $response->json('data.subscribe_url'));
        $this->assertSame($otp, Cache::get('otp_' . $user->token));
    }

    public function testSelectedEntryUsesAuthoritativeTimeBasedCredential(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://time.example.com/base',
            'v2board.subscribe_path' => '/time-subscribe',
            'v2board.show_subscribe_method' => 2,
            'v2board.show_subscribe_expire' => 7]);

        $before = time();
        $response = $this->postSelected($user, 'https://time.example.com/base')
            ->assertStatus(200);
        $after = time();
        $url = $response->json('data.subscribe_url');

        $this->assertSame('https://time.example.com/base/time-subscribe', strtok($url, '?'));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['token'], array_keys($query));
        $decoded = base64_decode(strtr($query['token'], '-_', '+/'), true);
        $expected = [];
        for ($second = $before; $second <= $after; $second++) {
            $counter = floor($second / (7 * 60));
            $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $user->token);
            $expected[] = $user->id . ':' . $hash;
        }
        $this->assertContains($decoded, $expected);
        $this->assertSame(['data' => ['subscribe_url' => $url]], $response->json());
    }

    public function testSelectedEntryUsesDefaultSubscribePathWhenConfiguredPathIsEmpty(): void
    {
        $user = $this->makeSubscriptionUser();
        config(['v2board.subscribe_url' => 'https://a.example.com',
            'v2board.subscribe_path' => '',
            'v2board.show_subscribe_method' => 0]);

        $this->postSelected($user, 'https://a.example.com')
            ->assertExactJson(['data' => ['subscribe_url' =>
                'https://a.example.com/api/v1/client/subscribe?token=legacy-regression-token']]);
    }

    public function testOldHelperPreservesRawWhitespaceSelectionSemantics(): void
    {
        config(['v2board.subscribe_url' => ' https://a.example.com ',
            'v2board.subscribe_path' => '/sub',
            'v2board.show_subscribe_method' => 0]);

        $this->assertSame(' https://a.example.com /sub?token=old-user-token',
            Helper::getSubscribeUrl('old-user-token'));
    }

    private function postSelected(User $user, $baseUrl)
    {
        $request = Request::create(self::SELECT_ROUTE, 'POST');
        $authData = (new AuthService($user))->generateAuthData($request)['auth_data'];

        return $this->withHeader('authorization', $authData)
            ->postJson(self::SELECT_ROUTE, ['base_url' => $baseUrl]);
    }

    private function postSelectedForm(User $user, $baseUrl)
    {
        $request = Request::create(self::SELECT_ROUTE, 'POST');
        $authData = (new AuthService($user))->generateAuthData($request)['auth_data'];

        return $this->call('POST', self::SELECT_ROUTE, [], [], [], [
            'HTTP_AUTHORIZATION' => $authData,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'base_url=' . rawurlencode($baseUrl));
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
