# ankus

**What can your Composer dependencies do, and did that just change?**

`composer audit` tells you when a dependency has a *known* CVE. It says nothing
when a patch release of a package you trust suddenly learns to run shell
commands, read `~/.aws/credentials`, or phone home from a Composer plugin hook.
That is what supply-chain attacks look like, and in 2026 they reached Packagist.

ankus statically analyzes every package in `vendor/` and reports its
**capabilities**, then lets you lock them. When an update grants a package a
capability it didn't have, CI fails and shows you exactly where.

```
$ ankus check
  ✓ guzzlehttp/guzzle [8.1.0 -> 8.2.0]
  ✗ musth: acme/http-utils [2.3.1 -> 2.3.2]
      + trigger composer-plugin: Acme\Http\Plugin
      + ENV  (reads or changes environment variables)
          getenv() at src/Plugin.php:12 in Acme\Http\Plugin::activate()
      + FILE_READ  (reads files)
          file_get_contents() at src/Plugin.php:13 in Acme\Http\Plugin::activate()
      + NETWORK  (opens network connections)
          file_get_contents() at src/Plugin.php:13 in Acme\Http\Plugin::activate() (URL argument: https://telemetry.example/c?d=)
      + OBFUSCATION  (feeds encoded or constructed strings into a sink)
          getenv() at src/Plugin.php:12 in Acme\Http\Plugin::activate() (argument is an encoded literal: GITHUB_TOKEN)

1 package(s) gained capabilities. Review the evidence above; if it is expected, run `ankus lock` and commit howdah.lock.
```

## Usage

```sh
ankus scan                # capabilities per package (-v for evidence, --json)
ankus lock                # approve what's installed now, writes howdah.lock
ankus check               # exit 1 if any package gained a capability or trigger
ankus diff old/ new/      # compare two versions of one package
```

Commit `howdah.lock`. Run `ankus check` in CI after `composer install`. When
it fails, read the evidence; if the change is expected, `ankus lock` and commit.
The lock stores capability names only, never file locations, so it changes
exactly when a package's powers change.

## Capabilities

| Capability | Meaning |
|---|---|
| `EXEC` | shell commands, `proc_open`, backticks, process control |
| `CODE_EVAL` | `eval`, `create_function`, `include` of `data:`/`php://input`/remote URLs |
| `DYNAMIC_INCLUDE` | `include`/`require` of a path decided at runtime |
| `NETWORK` | sockets, cURL, mail, DNS, URL stream wrappers |
| `FILE_WRITE` / `FILE_READ` | filesystem access (`fopen` decided by its mode) |
| `ENV` | `getenv`, `$_ENV`, secret-looking `$_SERVER` keys |
| `SENSITIVE_PATH` | mentions `.ssh/`, `.aws/credentials`, `auth.json`, `.npmrc`, ... |
| `UNSERIALIZE` | object-injection surface |
| `NATIVE` | FFI, `dl()` |
| `OBFUSCATION` | an encoded or reversed literal reaching a sink, PHP hidden in a `.png` |
| `DYNAMIC_UNRESOLVED` | a function called by a name we could not resolve |
| `UNANALYZABLE` | PHP that does not parse; never silently skipped |

Plus **triggers**: `composer-plugin` (code that runs inside Composer on
install) and `autoload-files` (code that runs on every request).

## How it sees through tricks

ankus resolves the strings that call targets, URLs and include paths are built
from: concatenation and interpolation, `.=` chains, `strrev`, `str_rot13`,
`base64_decode`, `hex2bin`, `gzinflate`, `chr()` chains, `implode`, `sprintf`,
`str_replace`, class constants, property defaults, `define()`, values returned
by the package's own functions and methods, closures and `use`, `match` and
branch unions, `call_user_func`/`array_map`/`ob_start`/`register_shutdown_function`
style callables, `ReflectionFunction`, `Closure::fromCallable` and `system(...)`.

The rule for attribution is **the code that names the sink owns the
capability**. A library calling a `$callback` you passed it is not a library
that runs shell commands. A library that assembles `'sys' . 'tem'`, decodes a
name, or calls something taken from `$_GET` or a file read is, and anything
built that way that can't be resolved is reported as `DYNAMIC_UNRESOLVED`
rather than dropped.

## Does it work?

Claims about security tools should come with numbers. Current state:

