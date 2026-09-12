#!jenss

use @domain from intelligence.domain;
use @json from develation.data;
use @goal from intelligence.goal;
use @statement from intelligence.statement;
use @holoscene, @narrative from intelligence.comprehension;

$contractData
set $contractData to @json: /parse "examples/jenss/fixtures/opus-runtime-contract.json";

@contract
set @contract to @domain: /make $contractData;

$hostName
set $hostName to @contract: /scenarioName;

$goalName
set $goalName to @contract: /goalName;

$routeCount
set $routeCount to @contract: /resourceCount "routes";

$sessionCount
set $sessionCount to @contract: /resourceCount "sessions";

$ledgerCount
set $ledgerCount to @contract: /resourceCount "ledgers";

@route
set @route to @contract: /resource "routes", "admin.dashboard";

@session
set @session to @contract: /resource "sessions", "operator-session";

@ledger
set @ledger to @contract: /resource "ledgers", "route-ledger";

$routeStatus
set $routeStatus to @route: /status;

$sessionStatus
set $sessionStatus to @session: /status;

$ledgerStatus
set $ledgerStatus to @ledger: /status;

@routeStatement
set @routeStatement to @statement: /make "route", "declares", "deterministic surface contract", "must", "runtime";
@routeStatement: /source "opus:jenss-proof";
@routeStatement: /confidence 0.91;
@routeStatement: /evidence ["route_id", "intent", "ledger_policy"];

@sessionStatement
set @sessionStatement to @statement: /make "session", "carries", "opaque actor context", "must", "runtime";
@sessionStatement: /source "opus:jenss-proof";
@sessionStatement: /confidence 0.88;
@sessionStatement: /evidence ["scope", "risk", "resume"];

@ledgerStatement
set @ledgerStatement to @statement: /make "ledger", "records", "append-only route event", "must", "runtime";
@ledgerStatement: /source "opus:jenss-proof";
@ledgerStatement: /confidence 0.9;
@ledgerStatement: /evidence ["sequence", "parent", "surface"];

$routeSummary
set $routeSummary to @routeStatement: /explain;

$sessionSummary
set $sessionSummary to @sessionStatement: /explain;

$ledgerSummary
set $ledgerSummary to @ledgerStatement: /explain;

@story
set @story to @narrative: /make "Opus runtime host contract proof.";
@story: /setPlace $hostName;
@story: /setTime "2026-05-28 12:00:00";
@story: /addTag "opus";
@story: /addTag "runtime-contract";
@story: /addFact "Goal: `$goalName`";
@story: /addFact "Routes: `$routeCount`, sessions: `$sessionCount`, ledgers: `$ledgerCount`";
@story: /addFact "Route status: `$routeStatus`";
@story: /addFact "Session status: `$sessionStatus`";
@story: /addFact "Ledger status: `$ledgerStatus`";
@story: /addFact "Route statement: `$routeSummary`";
@story: /addFact "Session statement: `$sessionSummary`";
@story: /addFact "Ledger statement: `$ledgerSummary`";

$card
set $card to @story: /compose;

@scene
set @scene to @holoscene: /make;
@scene: /push "opus_runtime_contract", $card;

$sceneSummary
set $sceneSummary to @scene: /explain;

say "Host: `$hostName`";
say "Goal: `$goalName`";
say "Route status: `$routeStatus`";
say "Session status: `$sessionStatus`";
say "Ledger status: `$ledgerStatus`";
say "Comprehension: `$sceneSummary`";
say "`$card`";
