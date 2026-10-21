# Meridia

Meridia resolves a registered route without invoking it. This package uses native
DevElation Mapping objects, immutable Obj snapshots, Service delegation and
Opus's shared Wise command processor.

The host builds RouteSource and RouteCandidate records from reviewed mappings,
binds the candidate list and a trusted clock through Application::bindArgs, then
resolves and delegates RouteService. Wrap the existing Wise processor with
RouteCommandProcessor and a fresh AgentRuntimeContext resolver. RouteServiceTest
demonstrates actual application registration and behavioral dispatch.

    get meridia-route "list"
    get meridia-route "resolve" "url" "/reports?period=month"
    get meridia-route "explain" "intent" "reports.monthly"
    get meridia-route "validate" "url" "/reports?period=month"
    get meridia-route "dry-run" "intent" "reports.monthly"

Resolve, explain, validate and dry-run share the same resolution result: status,
selected candidate and revision, and effects=none. All are read operations.
They do not test a target's behavior. The source is admitted by the host; this
package does not verify Annex signatures or classify free text.

Only active Meridia users with meridia.read and the candidate's capability can
discover or resolve it. The host supplies application, tenant and actor identity;
command context cannot grant access. A null tenant is a distinct scope.
Authorization and source expiry are checked afresh on each call.

Candidates are bounded to 128 and explicit intent labels to 32 per candidate.
Routes are GET mappings with local literal paths. Query pairs are canonicalized
by key; duplicate keys, arrays, fragments, authorities and malformed percent
escapes reject. Dynamic path parameters, fuzzy matching, external URLs and
non-GET invocation are not implemented by this increment.

Highest declared priority wins; equal priorities abstain. A denied or stale
winner never falls back to a weaker candidate. Aliases cannot loop or cross scope
and recheck capability and expiry at every step. Discovery removes aliases with
unavailable chains. Snapshots contain no callable, credentials or actor identity.
Later invocation must revalidate the source and permissions; a resolution result
is not an execution grant.

The meridia.resolve.result DevElation action reports only version, status and
effects. Observer exceptions cannot change the result; nested resolution and
catalog access are denied while observing. Native Service behavior dispatch is
available for host actions. The passive factory does not install global filters,
replace the router or grant specialist permissions.

CrawlDiscovery exposes a disabled policy with zero request budget. There is no
transport or enabling switch. Live crawling needs origin rules, rate limits,
provenance verification and security tests before an enabling API exists.
Synematic execution workflows, Annex admission, mutation approval, cache
invalidation and service deployment remain gated. No live provider is called.

Run from the containing Opus source checkout:

    php vendor/phpunit/phpunit/phpunit -c addons/meridia/phpunit.xml --do-not-cache-result
    php bin/opus-addon.php validate addons/meridia
