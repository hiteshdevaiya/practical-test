---
name: sdlc
description: Plans, implements, tests, and verifies software features across any programming language or framework. Use for new features, complex changes, multi-file tasks, architectural changes, and coordinated development work.
---

# SDLC — Software Development Lifecycle

## Goal

Deliver a maintainable, secure, tested implementation that satisfies the user's requirements while minimizing unnecessary changes and token usage.

Follow:

UNDERSTAND → PLAN → IMPLEMENT → TEST → FIX → QUALITY CHECK → VERIFY → REPORT

Do not declare completion merely because code was written.

## 1. Understand the Requirement

1. Identify the requested behavior and expected outcome.
2. Define measurable acceptance criteria for significant requirements.
3. Identify affected modules, platforms, APIs, UI screens, database structures, and integrations.
4. Identify backward compatibility requirements, constraints, and risks.
5. Ask questions only when missing information materially blocks correct implementation.
6. Otherwise, inspect the project and document reasonable assumptions.

Do not expand the task beyond the requested scope without justification.

## 2. Load Project Knowledge

1. Check `docs/agents.md`, if it exists.
2. Read only sections relevant to the task.
3. Follow applicable repository instructions such as `CLAUDE.md`, `AGENTS.md`, or equivalent files.
4. Inspect source files and configuration when existing knowledge is insufficient or potentially stale.
5. Reuse findings established during the current task.
6. Do not repeat successful discovery without a clear reason.
7. If project knowledge is missing, continue using available repository evidence.

Update `docs/agents.md` only when initialization is requested or reusable project knowledge needs to be recorded.

Never assume a specific programming language, framework, database, or architecture before inspecting the project.

## 3. Plan the Work

Use the Planner Agent for non-trivial features or work involving multiple components.

The plan should identify:

- Requirements and acceptance criteria.
- Relevant files and modules.
- Tasks and responsible agents.
- Dependencies and execution order.
- Tasks that can safely run in parallel.
- Testing and verification strategy.
- Security, compatibility, and operational risks.
- Assumptions and unresolved questions.

For small, isolated changes, use a proportionate plan rather than unnecessary delegation.

### Task Protocol

Preserve the existing structured JSON protocol.

Planner output must follow the existing schema:

- `task_id`
- `from: "planner"`
- `to: "orchestrator"`
- `type: "PLAN"`
- `tasks`

Each task should contain:

- `task_id`
- `assignee`
- `message`
- `requirements`
- `dependencies`
- `priority`

Forward task requirements without silently changing their meaning.

## 4. Delegate and Implement

Select agents according to the task and available capabilities.

- **Planner:** Analyzes requirements and prepares implementation plans.
- **Developer:** Implements business logic, APIs, services, data access, and non-UI functionality.
- **UI:** Implements presentation-layer changes.
- **Debugger:** Investigates failures and determines root causes.
- **Tester:** Verifies behavior and reports reproducible failures.
- **Security:** Performs dedicated security assessments when required.

These responsibilities do not mean every task requires every agent.

### Implementation Rules

1. Follow the existing architecture and coding conventions.
2. Make the smallest complete change that satisfies the requirements.
3. Reuse existing components, services, utilities, and dependencies where appropriate.
4. Avoid unnecessary dependencies, duplicate implementations, and unrelated refactoring.
5. Preserve existing behavior unless the requirement explicitly changes it.
6. Validate inputs and handle errors appropriately.
7. Avoid hardcoded environment-specific configuration and exposed secrets.
8. Update relevant tests and documentation when necessary.
9. Never claim an agent, command, or test ran unless it actually did.
10. Investigate unexpected behavior rather than hiding errors or weakening tests.

### UI and Design Accuracy

When implementing UI changes:

1. Detect the actual UI framework and component library.
2. Inspect existing screens, components, assets, styles, and responsive behavior.
3. Use supplied screenshots or design references when available.
4. Match layout, spacing, typography, colors, sizing, and interactions as closely as practical.
5. Reuse existing components and design tokens.
6. Run the application and compare rendered output when the environment supports it.
7. Record the actual level of visual verification.

Use the verification levels defined in Section 8.

Never claim pixel-perfect accuracy without appropriate visual comparison.

## 5. Testing

After implementation, invoke the shared `testing` skill.

The testing scope must reflect the requirements and risks of the change.

Test applicable areas:

- Positive scenarios.
- Invalid inputs and validation failures.
- Boundary and edge cases.
- Authentication and authorization.
- Error handling and failure recovery.
- API, database, UI, and integration behavior.
- Regression scenarios.
- Relevant linting, static analysis, type checking, and build checks.

Do not run every category for every task. Select tests according to risk and scope.

The testing skill must distinguish actual execution results from assumptions and unexecuted checks.

## 6. Failure Handling and Retry Limits

When a test or verification step fails:

1. Capture the failure and available evidence.
2. Determine whether it is related to the implementation, an existing defect, or the environment.
3. Delegate root-cause investigation to the Debugger Agent when appropriate.
4. Have the responsible Developer or UI Agent implement the correction.
5. Invoke the `testing` skill again to verify the fix and relevant regressions.
6. Repeat the quality check for affected changes.

### Retry Policy

- Allow up to **3 fix attempts per distinct root cause by default**.
- A fix attempt must include a meaningful change based on the previous failure's evidence.
- Before another attempt, identify what failed and what will change.
- Do not repeat an unchanged attempt without new evidence.
- A different, independently identified root cause may have its own retry budget.
- Stop when the default limit is reached and the problem remains unresolved.
- Report the failure evidence, attempted corrections, current state, and recommended next step.
- Continue beyond the default limit only when explicitly authorized or permitted by the established workflow and justified by new evidence.

