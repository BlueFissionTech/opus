# Kwikle integration automation

Kwikle composes triggers and actions for operators and specialist governors on
the shared Opus command surface. Synematic owns sequencing; provider clients
belong to SimpleClients and cross-system contracts belong to Annex.

The first source-package increment executes a fixture capture workflow through
Synematic. Operators can list, validate, explain, dry-run, execute and inspect a
run. Execution requires a host-owned approval bound to the exact request and
authenticated application, tenant and actor. Parse and discovery never execute.

Acceptance covers duplicate events, conflicting reuse, explicit bounded retries
after confirmed no effects, partial failure, approved compensation, unavailable
connectors, denied actions, observer privacy and correlation. Returned connector
content is never interpreted as a command.

This increment has an in-process run repository and fixture connector only.
It is not a durable queue or a production provider integration. External
connectors require released SimpleClients and Annex contracts, durable atomic
claim/receipt storage, crash recovery, quota and abuse controls before activation.
The eventual service-kwikle / auto.bluefission.com deployment remains gated on
those requirements. Issue #74 remains open until its complete acceptance is met.
