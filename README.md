# Blue Fission Opus Framework

Opus is the Blue Fission application platform for building and operating
extensible web applications, automations, inference-backed experiences, and
integrations. It is built on BlueCore and composes package-owned command,
orchestration, conversation, presentation, and interoperability contracts.

## Overview

### Purpose

Opus provides a common runtime and administration boundary for applications
that combine deterministic automation, data-backed insights, machine-learning
classification, model inference, content or code generation, and human review.
Each capability remains explicit so operators can understand its provider,
permissions, evidence, and lifecycle.

### Key Features

- **Application Runtime**: Compose BlueCore services, routes, gateways, themes,
  and add-ons through one host.
- **Automation And Commands**: Expose authorized operations through Wise for
  CLI, programmatic, conversational, and agent callers.
- **Provider-Neutral Inference**: Integrate hosted, self-hosted, local, or
  deterministic providers through replaceable profiles.
- **Generation And Scaffolding**: Validate and render Vibe templates within
  bounded application paths.
- **Extensibility**: Install package-owned add-ons and use documented
  DevElation hooks and filters without modifying core files.
- **Scoped Agents And Profiles**: Isolate central, specialist, tenant, and user
  command and profile surfaces by default.
- **Inspectable Outcomes**: Preserve structured status, diagnostics,
  correlation, approval, and lifecycle context.

## Installation

Composer only reads repository declarations from the root project. Unreleased
Blue Fission packages are source-distributed through GitHub VCS. DevElation,
Automata, BlueCore, Chronicler, SimpleClients, and Synthetiq use their tagged
Packagist releases.

Start new applications from
[`templates/composer/opus-root.json`](templates/composer/opus-root.json), or
merge its `repositories`, Blue Fission compatibility entries from `require`,
and `config` sections into an existing
root `composer.json` before requiring Opus:

```bash
composer require bluefission/opus
```

Do not add VCS overrides for DevElation, Automata, BlueCore, Chronicler,
SimpleClients, or Synthetiq.
The remaining root registry is required because Composer does not inherit repository
definitions from Opus or other dependencies. Composer also does not inherit
root aliases from dependencies, so the template repeats any tagged release
aliases required by the current dependency graph. Each alias can be removed
as its corresponding upstream release constraints are published. After
merging the registry and compatibility entries,
validate the application dependency graph without installing packages or
running package scripts:

```bash
composer update --no-install --no-scripts
```

The default application profile requires Wise explicitly and uses
`WISE_INTEGRATION=required`. Applications that do not expose the Wise command
environment or resources can start from
[`templates/composer/opus-root-optional-wise.json`](templates/composer/opus-root-optional-wise.json)
and set `WISE_INTEGRATION=optional`. Optional mode skips the complete Wise
resource mapping when its runtime types are unavailable. Required mode fails
with the missing types and installation guidance.

Keep `config.use-github-api` set to `false` from the template. Composer then
uses the declared Git repositories directly when GitHub API metadata is
unavailable, while still retaining canonical GitHub source and distribution
metadata in the lock.

Opus maintainers can verify that the template still covers the complete locked
Blue Fission dependency graph with `composer audit:composer-vcs`.

### Runtime Roots

Opus distinguishes the host application from the installed package. The active
Composer binary autoloader is preferred, followed by an explicit host root,
an ancestor host autoloader, and finally the package-local autoloader used by a
source checkout. Set `OPUS_HOST_ROOT` in the process environment only when the
host root cannot be inferred before dotenv is available.

`APP_ROOT` and `SITE_ROOT` identify the host application. `OPUS_ROOT` and
`OPUS_RESOURCE_ROOT` identify the installed package and its shipped resources.
Built-in themes always render from `OPUS_RESOURCE_ROOT`. A host theme override
must be selected explicitly as a path below the host `resource` directory; an
existing relative host directory does not silently replace a package theme.

Composer publishes the add-on contract command at
`vendor/bin/opus-addon.php`, and the same package entrypoint works from source
and distribution installs:

```bash
php vendor/bin/opus-addon.php validate <addon-root>
```

## Usage

### Event Management

Opus's event management system allows you to hook into various events and filters, making it easy to extend and customize the framework's behavior.

The lazy Wise command boundary publishes non-blocking `opus.agent.command_runtime.ready` and `opus.agent.command_runtime.unavailable` DevElation actions. Their payloads contain only stable status metadata; dependency injection remains the supported processor replacement boundary, and observer failures cannot alter command availability.

### Add-On System

The add-on architecture allows for seamless feature additions and management without modifying core files directly. See the [add-on authoring guide](ADDONS.md) for the package boundary, lifecycle, structure, and validation expectations.

### Inference And Automation Integration

Opus composes provider-neutral inference, machine-learning, conversation, and
automation contracts without making a provider mandatory. Provider setup,
credentials, routing, budgets, and retention policy remain explicit
application concerns. See the [product specification](PRODUCT.md) for the
current maturity and known gaps.

### Command Line Tools

Opus includes command-line tools for development and runtime coordination. The
command surface should converge around Wise as the central invocation kernel so
human operators and agents share the same backend contract.

## Project Docs

- [Specification](SPEC.md)
- [Product specification](PRODUCT.md)
- [Product requirements](PRD.md)
- [Roadmap](ROADMAP.md)
- [Arkheion capability catalog](ARKHEION.md)
- [Capability language](docs/terminology.md)
- [Testing](tests.md)
- [Asset build contract](ASSETS.md)

## Core Components

### Automation

Coordinate deterministic or approval-gated work through explicit command,
capability, tenant, and lifecycle boundaries.

### Data Insights

Present findings with their source, freshness, uncertainty, and decision
authority instead of treating generated output as evidence.

### Learning Extensions

Opus provides Vibe-backed rendering and bounded scaffold generation. Generated
artifacts remain reviewable proposals until validation and authorization gates
complete.

### Human Review And Decision Support

Operators can review plans, diagnostics, evidence, and generated artifacts
before authorizing consequential work. Inference and orchestration do not grant
execution authority by themselves.

## Contributing

We welcome contributions to improve Opus. If you would like to contribute, please follow the guidelines in our [contributing guide](https://github.com/bluefission/opus/CONTRIBUTING.md).

## License

Opus is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Support

If you have any questions or need support, please open an issue on our [GitHub repository](https://github.com/bluefission/opus/issues).
