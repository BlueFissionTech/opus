#!jenss

use @domain from intelligence.domain;
use @json from develation.data;
use @statement from intelligence.statement;
use @assessment, @feedback, @policy from intelligence.feedback;

$contractData
set $contractData to @json: /parse "examples/jenss/fixtures/opus-runtime-contract.json";

@contract
set @contract to @domain: /make $contractData;

@addon
set @addon to @contract: /resource "addons", "contract-addon";

@theme
set @theme to @contract: /resource "themes", "admin-theme";

$addonStatus
set $addonStatus to @addon: /status;

$themeStatus
set $themeStatus to @theme: /status;

$addonAvailability
set $addonAvailability to @addon: /availability;

$themeAvailability
set $themeAvailability to @theme: /availability;

@addonSignal
set @addonSignal to @statement: /make "addon", "requests", "activation", "may", "runtime";
@addonSignal: /source "opus:addon-lifecycle";
@addonSignal: /confidence $addonAvailability;
@addonSignal: /evidence ["status", "availability", "capability_count"];

@themeSignal
set @themeSignal to @statement: /make "theme", "supports", "admin surface", "should", "runtime";
@themeSignal: /source "opus:addon-lifecycle";
@themeSignal: /confidence $themeAvailability;
@themeSignal: /evidence ["status", "availability", "token_count"];

@reviewPolicy
set @reviewPolicy to @policy: /make 0.86, 5;
@reviewPolicy: /sensitive "activation";

@activationDecision
set @activationDecision to @reviewPolicy: /decide @addonSignal, "activation", 1;

$review
set $review to @activationDecision: /status;

$reviewReason
set $reviewReason to @activationDecision: /reason;

@gate
set @gate to @assessment: /make 1, $addonAvailability, "addon_activation_gate";

@history
set @history to @feedback: /make;

if $addonStatus is "candidate" then
    @history: /positive "addon:review-required", 1;

if $themeStatus is "active" then
    @history: /positive "theme:ready", 1;

$reviewScore
set $reviewScore to @history: /score "addon:review-required";

$themeScore
set $themeScore to @history: /score "theme:ready";

$addonSummary
set $addonSummary to @addonSignal: /explain;

$themeSummary
set $themeSummary to @themeSignal: /explain;

$gateSummary
set $gateSummary to @gate: /explain;

say "Addon status: `$addonStatus`";
say "Theme status: `$themeStatus`";
say "Review: `$review`";
say "Reason: `$reviewReason`";
say "Addon score: `$reviewScore`";
say "Theme score: `$themeScore`";
say "Gate: `$gateSummary`";
say "Addon signal: `$addonSummary`";
say "Theme signal: `$themeSummary`";
