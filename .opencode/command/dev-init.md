---
description: Analyze the current project, gather verified project knowledge, and create or update docs/agents.md.
agent: build
---

# Dev Initialization

## Goal

Analyze the current project, gather verified project knowledge, and create or update `docs/agents.md`.

The purpose is to:

1. Support any programming language, framework, library, or platform supported by the available environment.
2. Reduce repeated repository exploration and unnecessary token usage.
3. Provide reusable project knowledge for all development agents and skills.
4. Keep project knowledge accurate as the codebase evolves.
5. Establish consistent architecture, coding, testing, security, and UI implementation practices.
6. Avoid unnecessary full-project scans during normal development tasks.

## When to Use

Run `/dev-init` when:

1. Setting up the development workflow in a project for the first time.
2. Starting work in a new repository.
3. `docs/agents.md` is missing or incomplete.
4. The project architecture changes significantly.
5. A new application, service, framework, or major dependency is introduced.
6. Development commands, testing tools, or important conventions change.
7. Existing project knowledge appears outdated or conflicts with the current repository.

Do not run a complete initialization before every feature or bug-fix task.

For normal tasks, agents should reuse `docs/agents.md` and inspect only the files relevant to their assigned work.

## Step 1: Inspect the Project

1. Identify the repository root.
2. Read applicable project instructions, such as `CLAUDE.md`, `AGENTS.md`, and relevant development documentation.
3. Inspect the top-level project structure.
4. Identify applications, services, packages, and important modules.
5. Detect the actual programming languages, frameworks, libraries, databases, and runtimes.
6. Inspect relevant dependency manifests, lockfiles, and configuration files.
7. Identify the existing architecture and coding conventions.
8. Identify available development, build, test, lint, and static-analysis tools.
9. Check whether `docs/agents.md` already exists.
10. Identify existing documentation that should be reused.

Do not assume that the project uses a particular programming language, framework, database, or architecture.

Use repository evidence as the primary source of truth.

Do not dump the entire repository, dependency tree, or configuration into the context.

Inspect only the information needed to understand the project.

## Step 2: Select the Initialization Strategy

Choose the appropriate strategy based on the current state of the project.

### First-Time Initialization

If `docs/agents.md` does not exist:

1. Perform a structured project discovery.
2. Identify the major applications and technologies.
3. Gather the important architecture and development conventions.
4. Identify relevant development and verification commands.
5. Create `docs/agents.md` with verified knowledge.
6. Record unknowns and verification limitations.

### Incremental Initialization

If `docs/agents.md` already exists:

1. Read the existing knowledge file.
2. Preserve useful information that remains accurate.
3. Inspect relevant project instructions and configuration files.
4. Check whether important documented facts have changed.
5. Refresh only the sections affected by those changes.
6. Add missing information when it is useful.
7. Remove or correct outdated information.
8. Avoid rewriting unchanged sections unnecessarily.

### Full Refresh

Perform a broader discovery when:

1. The repository structure has changed significantly.
2. The project has been migrated to a different technology stack.
3. Existing knowledge is substantially outdated.
4. The project contains major new applications or services.
5. The existing knowledge file is unreliable or incomplete.
6. Incremental inspection cannot resolve important inconsistencies.

Do not perform a full refresh simply because a new feature or bug-fix task has started.

## Step 3: Gather Project Knowledge

Collect relevant information from the following areas.

### 3.1 Project Overview

- Project name and purpose.
- Main applications and services.
- Important modules and responsibilities.
- Repository organization.

### 3.2 Technology Stack

- Programming languages.
- Frameworks and libraries.
- Runtime and compiler versions, when verifiable.
- Package managers and dependency management.
- Databases and storage systems.
- Cache and queue systems.
- Build tools and development environments.

### 3.3 Architecture

- Application entry points.
- Main modules and their responsibilities.
- Architectural patterns.
- Service and repository patterns.
- API organization.
- Authentication and authorization.
- Data access patterns.
- Communication between applications and services.
- Existing architectural constraints.

### 3.4 Backend and API

When applicable, identify:

- Routes and endpoints.
- Controllers and handlers.
- Services and repositories.
- Request validation and error handling.
- Authentication and authorization conventions.
- API response formats.
- Background jobs and scheduled tasks.
- Integration and webhook patterns.

