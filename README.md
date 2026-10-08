# ankus

ankus reports what the packages in your `vendor/` directory are able to do
(run shell commands, open network connections, evaluate code, read
environment variables, and so on) and fails when an update gives a package an
ability it did not have before.

`composer audit` warns about *known* vulnerabilities. It cannot warn you when a
patch release of a package you already trust starts reading your credentials
and sending them somewhere, because nobody has filed an advisory yet. That is
what recent supply-chain attacks on Packagist looked like, and it is the case
ankus is built for.

```
$ ankus check
  ✗ acme/http-utils [2.3.1 -> 2.3.2]
      + runs inside Composer on install/update (composer-plugin): Acme\Http\Plugin
      + ENV  (reads or changes environment variables)
          getenv() at src/Plugin.php:12 in Acme\Http\Plugin::activate()
      + FILE_READ  (reads files)
          file_get_contents() at src/Plugin.php:13 in Acme\Http\Plugin::activate()
      + NETWORK  (opens network connections)
          file_get_contents() at src/Plugin.php:13 in Acme\Http\Plugin::activate() (URL argument: https://telemetry.example/c?d=)
      + OBFUSCATION  (passes an encoded or disguised value to a sensitive function)
          getenv() at src/Plugin.php:12 in Acme\Http\Plugin::activate() (argument is an encoded literal: GITHUB_TOKEN)

1 package gained capabilities. Review the evidence above; if it is expected, run `ankus lock` and commit ankus.lock.
```

ankus only reads and parses PHP files. It never includes, loads or runs the
code it analyzes.

## Requirements

- PHP 8.2 or newer, with the `tokenizer` and `json` extensions (enabled in
  most PHP builds)
- Composer, to install ankus
- Optional: `ext-pcntl`, used to analyze packages in parallel and to restart
  PHP without Xdebug. Without it ankus still works, only slower.

## Installation

ankus is not on Packagist yet, and the repository is private, so you need read
access to `dotMavriQ/ankus` on GitHub.

### Standalone (recommended)

Install ankus in its own directory, outside the projects you check:

```sh
git clone git@github.com:dotMavriQ/ankus.git ~/.local/share/ankus
composer install --no-dev --working-dir="$HOME/.local/share/ankus"
ln -s ~/.local/share/ankus/bin/ankus ~/.local/bin/ankus
```

`ankus` is now on your `PATH` (assuming `~/.local/bin` is). Run it from a
project's root directory.

This is recommended because ankus then runs entirely on its own code. When it
is installed inside a project, it uses that project's copy of
`nikic/php-parser`, which is one of the dependencies you are trying to check.

### As a development dependency

```sh
composer config repositories.ankus vcs https://github.com/dotMavriQ/ankus
composer require --dev ankus/ankus:dev-master
vendor/bin/ankus --version
```

Because the repository is private, Composer needs a GitHub token to download
it. If `composer require` fails with a 404, run
`composer config --global github-oauth.github.com <token>` first, or set
`COMPOSER_AUTH`.

Installed this way, ankus does **not** load your project's
`vendor/autoload.php`, so no code from your dependencies runs when you start
it. It requires `nikic/php-parser` 5.x, so Composer will refuse to install it
in a project that is held to an older version; use the standalone install
there.

## Getting started

In the root of a project with a `vendor/` directory:

```sh
ankus scan        # see what each package can do
ankus lock        # approve the current state, writes ankus.lock
git add ankus.lock
```

From then on, after every dependency change:

```sh
ankus check
```

`check` compares what is installed with `ankus.lock`. If a package can now do
something it could not before, it prints where in the code that comes from
and exits with code 1. If the change is expected, run `ankus lock` again and
commit the updated `ankus.lock`.

## Updating dependencies safely

The order of commands matters. A Composer plugin runs **during** `composer
install` and `composer update`, so checking afterwards is too late for an
attack that uses one. Download first with plugins and scripts disabled, check,
and only then let them run:

```sh
composer update --no-plugins --no-scripts   # downloads code, runs none of it
ankus check                                  # review anything it reports
ankus lock                                   # only if the changes are expected
composer install                             # now plugins and scripts run
```

Packages that use `autoload.files` run code whenever `vendor/autoload.php` is
loaded, so also run `ankus check` before your test suite or application starts.

## Using ankus in CI

GitHub Actions, with ankus checked out next to your project as a standalone
tool:

```yaml
- uses: actions/checkout@v4

- name: Check out ankus
  uses: actions/checkout@v4
  with:
    repository: dotMavriQ/ankus
    path: .ankus
    token: ${{ secrets.ANKUS_READ_TOKEN }}

- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.4'

- name: Download dependencies without running any of their code
  run: composer install --no-plugins --no-scripts --no-interaction --no-progress

- name: Install ankus
  run: composer install --no-dev --no-interaction --no-progress --working-dir=.ankus

- name: Check dependency capabilities
  run: php .ankus/bin/ankus check

- name: Finish installing (plugins and scripts run now)
  run: composer install --no-interaction --no-progress
```

