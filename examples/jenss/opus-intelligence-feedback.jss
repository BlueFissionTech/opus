#!jenss

use @goal, @objective, @kpi from intelligence.goal;
use @feedback from intelligence.feedback;

@proofGoal
set @proofGoal to @goal: /make "Improve reusable Opus contract decisions", "Learn from small correction signals without coupling the script to one caller.";

@objectiveDeclare
set @objectiveDeclare to @objective: /make "Prefer declared contracts over implicit behavior", "quality", "raise", 1;

@kpiCoverage
set @kpiCoverage to @kpi: /make "declared_contract_coverage", 0.67, 0.95, "ratio", "quality";

@contractMemory
set @contractMemory to @feedback: /make;
@contractMemory: /positive "contract:route-session-ledger", 1;
@contractMemory: /positive "contract:addon-theme-lifecycle", 1;
@contractMemory: /negative "contract:implicit-runtime-state", 0.75;
@contractMemory: /correct "contract:implicit-runtime-state", "contract:declared-runtime-state", 1;

$routeScore
set $routeScore to @contractMemory: /score "contract:route-session-ledger";

$lifecycleScore
set $lifecycleScore to @contractMemory: /score "contract:addon-theme-lifecycle";

$implicitScore
set $implicitScore to @contractMemory: /score "contract:implicit-runtime-state";

$declaredScore
set $declaredScore to @contractMemory: /score "contract:declared-runtime-state";

$memorySummary
set $memorySummary to @contractMemory: /explain;

say "Goal: `@proofGoal: $name`";
say "Objective: `@objectiveDeclare: $value`";
say "KPI: `@kpiCoverage: $name` target `@kpiCoverage: $target`";
say "Route/session/ledger score: `$routeScore`";
say "Addon/theme lifecycle score: `$lifecycleScore`";
say "Implicit state score: `$implicitScore`";
say "Declared state score: `$declaredScore`";
say "Feedback: `$memorySummary`";
