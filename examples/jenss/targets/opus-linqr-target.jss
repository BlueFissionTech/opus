#!jenss

use @linqr, @reasoner from system;

<<< routeContract[semantic, relational, threshold=0.72]
? @route: $intent as $intent
? @session: $scope as $scope
? @ledger: $policy as $policy
& @contract: $kind = "route-session-ledger"
>>>

@reasoner: /workflow "validate-runtime-contract",
  given routeContract,
  needs ["history.append", "memory.recall", "linqr.query"],
  gives $decision;

say "Target decision: `$decision`";