`ANKUS_READ_TOKEN` is a repository secret holding a GitHub token that can read
`dotMavriQ/ankus`. It is needed only while that repository is private.

The job fails at the check step if any package gained a capability. The
`ankus.lock` committed in your repository is the approved state.

## Commands

| Command | What it does |
|---|---|
| `ankus scan` | Lists the capabilities of every installed package. `-v` adds the file and line of every finding, `--json` prints machine-readable output. `--path=DIR` scans a single directory instead of `vendor/`. |
| `ankus lock` | Writes the current capabilities to `ankus.lock`. |
| `ankus check` | Compares installed packages with `ankus.lock` and fails if any gained a capability or a trigger. `--json` for machine-readable output. |
| `ankus diff OLD_DIR NEW_DIR` | Compares two versions of one package, given as directories. Useful for reviewing an update before installing it. |
| `ankus --version` | Prints the version. |
| `ankus help` | Prints usage. |

Options:

| Option | Default | Meaning |
|---|---|---|
| `--vendor=DIR` | `vendor` | The vendor directory to analyze. Must contain `composer/installed.json`. |
| `--lock=FILE` | `ankus.lock` | The lock file to read or write. |
| `--jobs=N` | number of CPUs | How many worker processes to use. |
| `--no-cache` | | Don't read or write the result cache. |

Exit codes: `0` nothing new, `1` a package gained capabilities (`check` and
`diff`), `2` an error, such as no `vendor/composer/installed.json` or no lock
file.

## Capabilities

| Capability | The package's code... |
|---|---|
| `EXEC` | runs shell commands or controls processes (`exec`, `proc_open`, backticks, ...) |
| `CODE_EVAL` | evaluates code built at runtime (`eval`, `create_function`, or `include` of a `data:` or remote URL) |
| `DYNAMIC_INCLUDE` | includes PHP files whose path is decided at runtime |
| `NETWORK` | opens network connections (sockets, cURL, `mail`, DNS lookups, URL file access) |
| `FILE_WRITE` | writes, moves or deletes files |
| `FILE_READ` | reads files |
| `ENV` | reads or changes environment variables |
| `SENSITIVE_PATH` | mentions credential or key locations (`.ssh/`, `.aws/credentials`, `auth.json`, ...) |
| `UNSERIALIZE` | calls `unserialize()` |
| `NATIVE` | loads native code (FFI, `dl`) |
| `OBFUSCATION` | passes an encoded or disguised value to a sensitive function, or includes a non-PHP file as PHP |
| `DYNAMIC_UNRESOLVED` | calls a function whose name could not be determined |
| `UNANALYZABLE` | contains PHP that could not be parsed |

In `scan` output, high-risk capabilities are marked with `!`.

Two **triggers** describe when a package's code runs without anyone calling it:

| Trigger | Meaning |
|---|---|
| `composer-plugin` | The package is a Composer plugin. Its code runs inside Composer during install and update. |
| `autoload-files` | The package lists files in `autoload.files`. They run whenever `vendor/autoload.php` is loaded. |

A package that gains a trigger fails `ankus check`, just like one that gains a
capability.

## The lock file

`ankus.lock` is JSON. For each package it records the version and the
approved capabilities and triggers:

```json
{
    "_readme": "Capabilities each dependency is approved to have, written by `ankus lock`. Review changes to this file like code.",
    "lock-version": 1,
    "packages": {
        "guzzlehttp/guzzle": {
            "version": "8.2.0",
            "capabilities": ["ENV", "FILE_READ", "FILE_WRITE", "NETWORK"],
            "triggers": []
        },
        "symfony/polyfill-mbstring": {
            "version": "v1.43.0",
            "capabilities": ["DYNAMIC_INCLUDE"],
            "triggers": ["autoload-files"]
        }
    }
}
```

File names and line numbers are not stored, so the lock file only changes
when a package's capabilities change, not every time its code moves around.
Treat a change to it in a pull request like a change to code: someone should
read it.

A new package fails `check` if it has any capabilities or triggers. A removed
package, or one that lost capabilities, is reported but does not fail.

## How ankus decides

ankus parses every PHP file in a package and looks for calls to PHP functions
and classes that grant a capability. It follows the values that decide what is
being called, so a function name built at runtime is still recognized:

```php
$f = strrev('metsys');   // "system"
$f($cmd);                // reported as EXEC and OBFUSCATION
```

