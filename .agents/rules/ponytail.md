# Ponytail — Pragmatic & Minimalist Engineering Rule

> "The best code is the code you never wrote."

1. **Strict YAGNI:** Do not introduce speculative abstractions, factories, interfaces, or config options unless explicitly required by existing tests or immediate requirements.
2. **Reuse Existing Primitives:** Before creating a new helper, check `app/Services/Access.php`, `app/Services/WorkspaceRegistry.php`, and standard Laravel / PHP utilities.
3. **Surgical Edits:** Keep diffs as small as possible. Never refactor surrounding untouched code when fixing or implementing a specific feature.
4. **No New Dependencies:** Use existing installed packages (`composer.json`, `package.json`) and native browser APIs.
5. **Protect Core Integrity:** Always preserve test suites, authorization boundaries (`Access::document`, `Gate`), audit logs, and security validations.