### 3.5 Database

When applicable, identify:

- Database technology.
- Models and important entities.
- Migration conventions.
- Relationships and data access patterns.
- Transaction and data integrity practices.
- Test database configuration.

Do not expose database credentials or copy sensitive production data.

### 3.6 Frontend and UI

When applicable, identify:

- Frontend frameworks and component architecture.
- Pages, views, templates, and shared components.
- State management.
- Styling approach and design system.
- Asset and image locations.
- Form validation and interaction patterns.
- Responsive design conventions.
- Existing UI testing and screenshot comparison tools.

Document existing UI conventions that help maintain consistency and achieve accurate reference-based implementations.

When reference screenshots or designs exist, document where they are stored and how the project verifies visual changes.

Do not assume a particular UI framework or design system.

### 3.7 Coding Standards

Identify existing conventions for:

- Naming and file organization.
- Formatting and linting.
- Error handling.
- Input validation.
- Logging.
- Dependency injection.
- Reusable components and utilities.
- Documentation.
- Backward compatibility.

Prefer established project conventions over introducing new ones without justification.

### 3.8 Testing and Verification

Identify:

- Unit testing frameworks.
- Integration and feature testing frameworks.
- End-to-end testing tools.
- Build and compilation commands.
- Linting and static-analysis commands.
- Type-checking commands.
- UI and browser testing tools.
- Existing test organization.
- Known verification limitations.

Document commands only when supported by repository evidence.

Distinguish between commands discovered in documentation and commands actually executed successfully.

### 3.9 Security and Integrations

Identify relevant conventions for:

- Authentication and authorization.
- Secret and environment-variable management.
- Input validation.
- Sensitive data handling.
- File uploads and filesystem access.
- External API integrations.
- Webhooks and third-party services.
- Dependency security.
- Logging and error reporting.

Never copy passwords, access tokens, private keys, or other secrets into `docs/agents.md`.

### 3.10 Project-Specific Rules

Identify:

- Important development restrictions.
- Existing functionality that must be preserved.
- Compatibility requirements.
- Deployment considerations.
- Known architectural limitations.
- Required testing procedures.
- Documentation that must be updated when behavior changes.

Expand the investigation only when the available evidence is insufficient.

## Step 4: Assign Confidence Levels

Record the confidence level of important project facts.

Use these labels consistently:

- **VERIFIED:** Directly confirmed from source code, configuration, or other authoritative repository evidence.
- **EXECUTION-VERIFIED:** Confirmed by actually executing the relevant command or test and observing its result.
- **INFERRED:** A reasonable interpretation of available evidence that has not been fully confirmed.
- **UNKNOWN:** Insufficient evidence to establish the fact.

Examples:

- Framework: `VERIFIED — identified from the dependency manifest.`
- Test command: `VERIFIED — documented in project configuration.`
- Test result: `EXECUTION-VERIFIED — command executed successfully.`
- External service behavior: `UNKNOWN — implementation exists, but live behavior was not verified.`

Rules:

1. Do not label an unexecuted test as execution-verified.
2. Do not treat assumptions as established facts.
3. Use the strongest available evidence.
4. Record uncertainty only when it affects future development or verification.
5. Avoid excessive confidence labels for trivial details.
6. Recheck important facts when conflicting evidence appears.

## Step 5: Create or Update docs/agents.md

Create the `docs` directory if it does not exist.

Create or update `docs/agents.md` using the following structure:

# Project Knowledge for Development Agents

## 1. Project Overview

## 2. Technology Stack

## 3. Repository Structure

## 4. Architecture and Modules

## 5. Backend and API Conventions

## 6. Database and Data Access

## 7. Frontend and UI Conventions

## 8. Coding Standards

## 9. Development Commands

## 10. Testing and Verification

## 11. Security Considerations

## 12. External Integrations

## 13. Important Project Rules

## 14. Verified Facts and Unknowns

## 15. Maintenance Information

Populate only relevant sections.

For irrelevant sections, write `Not applicable` or omit unnecessary details.

Keep the document concise, factual, and easy to scan.

Prefer short bullet points and accurate file paths over lengthy explanations.

Record confidence levels where they materially affect reliability.