It works through string concatenation, `sprintf`, `implode`, `str_replace`,
`base64_decode`, `str_rot13`, `hex2bin`, `gzinflate`, `chr()` sequences,
constants, property defaults and values returned by the package's own
functions. When a call passes a suspicious value (a URL, an encoded string, a
function name such as `exec`, or a credential path) to a closure, function or
method in the same package, ankus follows it into that code. It also recognizes
callables passed to functions such as `call_user_func`, `array_map`,
`ob_start` and `register_shutdown_function`.

**Whose capability is it?** A capability belongs to the code that names the
function. A library that calls a callback you pass it, such as
`$listener($event)`, is not credited with whatever your callback does. A
library that builds the name `'sys' . 'tem'` itself, decodes it, or takes it
from `$_GET` or from a file it read, is. If a function name is built in a way
ankus cannot resolve, the call is reported as `DYNAMIC_UNRESOLVED` instead of
being ignored.

## Limitations

- ankus reports what code **can** do, not whether it is malicious. guzzle has
  `NETWORK` because it is an HTTP client. The value is in noticing *changes*.
- It cannot tell a package misusing an ability it already had. If an HTTP
  client starts sending your data to a new host, it still only has `NETWORK`.
- Capabilities are counted per package, including code your application never
  calls.
- A function that accepts a file path from its caller can be given a URL.
  ankus reports such a function as `FILE_READ` or `FILE_WRITE`, not `NETWORK`,
  because the URL is the caller's choice.
- PHP is very dynamic, and some calls cannot be resolved statically. Those are
  reported as `DYNAMIC_UNRESOLVED`, which in a large framework is normal.
- If ankus is installed inside your project, it reports itself: `EXEC` (it
  restarts PHP), `FILE_READ`, `FILE_WRITE` and `UNSERIALIZE` (its cache),
  `ENV`, and `SENSITIVE_PATH` (its own list of credential paths). That is
  accurate.

## Performance and caching

On a fresh Laravel 13 application (109 packages, about 8,000 PHP files) on a
12-core laptop:

| | Time |
|---|---|
| First run | 4.6 s |
| `ankus check`, nothing changed | 0.3 s |
| `ankus check`, one package changed | 0.45 s |

Results are cached in `~/.cache/ankus` (or `$XDG_CACHE_HOME/ankus`). The
cache is keyed by the contents of every analyzed file, not by version numbers,
so a file edited inside `vendor/` is always analyzed again. Delete the
directory or pass `--no-cache` to bypass it.

If Xdebug is enabled, ankus restarts PHP once with Xdebug off and the opcache
JIT on, as Composer does with Xdebug. On the same application, a
single-process run took 77 seconds with Xdebug on and 22 seconds with it off.
Set `ANKUS_NO_RELAUNCH=1` to prevent the restart.

## Testing ankus itself

```sh
composer install
vendor/bin/phpunit             # unit tests and fixtures
php corpus/run.php             # replays real attacks; needs network, set GITHUB_TOKEN to avoid rate limits
php oracle/run.php             # compares predictions with runtime behaviour; needs Xdebug and bubblewrap, slow
```

**Fixtures** (`tests/fixtures/`). Each file states the exact set of
capabilities ankus must report for it, in an `// @expect:` comment. A
capability that is missing fails the test, and so does one that should not be
there. There are 65 files that use a technique to hide what they do, 24
ordinary patterns that must not be reported (for example `$pdo->exec()`, a
namespaced `exec()` function, or callbacks), and 10 straightforward uses of
functions such as cURL, `proc_open` and `file_put_contents`.

**Real attacks** (`corpus/`). Recent Packagist supply-chain attacks whose
malicious commits are still available, replayed as the update a victim would
have installed: the last clean commit, then the malicious one. The expected
results come from published analyses of each attack, not from ankus. All six
fail `ankus check` with every expected capability:

| Attack | What ankus reports |
|---|---|
| intercom/intercom-php 5.0.2, April 2026 (2 commits) | becomes a Composer plugin; `EXEC` (runs the downloader with `passthru`) |
| laravel-lang/lang, May 2026 | new `autoload-files` entry; `NETWORK` to `https://flipboxstudio.info/payload` (decoded from character codes); `EXEC`; `FILE_WRITE`; `OBFUSCATION` |
| laravel-lang/http-statuses, May 2026 (2 commits) | same as laravel-lang/lang |
| bfunky/http-parser, May 2026 | `NETWORK` to a base64-encoded URL; `ENV` (sends every environment variable); `FILE_WRITE`; `OBFUSCATION` |

Samples are downloaded by commit hash and only parsed, never executed. CI runs
the corpus on every push.

**Runtime comparison** (`oracle/`). Runs the test suites of 20 widely used
packages under an Xdebug function trace, in a sandbox without network access,
and checks that every capability the package's own code used at runtime was
also reported by ankus. Results are in `oracle/results.json`.

## License

MIT. See [LICENSE](LICENSE).
