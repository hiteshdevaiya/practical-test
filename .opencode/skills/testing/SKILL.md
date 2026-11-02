---
name: testing
description: Tests software changes, reproduces bugs, verifies acceptance criteria, checks regressions, and performs realistic end-of-day testing across any programming language or framework.
---

# Testing and Verification

## Goal

Verify that the application behaves as expected, identify reproducible failures, and report actual test results honestly.

This skill supports:

- Feature implementation testing.
- Bug-fix verification.
- Regression testing.
- End-of-day testing of completed tasks.
- API, UI, database, and integration testing where applicable.

Do not modify production application code unless explicitly instructed.

## 1. Identify the Testing Scope

Determine the scope from:

1. User requirements and acceptance criteria.
2. The current task and implementation report.
3. Relevant sections of `docs/agents.md`.
4. Changed files and associated tests.
5. Completed tasks recorded in available conversation history, task records, Git history, or work logs.

For end-of-day testing, identify completed tasks for the requested period.

Use available evidence rather than assuming every recent code change was completed that day.

If task history is unavailable, state the limitation. Ask for missing task details when they are necessary to establish a meaningful test scope.

## 2. Understand the Project

1. Read relevant sections of `docs/agents.md`, if available.
2. Follow applicable repository instructions.
3. Identify the actual language, framework, architecture, and test tooling.
4. Locate existing tests, fixtures, factories, mocks, and execution commands.
5. Inspect the changed behavior and its dependencies.
6. Reuse existing test infrastructure where possible.

Do not assume a particular testing framework.

Do not install new dependencies without a clear need and appropriate authorization.

## 3. Assess Risk and Prioritize Tests

Prioritize testing according to the potential impact and likelihood of failure.

### Low Risk

Examples include isolated text changes and minor styling adjustments.

Verify the changed area, relevant interactions, and applicable existing tests.

### Medium Risk

Examples include business logic, API changes, and shared components.

Verify positive and negative scenarios, relevant edge cases, and regressions affecting related modules.

### High Risk

Examples include:

- Authentication and authorization.
- Database schema or persistence changes.
- Payment processing and wallet balances.
- Shared API contracts.
- Cross-application workflows.
- External integrations and webhook processing.

Verify important positive, negative, boundary, error-recovery, and regression scenarios.

### Critical Impact

Operations involving production data, financial transfers, irreversible actions, or external side effects require explicit safety precautions and appropriate authorization.

Use test environments and test accounts whenever possible.

Never execute destructive tests against production data.

These categories guide test selection; they do not replace project-specific risk analysis.

## 4. Build Realistic Scenarios

Select scenarios that match the feature, requirements, and risk.

### A. Positive Scenarios

Verify that valid inputs and expected actions produce the intended results.

Examples:

- A valid form submits successfully.
- An API returns the expected response.
- A user completes the intended workflow.
- A database change persists correctly.
- An integration succeeds with valid configuration.

### B. Negative Scenarios

Verify that invalid or unauthorized actions are handled correctly.

Examples:

- Required fields are missing.
- Inputs contain invalid values.
- An unauthenticated request accesses a protected endpoint.
- A user attempts an operation without permission.
- A dependency returns an error.
- A request contains malformed data.

### C. Boundary and Edge Cases

Test applicable cases such as:

- Minimum and maximum values.
- Empty collections or optional fields.
- Duplicate submissions.
- Repeated requests.
- Missing or stale records.
- Timeouts and interrupted operations.
- Concurrent updates.
- Pagination and large inputs.
- Date, timezone, and currency boundaries.

### D. Regression Scenarios

Check related behavior that could have been affected.

Prioritize:

- Features sharing the changed component.
- APIs or consumers affected by contract changes.
- Existing behavior near the modified logic.
- Previously fixed bugs that could return.
- Critical business workflows.

### E. UI Scenarios

When a UI is involved:

- Check layout, visibility, navigation, and interactions.
- Verify loading, empty, success, and error states where applicable.
- Check validation messages and disabled actions.
- Test relevant screen sizes or platforms.
- Compare against supplied design references when available.
- Use screenshot comparison or visual tools when practical.

### F. Data and Integration Scenarios

When applicable:

- Verify persistence and retrieval.
- Check transaction boundaries and rollback behavior.
- Check API request and response contracts.
- Verify third-party success and failure handling.
- Check duplicate-processing and idempotency behavior.
- Verify that sensitive data is not exposed.

Do not execute every example for every task. Choose scenarios based on actual risk and scope.

## 5. Execute Tests

Use the project's established testing infrastructure.

Run the most relevant tests first, followed by appropriate regression tests.

Depending on the project, this may include:

- Unit tests.
- Integration tests.
- Feature or API tests.
- End-to-end tests.
- UI or widget tests.
- Linting.
- Static analysis.
- Type checking.
- Build or compilation checks.
- Manual scenarios when automated coverage is unavailable.

Record the actual command or procedure used.

If a test cannot run because of missing credentials, unavailable services, environment problems, or missing tooling, report the blocker.

Do not claim that a real integration was tested when only a mock or stub was used.

## 6. Record Results and Evidence

Every relevant test or scenario must have an honest result:

- **PASS:** Executed and met the expected result.
- **FAIL:** Executed and did not meet the expected result.
- **BLOCKED:** Could not execute because of a specific blocker.
- **NOT RUN:** Not executed.