Do not duplicate entire source files, dependency manifests, documentation pages, or large configuration files.

If `docs/agents.md` already exists:

1. Preserve useful and accurate information.
2. Verify facts relevant to the current initialization.
3. Update only affected sections whenever possible.
4. Remove outdated or incorrect information.
5. Avoid rewriting unchanged sections unnecessarily.
6. Preserve useful project-specific rules.

Do not overwrite existing knowledge blindly.

## Step 6: Apply Token Efficiency Rules

Follow these requirements throughout initialization and subsequent maintenance.

1. Reuse existing project documentation when accurate.
2. Inspect targeted files instead of dumping the entire repository.
3. Avoid repeatedly scanning the same directories.
4. Read relevant sections instead of repeatedly loading entire files.
5. Reuse previous findings when the evidence remains valid.
6. Avoid collecting information unrelated to development tasks.
7. Store reusable facts concisely.
8. Avoid duplicating information already documented elsewhere.
9. Perform incremental updates when possible.
10. Do not repeat identical commands or investigations without a reason.
11. Avoid unnecessary agent handoffs and repeated explanations.
12. Never sacrifice correctness, security, or required verification to save tokens.

The objective is to reduce repeated investigation, not to eliminate necessary investigation.

## Step 7: Establish Knowledge Reuse for All Agents

All development agents and skills must follow these rules:

1. Before exploring the repository, check whether `docs/agents.md` exists.
2. Read only the sections relevant to the assigned task.
3. Reuse verified project knowledge whenever possible.
4. Verify potentially outdated or conflicting information against the current repository.
5. Inspect additional source files when required for the task.
6. Avoid repeated repository-wide searches when existing knowledge is sufficient.
7. Do not treat `docs/agents.md` as a replacement for inspecting relevant source code.
8. Report important architecture or convention changes to the orchestrator.
9. Update affected knowledge when a completed task changes documented architecture or development conventions.
10. Keep agent-specific responsibilities and JSON protocols in their existing instruction files rather than duplicating them in `docs/agents.md`.

If the knowledge file is missing, gather enough project context to work safely and report that initialization is needed.

Do not block an otherwise safe task merely because optional documentation is missing.

## Step 8: Keep Knowledge Fresh

Update project knowledge when a task changes:

- Application or module structure.
- Technology stack or major dependencies.
- Development or build commands.
- Testing frameworks or verification procedures.
- Architecture or important integration boundaries.
- Security requirements.
- Shared UI conventions.
- Important project-specific rules.

Update only the affected sections unless a broader refresh is necessary.

Do not update `docs/agents.md` merely to record every small source-code edit.

If no knowledge update is necessary, do not rewrite the file.

## Step 9: Safety Requirements

1. Do not store secrets or sensitive credentials.
2. Do not modify application source code during initialization unless explicitly requested.
3. Do not execute destructive commands merely to inspect the project.
4. Do not run production migrations or deployments.
5. Do not change dependencies or project configuration merely to gather knowledge.
6. Do not invent commands, architecture, test results, or integrations.
7. Do not delete useful existing documentation.
8. If evidence conflicts, investigate before changing important project knowledge.
9. Report environmental limitations honestly.

## Step 10: Return the Result

Provide a concise report containing:

- Project name.
- Detected technology stack.
- Main applications and architecture.
- Knowledge file created or updated.
- Important development and testing commands discovered.
- Significant project conventions identified.
- Sections that remain unverified.
- Important security or configuration concerns.
- Whether initialization was full or incremental.
- Recommended next step.

Do not start implementing a feature merely because initialization has completed.

Do not claim that tests, builds, or commands passed unless their results have been verified.

## Completion Criteria

Initialization is complete when:

1. The repository has been inspected sufficiently to establish its main technologies and structure.
2. `docs/agents.md` exists and contains useful, evidence-based project knowledge.
3. Existing valid knowledge has been preserved during updates.
4. Important facts have appropriate confidence labels.
5. Unknowns and verification limitations are disclosed.
6. No secrets or sensitive credentials have been included.
7. The document is concise and reusable.
8. Agents can use the document to avoid unnecessary repeated discovery.

If these conditions cannot be satisfied, report what remains incomplete and why.
