# Native composition and authority

- Opus owns passive factory/lifecycle and generated specialist enrollment.
- DevElation Service, immutable Obj values, Arr/Str/Func and actual Application
  registration are used; no duplicate container, event bus or workflow engine.
- SimpleClients owns ClientInterface, ClientRequest, ClientResponse, capabilities,
  transport, authentication and provider/protocol interpretation. The injected
  text-submission fixture uses POST messages with a package-owned body; this is
  not a provider endpoint specification. A future qualified binding translates it
  through the owning native client. Never bind an arbitrary generic HTTP client
  and infer messaging support from HTTP200.
  Resolver/review callbacks and client implementations are trusted host code, not
  sandboxed plugins. Provider qualification must establish their timeout and retry
  behavior; this service neither interrupts blocking clients nor overrides their
  internal retries. Its no-replay guarantee covers only its own dispatch calls.
- A trusted context resolver and review callback are host inputs, never command
  arguments. Participant membership is a host-owned channel configuration; fresh
  lifecycle/capability context is authoritative on every access. Configuration
  replacement requires a controlled service lifecycle, not untrusted updates.
- Process-local receipts retain a payload digest and sanitized outcome, not message
  text. No ambiguous dispatch is replayed within the same instance. A new instance
  is NOT a recovery mechanism; live use requires a qualified native atomic journal.
- Wise owns parsing and continuations. No network, database or credentials are
  acquired by construction, package discovery, parsing or validation.

The host binding is deliberately narrow and removable: replace injected fixture
clients only after the protocol owner supplies reviewed compatible adapters,
signature/status fixtures and receipt semantics. Keep host authorization and
approval outside those adapters. No upstream upgrades are implied.
