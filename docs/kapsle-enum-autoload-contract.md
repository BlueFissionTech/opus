# Kapsle enum autoload contract

The installed Kapsle package must provide distinct tenant-status and log-type
enums in `AddOns\Kapsle\Domain\Enums`. Each enum must resolve from its own
canonical file under `logic/Domain/Enums`, including when Composer's classmap
is authoritative and neither enum has previously been loaded.

`KapsleEnumAutoloadTest` verifies both load orders, canonical classmap entries,
declaration identity, backed values, labels, and value-to-case round trips.
The normal suite uses the host's generated Composer autoloader. CI also
generates an independent authoritative autoloader from the installed package's
own metadata with scripts and plugins disabled, then runs the same tests with
`KAPSLE_AUTOLOAD=addons/kapsle/vendor/autoload.php`. Composer's strict PSR and
ambiguity checks must pass before the package test runs.

This contract prevents a duplicate log enum declaration from replacing the
tenant-status enum in an optimized classmap. It does not establish tenancy,
authentication, storage isolation, or complete add-on readiness. No compatibility
alias or local copy of either upstream enum is supplied.