The retry limit does not permit hiding failures, skipping required checks, or declaring incomplete work successful.

## 7. Security Assessment

Use the Security Agent for security-sensitive changes, including:

- Authentication, authorization, roles, and permissions.
- Payment processing, financial transactions, and wallet balances.
- Untrusted input reaching databases, shells, templates, external services, or LLM instruction boundaries.
- File uploads, filesystem paths, network requests, and webhooks.
- Secrets, sessions, sensitive data, and access-control boundaries.

Follow the Security Agent's existing protocol.

If critical or high-severity findings remain, do not declare the task complete.

Forward actionable findings to the responsible implementation agent, verify the corrections, rerun relevant tests, and repeat the security assessment when needed.

Do not duplicate a full security assessment for unrelated changes.

## 8. Code Quality and Verification Levels

After relevant tests pass, perform a focused code-quality check. Do not invoke a separate `code-review` skill.

### Focused Quality Check

Inspect changed files and relevant surrounding code for:

1. Correctness and acceptance-criteria coverage.
2. Consistency with architecture and coding conventions.
3. Unnecessary complexity, duplicated logic, and unrelated changes.
4. Hardcoded configuration, temporary debugging code, and accidental logging.
5. Error handling, input validation, and data integrity.
6. Backward compatibility and potential regressions.
7. Appropriate test coverage.
8. Necessary documentation updates.

If issues are found:

1. Forward findings to the responsible Developer or UI Agent.
2. Apply the required corrections.
3. Invoke the `testing` skill again.
4. Recheck the affected changes.

### Verification Levels

Report the highest level actually achieved for each relevant verification area.

**Level 1 — IMPLEMENTED**

- Code changes have been made.
- No claim is made that tests or runtime behavior were verified.

**Level 2 — FUNCTIONALLY VERIFIED**

- Relevant tests or manual scenarios were actually executed.
- Observed behavior met the stated expectations for the tested scope.

**Level 3 — VISUALLY VERIFIED**

- The UI was rendered and visually compared against the reference when a reference was provided.
- Relevant layout and interaction checks were performed where applicable.

**Level 4 — PIXEL-DIFFERENCE VERIFIED**

- An actual screenshot or image comparison was performed using an appropriate comparison method.
- The compared reference, screen state, and limitations are identified.
- Any remaining differences are reported.

These levels describe different kinds of evidence, not interchangeable quality scores. For non-UI work, visual levels may not apply.

Never infer a higher verification level from a lower one.

Also report automated tests, manual tests, static analysis, builds, and security assessments separately when applicable.

## 9. Risk-Based Testing

Select test depth according to potential impact and likelihood of failure.

Examples:

- **Low risk:** Copy changes or isolated styling adjustments. Verify the changed area and relevant behavior.
- **Medium risk:** Business logic, API changes, or shared components. Run focused tests and relevant regressions.
- **High risk:** Authentication, permissions, database migrations, payments, wallets, and cross-application contracts. Verify important positive, negative, boundary, failure-recovery, and regression scenarios.
- **Critical impact:** Operations involving production data, financial transfers, or irreversible external side effects require explicit safety precautions and appropriate authorization.

These categories guide test selection; they do not replace project-specific risk analysis.

Never execute destructive tests against production data.

## 10. Final Verification

Before completion:

1. Confirm acceptance criteria against the implementation.
2. Verify that relevant tests were executed and their results recorded.
3. Check for unintended changes and temporary files.
4. Confirm required security findings have been handled.
5. Verify documentation changes when applicable.
6. Report blocked checks and remaining concerns.
7. Confirm the final status reflects the actual evidence.

A passing test suite does not prove every acceptance criterion was met.

## 11. Token Efficiency

- Reuse valid project knowledge and findings.
- Inspect targeted files rather than dumping the entire repository.
- Avoid repeating searches, file reads, and commands without a reason.
- Delegate only when delegation adds value.
- Keep instructions and reports concise.
- Prefer focused changes over unnecessary rewrites.
- Run focused tests before broader relevant regression tests.
- Investigate failed commands before retrying them.
- Do not sacrifice essential testing, security, or verification to save tokens.

## 12. Structured Agent Protocols

Preserve existing protocol names, required fields, and status values.

- Planner → `PLAN`
- Developer/UI → `READY_FOR_TEST`
- Tester → `TEST_RESULT`
- Debugger → `FIX_APPLIED`
- Security → `SECURITY_RESULT`

Use the schemas already defined in the agent prompts.

Do not invent replacement schemas or discard required fields.

Agent handoffs must provide enough context for the receiving agent to continue without unnecessary rediscovery.

## 13. Final Report

Provide a concise report containing:

- **Status:** Completed, Completed with Limitations, Blocked, or Incomplete.
- **Implemented:** Main behavior delivered.
- **Files Changed:** Relevant files.
- **Tests:** Actual commands or scenarios and results.
- **Verification Level:** Highest applicable level achieved, with evidence.
- **Quality Check:** Important findings and resolutions.
- **Security:** Results when applicable.
- **Documentation:** Files updated or reason no update was necessary.
- **Remaining Concerns:** Untested scenarios, blockers, or known limitations.

Completion means the requested behavior has been implemented and verified to the extent explicitly reported.
