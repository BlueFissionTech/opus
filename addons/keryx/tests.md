# Validation

Use the host's approved installed dependencies; these commands do not install or
upgrade packages:

```sh
php vendor/bin/phpunit -c addons/keryx/phpunit.xml --do-not-cache-result
php vendor/bin/phpunit --filter 'Keryx(Read|GatewayTransport)ContractTest' --do-not-cache-result
php bin/opus-addon.php validate addons/keryx package
```

The authority fixtures require no daemon, credentials, database or provider.
Transport fixtures require `ext-curl`, process spawning and an available
ephemeral loopback port. They start and stop only their own PHP HTTP process;
requests never leave loopback. Transport tests explicitly skip without curl.

`KERYX_FIXTURE_MODE` and `KERYX_FIXTURE_REQUESTS` are synthetic fixture-only
variables passed to that child process. Do not add them to a host `.env`.
There are no production environment variables or secrets read by this package.
Production origin and authentication remain explicit trusted-host configuration.

Passing these tests is not authorization to contact a real daemon, expose a
remote endpoint, execute a mutation or deploy a service. The full host suite must
also pass before promotion; native Engine route-registration failures are not
waived by the package's direct Application registration tests.
