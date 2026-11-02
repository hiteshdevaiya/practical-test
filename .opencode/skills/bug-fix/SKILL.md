---
name: bug-fix
description: Reproduces, investigates, fixes, and verifies focused software bugs, regressions, crashes, and incorrect behavior across any programming language or framework.
---

# Bug Fix

## Goal

Resolve the actual root cause of a bug, verify the correction, and ensure related behavior has not regressed.

Follow:

UNDERSTAND → REPRODUCE → INVESTIGATE → FIX → TEST → QUALITY CHECK → REPORT

Do not assume a bug is fixed merely because the code was changed.

## 1. Understand the Bug

Identify:

- Expected behavior.
- Actual behavior.
- Reproduction steps.
- Affected module, platform, API, or user flow.
- Error messages, logs, screenshots, and test failures when available.
- Recent changes that may be related.

If information is missing, inspect the repository and available evidence before asking questions.

Do not invent reproduction steps or claim that a failure was reproduced without evidence.

## 2. Load Project Knowledge

1. Read relevant sections of `docs/agents.md`, if available.
2. Follow applicable repository instructions.
3. Inspect affected code, related tests, and relevant dependencies.
4. Reuse findings and reproduction evidence already collected.
5. Verify documentation against source code when information may be stale.

Do not inspect unrelated modules unless the execution path or evidence requires it.

## 3. Reproduce the Bug

Invoke the shared `testing` skill to reproduce the issue when practical.

Use an appropriate method:

- Existing automated test.
- Focused unit or integration test.
- API request.
- UI interaction.
- Relevant application logs.
- Minimal reproduction in the existing environment.

Record:

- Reproduction steps.
- Expected result.
- Actual result.
- Evidence or observed error.
- Whether the failure was reproduced.

If the issue cannot be reproduced, investigate available evidence and clearly report the limitation.

Do not modify production code merely to suppress an unexplained error.

## 4. Determine the Root Cause

Inspect the relevant execution path and dependencies.

Potential causes include:

- Incorrect conditions or calculations.
- Invalid state transitions.
- Missing validation or error handling.
- Incorrect database queries or persistence.
- API request or response mismatches.
- Authentication or authorization failures.
- Race conditions or asynchronous behavior.
- Configuration or environment differences.
- UI state or lifecycle issues.
- Third-party integration failures.

Use evidence to distinguish a confirmed root cause from a hypothesis.

## 5. Decide Whether to Continue or Escalate

Continue as a focused bug fix when:

- The cause is identifiable or can reasonably be isolated.
- The correction is focused.
- Existing architecture can support the fix.
- Verification is feasible within the task scope.

Escalate to `/sdlc` when the work requires substantial new functionality, architectural changes, a new subsystem, or multiple interdependent features.

A migration, endpoint, or UI component does not automatically require escalation if it is small and necessary. Decide based on complexity, risk, and scope.

If escalation is needed, summarize the evidence, root cause, and required work.

## 6. Plan the Fix

Identify:

- Confirmed or most likely root cause.
- Smallest appropriate correction.
- Files expected to change.
- Required tests.
- Potential regressions.
- Security and data-integrity implications.

Use the Debugger Agent when appropriate for root-cause investigation. Delegate implementation to the responsible Developer or UI Agent according to the existing workflow.

## 7. Implement the Fix

1. Correct the root cause rather than its symptoms.
2. Make the smallest complete change.
3. Preserve unrelated behavior.
4. Follow project conventions.
5. Add or update a regression test when practical.
6. Avoid unnecessary dependencies and unrelated refactoring.
7. Do not weaken tests or remove validation to hide the failure.
8. Do not introduce secrets or insecure workarounds.

## 8. Verify the Fix

Invoke the shared `testing` skill after implementation.

When applicable:

1. Retest the original bug.
2. Verify the expected behavior.
3. Run relevant regression tests.
4. Check important edge cases.
5. Verify error handling and data integrity.
6. Run appropriate linting, static analysis, type checks, or builds.

Record actual results as:

