# Pending Order Expiration

Pending orders have one authoritative lifetime:

```text
Order::PENDING_TTL_SECONDS = 7200
expires_at = created_at + PENDING_TTL_SECONDS
```

The deadline is hard: an order is expired when `now >= expires_at`.

The same rule applies to:

- user order list and detail responses, which explicitly append `expires_at`;
- checkout, before free-order processing or payment mutation;
- the scheduled order handler;
- the atomic pending-to-paid database transition.

`expires_at` is a derived attribute and is not globally appended to Order
serialization. No database migration or persistent expiration state is used.

Provider callbacks for an expired pending order return the provider's success
response without paying, reopening, activating, or crediting the order. They
write `LATE_PAYMENT_EXPIRED_ORDER` to the `daily` log channel with order and
callback identifiers plus expiry/receipt timestamps. Full callback parameters,
credentials, signatures, payment URLs, and QR payloads are not logged. Late
payments require manual refund or compensation review.
