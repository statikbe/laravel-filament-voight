# MCP quoted env output

**Date:** 2026-11-26
**Status:** proposed (INTLAR-274)
**Depends on:** `13-mcp-server.md` (`create_project_token`)

## Problem

`create_project_token` returns the uploader variables as a structured object
(`uploader_variables`). MCP clients render that as bare `KEY=value` lines, and
users paste those into a `.env` file or a shell. Sanctum tokens look like
`3|VigEbs1R...`. The `|` is a shell metacharacter, so an unquoted
`VOIGHT_API_TOKEN=3|Vig...` is parsed as a pipe by a shell and can break dotenv
parsing. Quoting the values fixes it.

## Decision

Keep `uploader_variables` exactly as it is (raw values, machine readable, the
existing test asserts `VOIGHT_API_TOKEN` equals `token`). Add one new output
field, `uploader_env`: a ready-to-paste string with one `KEY="value"` line per
variable, in the order `VOIGHT_API_BASE_URL`, `VOIGHT_API_TOKEN`,
`PROJECT_CODE`.

Rejected: putting the quotes inside the `uploader_variables` values. That
changes the value contract for any client that reads the object and would make
the token unusable as a header value.

Plain double quotes, matching the repo's own `.env` files. Values never contain
`"`, `\`, `$` or a backtick: the token is `id|alphanumeric`, the URL is a
`url('/')` result, the project code is a short slug. No escaping is added.
`shortcut: no escaping, revisit if project codes ever allow those characters.`

The `instructions` text points at `uploader_env` as the paste-ready block, so
clients show it instead of re-deriving unquoted lines. The `token` field is
unchanged.

## Changes

1. `src/Mcp/Tools/CreateProjectTokenTool.php`
   - `outputSchema()`: add `'uploader_env' => $schema->string()->required()->description('Paste-ready .env lines, values double-quoted.')`.
   - `run()`: build `$variables` once, reuse it for `uploader_variables`, and
     add `uploader_env` from it via a single `collect($variables)->map(fn ($value, $key) => "{$key}=\"{$value}\"")->implode("\n")`.
   - `instructions`: mention that `uploader_env` is the paste-ready block.
2. `tests/Mcp/WriteToolsTest.php` (`issues a working project token ...`): assert
   `uploader_env` contains `VOIGHT_API_TOKEN="<token>"`, `PROJECT_CODE="MINE"`
   and a quoted `VOIGHT_API_BASE_URL`.
3. `specs/13-mcp-server.md` (`create_project_token` Out list): add `uploader_env`.
4. `src/Mcp/Prompts/OnboardProjectPrompt.php`: no change. It names variables,
   not formatted lines.

## Verification

Run `vendor/bin/pest tests/Mcp --no-coverage`, then `vendor/bin/pint --dirty`
and phpstan. The existing tests must still pass unchanged apart from the one
extended test.

## Out of scope

- Quoting in `README.md` (line 150, the example for humans). Can be a follow-up.
- Changing how the token is created or its format.
- `voight.sh`, which reads variables from the environment and is not affected.