For each failure, record:

- Task ID, when available.
- Scenario or test name.
- Severity.
- Affected file or module, when known.
- Reproduction steps.
- Expected result.
- Actual result.
- Evidence or error output.
- Likely cause, clearly marked as unconfirmed when necessary.

Never fabricate logs, results, reproduction steps, or root causes.

Distinguish observed behavior from inferred explanations.

## 7. Standardize Verification Levels

Report the highest applicable verification level actually achieved.

### Level 1 — IMPLEMENTED

Code has been changed, but behavior has not been verified.

### Level 2 — FUNCTIONALLY VERIFIED

Relevant tests or manual scenarios were actually executed, and the observed results met expectations for the tested scope.

### Level 3 — VISUALLY VERIFIED

For applicable UI work, the rendered UI was compared against the supplied design or screenshot reference.

### Level 4 — PIXEL-DIFFERENCE VERIFIED

For applicable UI work, an actual screenshot or image comparison was performed using an appropriate method. Identify the comparison scope and report meaningful remaining differences.

These levels are not interchangeable. Visual verification is not applicable to every backend or infrastructure task.

Also report automated tests, manual tests, static analysis, builds, and security assessments separately when relevant.

Never infer that a higher level was achieved merely because a lower level passed.

## 8. Handle Failures

When a test fails:

1. Capture the error and reproduction steps.
2. Determine whether it is a new failure, an existing issue, or an environment blocker.
3. Report the failure to the orchestrator.
4. Request the Debugger Agent or `/bug-fix` workflow for focused bug investigation.
5. Escalate broader feature or architectural problems to `/sdlc`.
6. After a correction, rerun the original failing test and relevant regressions.

The Tester does not independently modify production code unless explicitly instructed.

A passing test suite does not prove that every acceptance criterion has been verified.

## 9. Retry and Retest Policy

Testing itself does not repeatedly modify application code.

When a failure requires a correction:

1. Record the evidence from the failed attempt.
2. Route the issue to the responsible debugging or implementation workflow.
3. Require a meaningful correction based on the evidence.
4. Retest the original failure.
5. Run relevant regression tests.
6. Record the new results.

Use the retry policy defined in the calling `/sdlc` or `/bug-fix` workflow.

By default, allow up to **3 fix attempts per distinct root cause** before stopping and reporting the unresolved problem.

Do not rerun unchanged tests repeatedly without a reason.

Repeated test executions may be justified when investigating flaky or intermittent failures; document that purpose and the observed results.

## 10. End-of-Day Testing

When asked to test today's completed tasks:

1. Identify completed tasks using available conversation history, task records, Git changes, and work logs.
2. List the tasks selected for testing.
3. Map each task to realistic user or system scenarios.
4. Prioritize critical workflows and high-risk changes.
5. Run available automated tests and appropriate manual scenarios.
6. Verify related behavior that could have been affected.
7. Record PASS, FAIL, BLOCKED, or NOT RUN for each scenario.
8. Report bugs with reproducible evidence.
9. Retest fixes when they are applied and the workflow permits.
10. Produce a daily testing report with unresolved issues and recommended next steps.

Do not claim end-to-end testing if only one component was tested.

If the day's changes cannot be determined reliably, state what evidence was available and what remains unverified.

## 11. Quality and Security Boundaries

Testing verifies behavior. It does not replace a focused code-quality check or a dedicated security assessment.

When a test reveals a possible security issue:

- Record the evidence.
- Notify the orchestrator.
- Request the Security Agent when appropriate.

Do not expose secrets in reports or logs.

The calling `/sdlc` or `/bug-fix` workflow is responsible for the focused code-quality check after relevant tests pass.

## 12. Token Efficiency

- Reuse existing test commands, fixtures, and scenario definitions.
- Avoid repeating unchanged tests without a reason.
- Start with tests most likely to validate the changed behavior.
- Run broader regression tests according to risk.
- Avoid dumping full logs when a relevant excerpt is sufficient.
- Keep reports concise while preserving reproducible failure details.
- Do not skip critical tests merely to reduce token usage.

## 13. Final Testing Report

Report:

- **Scope:** Tasks and behaviors tested.
- **Environment:** Relevant environment and limitations.
- **Risk Level:** Reason for the selected testing depth.
- **Scenarios:** Results for positive, negative, edge, and regression cases as applicable.
- **Automated Tests:** Commands and actual outcomes.
- **Manual Tests:** Actions performed and observed outcomes.
- **Verification Level:** Highest applicable level achieved.
- **Failures:** Reproduction steps, expected versus actual behavior, and severity.
- **Blocked / Not Run:** Reasons and remaining verification.
- **Overall Result:** PASS, FAIL, or BLOCKED, with an explanation.
- **Next Steps:** Bugs to fix and additional verification required.

Use PASS only when the defined scope has been adequately verified and no unresolved failures remain within that scope.

Otherwise, report FAIL or BLOCKED as appropriate.

### Structured Agent Protocol

When invoked by the SDLC orchestrator, preserve the existing JSON protocol:

- `task_id`
- `from: "tester"`
- `to: "orchestrator"`
- `type: "TEST_RESULT"`
- `status`
- `failures`, when applicable.

Each failure must use the established fields:

- `severity`
- `file`
- `location`
- `reproduction`
- `expected`
- `actual`
- `likely_cause`

Do not silently change the protocol expected by the orchestrator.
