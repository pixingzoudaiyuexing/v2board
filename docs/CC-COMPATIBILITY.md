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

## VB-CF02-002 - Generate Subscription URL for Selected Entry

- Purpose: generate the official current-user subscription URL for one exact selected canonical entry.
- Dependency chain: VB-CF02-001 provides canonical entry discovery; VB-CF02-002 provides official credential URL generation for one exact validated canonical entry.
- Reason: VB-CF02-001 alone does not identify which base was selected by the existing random full URL. Prefix matching is ambiguous when entries overlap, such as `https://x.example.com` and `https://x.example.com/p`, and the official random Helper path retains raw comma-separated whitespace while VB-CF02-001 returns trimmed canonical entries.
- Route and method: `POST /api/v1/user/getSubscribeForEntry`.
- Authentication: existing V2Board `user` middleware with existing `authorization` / `auth_data`; no new credential or eligibility policy.
- Request: JSON or URL-encoded form body containing only the selection identity, for example `{"base_url":"https://x.example.com/p"}`. The original untrimmed body value must exactly equal a current canonical entry.
- Success response: `{"data":{"subscribe_url":"https://x.example.com/p/api/v1/client/subscribe?token=..."}}`. No separate token, OTP, HMAC, path, Admin data, user model, or other configuration is returned.
- Selection error: missing, non-string, unknown, stale, case-different, whitespace-different, or prefix-only values return HTTP 422 with `{"message":"Selected subscription entry is invalid"}`. Submitted values are not echoed and there is no fallback.
- Configuration error: any invalid non-empty configured entry returns HTTP 500 with `{"message":"Subscription entry configuration is invalid"}`, preserving VB-CF02-001 fail-closed behavior without exposing the configured value.
- Exact membership and parser sharing: `SubscriptionEntryService::canonicalBaseUrls()` is the sole trim, validation, duplicate-removal, and ordering parser. Both compatibility endpoints use it; selected entries use strict string equality only.
- Credential single source: `Helper::buildSubscribeUrl()` is the sole implementation of `subscribe_path`, normal token, OTP cache/lifetime, and time-based HMAC behavior. Existing `getSubscribeUrl()` retains raw `explode` plus random selection and passes that raw value to the builder. `getSubscribeUrlForBase()` passes the already validated canonical base to the same builder.
- Backward compatibility: the existing `/api/v1/user/getSubscribe` controller is unchanged. Historical random selection and raw whitespace behavior are intentionally preserved; the old path does not use the canonical parser.
- Network boundary: the selected base is only concatenated into the returned URL. The compatibility path performs no HTTP request, DNS lookup, redirect, proxy, socket, or filesystem operation against it.
- CC master/base SHA before patch: `fc9a47a1f5571188a8ca4542d7304d3457a905da`.
- Official upstream: `wyx2685/v2board` at `99f8526eddb72a4e8f6cbccd58cc0656bb91fe88`.
- Implementation commit: `851fabf222f5b60ff265b880de61dac4b908d3ad`.
- Modified files: `app/Utils/Helper.php`, `app/Services/SubscriptionEntryService.php`, `app/Http/Controllers/V1/User/SubscriptionEntryController.php`, `app/Http/Routes/V1/UserRoute.php`, `tests/Feature/SubscriptionEntryTest.php`, and `docs/CC-COMPATIBILITY.md`.
- Tests: authentication and exact request/response boundaries; JSON and form identity preservation; single/multiple/path/port/trailing-slash/duplicate entries; prefix overlap; stale and arbitrary selections; invalid configuration; all three credential modes; default/custom path; VB-CF02-001 stability; historical random/raw-whitespace behavior; existing `getSubscribe` endpoint.

### VB-CF02-002 upgrade procedure

1. Fetch the new official upstream and determine whether it provides both canonical complete-entry discovery and exact selected-entry credential generation.
2. If equivalent official capabilities exist, evaluate retiring VB-CF02-001 and VB-CF02-002 together without changing public behavior prematurely.
3. Otherwise rebase/reapply both patches in dependency order: VB-CF02-001, then VB-CF02-002.
4. Verify that the upstream credential implementation has not changed before retaining or adapting the shared builder refactor.
5. Re-run canonical-parser, selection, all credential-mode, old random/raw-whitespace, endpoint, security, and full regression tests.
6. Re-run independent security, credential-single-source, backward-compatibility, minimal-patch, upgradeability, and contract review.
7. Update the upstream/base and implementation commit references.
