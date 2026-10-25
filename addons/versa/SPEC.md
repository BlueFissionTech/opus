# Versa injected text submission

Versa is a passive specialist add-on. An explicitly configured host may inject a
native SimpleClients ClientInterface and approve a bounded text request. The
package owns application/tenant/participant admission, approval and sanitized
attempt receipts, not provider protocols, credentials or message delivery.

The initial executable boundary is channel discovery, reviewed send, notification
opt-in and attempt status. No client is selected or constructed by discovery.
Native Wise parsing must not confer authority. Current host context is re-read
after callbacks and immediately before transport dispatch or disclosure. Approval
binds the exact immutable request and app, tenant, actor and correlation.

An HTTP success is only a submitted request with unknown delivery/effects, never
proof of delivery. Exceptions and negative responses after dispatch are uncertain.
No automatic retry occurs; an identical request returns the existing receipt,
changed content under the same key conflicts, and a different actor cannot read it.
Pre-dispatch unavailability can be retried. Receipts are service-instance-local and
bounded to 1000 entries, not a durable outbox or distributed idempotency guarantee.

Text is bounded UTF-8. Up to four media references contain only id, MIME and byte
count declarations; neither attachments nor URLs are fetched. Results contain no
message body, private participant roster, provider errors, headers or credentials.

## Remaining issue acceptance

Signed inbound normalization, durable duplicate/order/recovery behavior, provider
status reconciliation and protocol-backed live transports are not implemented by
this submission increment. A fake boolean signature flag is not verification.
Native provider adapters and their offline signature/error fixtures must precede
that work; no local Matrix, SMTP or SMS implementation is permitted. Matrix
evaluation, encryption/key ownership, retention, moderation, abuse/rate/load tests
and deployment remain gates. This package must not be activated for live delivery
or advertised as a complete communication service based on these fixtures.
