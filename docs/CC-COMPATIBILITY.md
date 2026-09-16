# CC V2Board compatibility patches

## VB-CF02-001 - Expose Subscription Entry Metadata

- Purpose: expose the complete configured subscription entry bases for CC Solution CF-02.
- Official upstream: `wyx2685/v2board` at `99f8526eddb72a4e8f6cbccd58cc0656bb91fe88`.
- Writable CC repository: `pixingzoudaiyuexing/v2board`.
- Patch base HEAD: `e575c38227f6de9aace98d46b2d35d7469b19e70`.
- Patch commit: `a37250b7f9651a4aab7e077fb463f48bca2f46ec` (implementation); this documentation is committed immediately after it.
- Ground truth: `config('v2board.subscribe_url')`. Official `getSubscribe` randomly resolves only one configured base, so the User API otherwise cannot disclose the complete configured list.
- Modified files: `app/Services/SubscriptionEntryService.php`, `app/Http/Controllers/V1/User/SubscriptionEntryController.php`, `app/Http/Routes/V1/UserRoute.php`, `tests/Feature/SubscriptionEntryTest.php`, `docs/CC-COMPATIBILITY.md`.

### Contract and security

`GET /api/v1/user/getSubscribeEntries` uses the existing V2Board `user` middleware, with the existing `authorization` / `auth_data` authentication. A successful response is exactly `{"data":{"entries":[{"base_url":"https://a.example.com"}]}}`; an empty configuration returns `{"data":{"entries":[]}}`. There is no Entry ID. Configured ordering is retained.

Only safe configured base URLs are returned. No user token, subscription credential, OTP, time-based credential, `subscribe_path`, Admin configuration or secret, database state, or other configuration is read into the response. This endpoint does not alter `getSubscribe` or normal subscription generation.

The parser splits on commas, trims each component, skips empty components, and removes exact duplicates after trimming while keeping first-seen order. It preserves URL case, path, port, and trailing slash. Null or empty/whitespace-only configuration returns an empty list. Non-string configuration or any non-empty malformed/non-HTTP(S) entry, missing host, userinfo, query, or fragment fails the entire request with HTTP 500 and the fixed response `{"message":"Subscription entry configuration is invalid"}`. The invalid value is never included in that response.

### Tests

`tests/Feature/SubscriptionEntryTest.php` exercises authentication, exact response shape, parsing and invalid configuration. Run it with `./vendor/bin/phpunit tests/Feature/SubscriptionEntryTest.php`; also run the existing suite and PHP syntax checks. Record environmental gaps in the implementation report.

### Upgrade procedure

1. Fetch new official upstream.
2. Determine whether upstream now exposes an equivalent narrow complete-entry capability.
3. If equivalent official capability exists, evaluate retiring VB-CF02-001.
4. Otherwise rebase/reapply the compatibility patch.
5. Re-run all compatibility and regression tests.
6. Re-run independent security/minimal-patch review.
7. Update the upstream base SHA and patch commit reference.
