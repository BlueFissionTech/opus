# Blue Fission Opus Framework

Welcome to the Opus framework, crafted by BlueFission. Opus is designed to bring the power of AI to your fingertips, enabling you to build and manage AI-powered applications with ease. This framework is built on BlueCore and integrates seamlessly with various AI tools and services to help you create robust, data-driven applications.

## Overview

### Purpose

Opus is a low-code/no-code platform aimed at democratizing AI technology. It allows users of all technical backgrounds to leverage AI capabilities in their applications. Opus bridges the gap between human potential and AI power, providing tools for business automation, data insights, learning systems, and human-AI collaboration.

### Key Features

- **Business Automation**: Deploy autonomous agents to achieve your organization's goals.
- **Data Informed Insights**: Utilize data science tools to uncover hidden insights from your data.
- **Learning Systems**: Generate dynamic addon extensions that enhance functionality in real-time.
- **Human Supporting**: Collaborate with AI to boost productivity and effectiveness.
- **Low Code/No Code Development**: Create AI-powered applications with minimal coding effort.
- **Extensibility**: Easily extend functionality through a plugin-based system.
- **Self-Improving System**: Leverage generative AI to evolve and create new features as your needs grow.
- **Stakeholder Collaboration**: Foster collective decision-making with multiple stakeholders.

## Installation

Composer only reads repository declarations from the root project. Most Blue
Fission packages are source-distributed through GitHub VCS; DevElation is the
only package in the supported graph resolved through Packagist.

Start new applications from
[`templates/composer/opus-root.json`](templates/composer/opus-root.json), or
merge its `repositories` and `config` sections into an existing
root `composer.json` before requiring Opus:

```bash
composer require bluefission/opus
```

Do not add a DevElation VCS override. The root registry is required because
Composer does not inherit repository definitions from Opus or other
dependencies. After merging the registry, validate the application dependency
graph without running package scripts:

```bash
composer update --no-scripts
```

Keep `config.use-github-api` set to `false` from the template. Composer then
uses the declared Git repositories directly when GitHub API metadata is
unavailable, while still retaining canonical GitHub source and distribution
metadata in the lock.

Opus maintainers can verify that the template still covers the complete locked
Blue Fission dependency graph with `composer audit:composer-vcs`.

## Usage

### Event Management

Opus's event management system allows you to hook into various events and filters, making it easy to extend and customize the framework's behavior.

### Add-On System

The add-on architecture allows for seamless feature additions and management without modifying core files directly. See the [add-on authoring guide](ADDONS.md) for the package boundary, lifecycle, structure, and validation expectations.

### AI Integration

Opus is designed to integrate seamlessly with AI libraries and services, providing native compatibility and simplifying the process of building AI-powered applications.

### Command Line Tools

Opus includes command-line tools for development and runtime coordination. The
command surface should converge around Wise as the central invocation kernel so
human operators and agents share the same backend contract.

## Project Docs

- [Specification](SPEC.md)
- [Roadmap](ROADMAP.md)
- [Testing](tests.md)

## Core Components

### Automation

Deploy autonomous agents to automate various business processes, increasing efficiency and reducing manual effort.

### Data Insights

Access a suite of data science tools to analyze and visualize your data, helping you make informed decisions based on real insights.

### Learning Extensions

Opus can dynamically generate addon extensions, enhancing your application's functionality in real-time based on your evolving needs.

### Human-AI Collaboration

Collaborate with AI systems to augment human capabilities, improving productivity and effectiveness in your workflows.

## Contributing

We welcome contributions to improve Opus. If you would like to contribute, please follow the guidelines in our [contributing guide](https://github.com/bluefission/opus/CONTRIBUTING.md).

## License

Opus is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Support

If you have any questions or need support, please open an issue on our [GitHub repository](https://github.com/bluefission/opus/issues).