**Real attacks** (`corpus/`): every Packagist supply-chain attack of 2026 whose
malicious commits are still recoverable, replayed as the update a victim would
have pulled (clean parent commit -> malicious commit). Expected capabilities
are written from the public write-ups, not from ankus output.

| Attack | `ankus check` | Gained |
|---|---|---|
| intercom/intercom-php 5.0.2, Mini Shai-Hulud (Apr 2026), 2 commits | fails | `composer-plugin` trigger, `EXEC` (`passthru` of the Bun dropper in `onPostInstallOrUpdate()`) |
| laravel-lang/lang tag rewrite (May 2026) | fails | `autoload-files` trigger, `NETWORK` to `https://flipboxstudio.info/payload` (decoded from `array_map('chr', ...)`), `EXEC`, `FILE_WRITE`, `OBFUSCATION` |
| laravel-lang/http-statuses tag rewrite (May 2026), 2 commits | fails | same as above |
| bfunky/http-parser re-tag (May 2026) | fails | `NETWORK` to the base64-hidden `https://44.210.94.38/packagist.php`, `ENV` (full `getenv()` dump), `FILE_WRITE`, `OBFUSCATION` |

**6/6 caught with every expected capability.** `php corpus/run.php` reproduces
this; samples are downloaded by commit SHA and only ever parsed.

The corpus earned its keep on the first run: 6/6 were already flagged, but 4
were missing `OBFUSCATION`, and reading the Laravel-Lang dropper showed a
deeper gap. Its URL reached `file_get_contents()` only through a closure
parameter, so ankus had reported `NETWORK` only because the same closure also
called `curl_init()`. ankus now follows calls into closures, package functions
and same-class methods with the caller's arguments bound whenever an argument
is worth following (a URL, a sink name, a credential path, anything encoded).

**Fixtures** (`tests/fixtures`): 99 files, each declaring the *exact* set of
capabilities it must produce. Extra capabilities fail just like missing ones.

- 65 evasion techniques, all caught (string folding, decoders, `chr` arrays,
  loops, branch unions, constants, method returns, callables, values passed
  through closure and function parameters, include tricks, FFI, ...)
- 24 benign patterns that must stay quiet (`$pdo->exec()`, namespaced
  `exec()`, `use function`, callbacks in params and properties, first-class
  method callables, `[$this, $method]`, framework-style `new $class`, ...)
- 10 plain sinks, so ordinary capabilities are reported correctly
- the "update turns into a plugin and exfiltrates a token" scenario, end to end

**Real-world precision**: a fresh Laravel 13 application (109 packages, 8,092
files, ~21 s, 250 MB):

- 60 of 109 packages have no capabilities at all.
- Every `EXEC`, `CODE_EVAL` and `NATIVE` finding was checked by hand and is
  real (symfony/process, mockery's and phpunit's `eval`, var-dumper's FFI, ...).
- `DYNAMIC_UNRESOLVED`: 9 findings across 5 packages, down from 157 in the
  first run. At least 7 are calls the code genuinely builds or loads at runtime:
  `require Env::get(...)` returning a callable, a closure obtained by `include`
  through a custom stream wrapper, `"is_$mode"()`, `'image' . $name`.
- Following calls with bound arguments added no new capabilities on Laravel.

Every false positive found on real code became a regression fixture.

## Limits

- Static analysis of PHP can't resolve everything. ankus's promise is narrow:
  a built-in sink whose name is decided *inside the package* is either
  resolved or flagged. Names that arrive from a caller are the caller's.
- It answers "can this code now do X". It does not detect a package misusing a
  capability it already had (guzzle sending data to a new host is still just
  `NETWORK`).
- Capabilities are per package, not per reachable path. A capability in a file
  you never load still counts.

## Roadmap

- **More corpus**: older attacks (hautelook/phpass 2022, nhattuanbl's Laravel
  RAT packages 2024) where the malicious code has to be recovered from
  archives rather than GitHub.
- **Dynamic oracle**: run package test suites under an instrumented PHP and
  require every capability observed at runtime to be predicted statically.
- **Historical replay**: a year of real `composer update`s on popular apps,
  hand-labelled, to measure alerts per update.
- **Sandboxed install**: run `composer install` with plugins confined by
  Landlock, so install-time code can't reach the network or your home dir.
- PHAR build, result cache by file hash, SARIF output for code scanning.

## Requirements

PHP 8.2+. The only runtime dependency is `nikic/php-parser`. A supply-chain
tool should not bring a framework's worth of packages into your tree.

## License

MIT