- `PASS`
- `FAIL`
- `BLOCKED`
- `NOT RUN`

If a test fails:

1. Inspect the evidence.
2. Determine whether the failure is related to the fix, an existing issue, or the environment.
3. Correct the issue when within scope.
4. Invoke the `testing` skill again.

Never report an unexecuted test as passing.

## 9. Retry Policy

Allow up to **3 fix attempts per distinct root cause by default**.

For each attempt:

1. Record what failed in the previous attempt.
2. Identify the evidence supporting the next change.
3. Make a meaningful correction.
4. Invoke the `testing` skill.
5. Verify the original bug and relevant regressions.

Do not repeat an unchanged attempt without new evidence.

If three attempts fail to resolve the same root cause:

- Stop automatic retrying.
- Report the attempts and results.
- Identify remaining uncertainty.
- Recommend the next diagnostic step or escalation to `/sdlc`.
- Do not claim the bug is fixed.

A new, independently identified root cause may have its own retry budget.

Continue beyond the default limit only when explicitly authorized or permitted by the established workflow and justified by new evidence.

## 10. Focused Quality Check

After relevant tests pass, inspect the changed files and surrounding code. Do not invoke a separate `code-review` skill.

Check:

- The fix addresses the identified root cause.
- No unrelated files or behavior were changed.
- Existing architecture and conventions are preserved.
- Error handling and validation remain correct.
- No temporary debugging code or accidental logging remains.
- No hardcoded credentials or unsafe configuration were introduced.
- Relevant documentation is updated when necessary.

For security-sensitive fixes, involve the Security Agent when appropriate.

If the quality check identifies an issue, correct it and invoke the `testing` skill again.

## 11. Verification Levels

Report the highest applicable level actually achieved:

**IMPLEMENTED:** The fix was written, but behavior has not been verified.

**FUNCTIONALLY VERIFIED:** The original bug and relevant scenarios were actually tested, with results recorded.

**VISUALLY VERIFIED:** The rendered UI was compared against the relevant reference when one exists.

**PIXEL-DIFFERENCE VERIFIED:** An actual screenshot comparison was performed, and the comparison scope and remaining differences are reported.

Visual levels do not apply to every bug. Never claim a higher verification level than the available evidence supports.

Report automated tests, manual tests, builds, and static analysis separately where applicable.

## 12. Token Efficiency

- Reuse existing reproduction steps and evidence.
- Avoid rereading unchanged files without a reason.
- Inspect the affected execution path rather than the whole repository.
- Prefer existing tests and tooling.
- Keep changes focused.
- Run the original failing test and relevant regressions.
- Avoid repeating successful commands unless the code or environment changed.
- Do not sacrifice essential verification to save tokens.

## 13. Completion Criteria

Mark a bug as fixed only when:

- The root cause is identified or sufficiently established.
- The correction is implemented.
- The original bug is verified as resolved, or limitations are explicitly reported.
- Relevant regression tests have been executed.
- The focused quality check is complete.
- Remaining risks and blocked checks are documented.

## 14. Final Report

Report:

- **Bug:** What was incorrect.
- **Root Cause:** Confirmed cause or remaining uncertainty.
- **Fix:** What changed.
- **Files Changed:** Relevant files.
- **Original Bug Test:** Actual result.
- **Regression Tests:** Actual results.
- **Verification Level:** Highest applicable level achieved.
- **Quality Check:** Findings and resolution.
- **Remaining Concerns:** Outstanding risks or limitations.
- **Final Status:** FIXED, FIXED WITH NOTES, NOT FIXED, or ESCALATED TO SDLC.

### Structured Agent Protocol

When used in an orchestrated workflow, preserve the existing JSON handoff structure:

- `task_id`
- `from: "bugfix"`
- `to: "orchestrator"`
- `type: "BUGFIX_RESULT"`
- `status`
- `root_cause`
- `files_changed`
- `tests_executed`
- `original_bug`
- `regression_tests`
- `remaining_issues`

Use the established status values and schema expected by the orchestrator.

For standalone requests, provide the normal report unless structured output is requested.
