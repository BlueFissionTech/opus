# Opus Architecture

This describes the current application-platform composition. [SPEC.md](SPEC.md)
defines detailed contracts; [PRD.md](PRD.md) and [ROADMAP.md](ROADMAP.md)
separate product requirements and planned delivery from working code.

## Context and ownership

Opus hosts applications on BlueCore. It owns host bootstrap, service and
add-on registration, application policy, command presentation, and resource
selection. Wise owns the command kernel; other first-party packages own their
reusable inference, orchestration, language, and interoperability behavior.
Opus does not reimplement those libraries or grant authority merely because
an add-on or provider is installed.

## Components and flow

1. **Entrypoints.** [`public/index.php`](public/index.php) starts the web
   engine. [`bin/opus-addon.php`](bin/opus-addon.php) exposes add-on contract
   generation and validation. [`terminal`](terminal) is the CLI host;
   [`websocket-server.php`](websocket-server.php) boots the optional socket
   transport backed by [`App\Terminal`](app/Terminal.php). Each entrypoint
   has its own caller and failure boundary.
2. **Bootstrap and registration.** [`common/bootstrap/runtime.php`](common/bootstrap/runtime.php)
   selects the active Composer autoloader and host/package roots through
   [`RuntimePathResolver`](app/Business/Services/RuntimePathResolver.php).
   [`AppRegistration`](app/Registration/AppRegistration.php) binds services,
   data interfaces, themes, and activated add-ons to the BlueCore engine.
3. **Application services and domain.** [`app/Business/Services`](app/Business/Services)
   contains host-level command, agent-composition, lifecycle-readiness,
   generation, and policy services. [`app/Domain`](app/Domain) contains user,
   onboarding, guidance, and command data contracts. Add-on package logic
   stays behind the [add-on contract](ADDONS.md).
4. **Adapters and data.** [`app/Business/Http`](app/Business/Http) exposes web
   controllers; [`app/Business/Console`](app/Business/Console) exposes console
   managers. SQL repository implementations sit beside their domain
   interfaces. External providers and optional transports must be configured
   by the host; their absence must not silently grant substitute behavior.
5. **Presentation.** Package themes ship under [`resource/markup`](resource/markup).
   [`HostFrontendThemeResolver`](app/Business/Services/HostFrontendThemeResolver.php)
   can select a contained host frontend root; admin remains package-owned.
   Asset build inputs and migration limits are documented in [ASSETS.md](ASSETS.md).

## Runtime and security boundaries

Host configuration lives under [`common/config`](common/config) and process
environment settings; secrets are not source files. Runtime path resolution
distinguishes the installed Opus package from the consuming host and constrains
host resource overrides. Agent and command authority is supplied by the host
and checked at the execution boundary, not inferred from client frames,
retrieved text, or extension callbacks. See the [extension-point catalog](docs/extension-points.md)
for hooks and deliberately closed boundaries.

This document does not claim a credential-free public install, complete
managed-service operations, or production readiness for optional providers.
Those remain evidence-gated in the [roadmap](ROADMAP.md). Unit and contract
tests live under [`tests`](tests); the [testing guide](tests.md) distinguishes
clean-checkout checks from opt-in integration and release proofs.
