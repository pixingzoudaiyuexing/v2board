<?php

namespace Tests\Feature;

use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubscriptionEntryTest extends TestCase
{
    private const ROUTE = '/api/v1/user/getSubscribeEntries';
    private const AUTH = 'subscription-entry-test-auth';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'subscription-entry-test-key']);
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
}
