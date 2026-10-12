# Hasim AI Constitution

The constitution is the highest instruction layer of the Hasim AI runtime. It holds
**laws** that bind every AI employee, workspace, channel and model. It is loaded by
`ConstitutionLoader` and placed in `PromptLayer::Constitution`, above policy, employee,
training, context and the customer message.

## Files and load order

The order is fixed in `ConstitutionManifest::standard()`, never by the filesystem:

1. `core.md` – authority order, scope, honest identity, finished replies
2. `truth.md` – business facts come only from trusted application sources
3. `security.md` – data vs instructions, no disclosure of internals or secrets, authorization
4. `privacy.md` – data minimization and customer data boundaries
5. `safety.md` – out-of-scope, harmful and manipulative behavior
6. `handoff.md` – when a person should take over (the application performs it)
7. `actions.md` – no claimed action without a confirmed execution result

`README.md` is documentation and is never loaded. Every other `.md` file in this
directory must be listed in the manifest; an architecture test enforces that.

## What belongs here and what does not

Belongs: short, enforceable laws that are true for every employee and every business.

Does not belong (and has a proper home in later layers):

- conversation examples, scenarios, playbooks, FAQ → Training
- dialect, tone, greeting, sales style, persona → Employee persona and Training
- employee names or employee-specific instructions → Employee records
- product, price, stock or catalog wording → Business services and tools
- provider, model or channel specifics → Model gateway and channel layers

If a law is already stated in one file, reference it instead of restating it.

## Versioning

`constitution_version` is a SHA-256 of the manifest order and each file's normalized
content (UTF-8 BOM removed, line endings unified, outer whitespace trimmed). Changing
any character changes the version; unchanged files keep it, including on CRLF
checkouts. Timestamps and random values are never part of the version. Each prompt
section also carries a short per-file content hash.

The version is recorded in every `AiDecision`, so every reply can be traced to the exact
constitution text it ran under.

## Validation

Loading succeeds completely or throws `ConstitutionException`. There is no partial or
empty fallback. A load fails when:

- the directory or any manifest file is missing, unreadable or not a regular file
- a file resolves outside this directory (including through symlinks)
- a file is empty, not valid UTF-8, or contains executable markers (`<?php`, `<?=`, `<%`)
- a file exceeds `ConstitutionLimits::DEFAULT_MAX_FILE_BYTES`, or all files together exceed
  `ConstitutionLimits::DEFAULT_MAX_TOTAL_BYTES`
- two sections point at the same file or have identical content

Manifest entries accept a plain lowercase `name.md` only, so separators, `..` and
absolute paths cannot be expressed. The directory is set by code, never by input.

## Caching

A loader instance reuses the loaded constitution while every file keeps the same size,
modification time and inode. Any change on disk triggers a full reload and
revalidation, so edits during development and deployments are picked up without a
cache clear. Nothing is stored in the application cache or the database.

## Changing the constitution

- Edit the Markdown files through a normal code change and review. The files are
  versioned in Git, auditable and can be rolled back like code.
- Run the AI test suites before merging; the constitution tests validate structure,
  order, versioning and content boundaries.
- Live conversations, employees, workspace settings and the model never modify these
  files. There is no automatic self-training.
