# ankus

[![Packagist](https://img.shields.io/packagist/v/ankus/ankus)](https://packagist.org/packages/ankus/ankus)
[![PHP](https://img.shields.io/packagist/dependency-v/ankus/ankus/php)](https://packagist.org/packages/ankus/ankus)
[![CI](https://github.com/dotMavriQ/ankus/actions/workflows/ci.yml/badge.svg)](https://github.com/dotMavriQ/ankus/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/ankus/ankus)](LICENSE)

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

### PHAR (recommended)

A single self-contained file, attached to every
[release](https://github.com/dotMavriQ/ankus/releases):

```sh
curl -sSLO https://github.com/dotMavriQ/ankus/releases/latest/download/ankus.phar
curl -sSLO https://github.com/dotMavriQ/ankus/releases/latest/download/ankus.phar.sha256
sha256sum -c ankus.phar.sha256
chmod +x ankus.phar
mv ankus.phar ~/.local/bin/ankus
```

Each release is built by GitHub Actions from the tagged commit, with a signed
record of how it was built. With the GitHub CLI you can check that the file
you downloaded is that build:

```sh
gh attestation verify ankus.phar --repo dotMavriQ/ankus
```

Run that before the `mv`; it exits with an error if the file does not match.

The PHAR contains its own copy of `nikic/php-parser`, so it never uses
anything from the project it checks.

### From source

Install ankus in its own directory, outside the projects you check:

```sh
git clone https://github.com/dotMavriQ/ankus.git ~/.local/share/ankus
composer install --no-dev --working-dir="$HOME/.local/share/ankus"
ln -s ~/.local/share/ankus/bin/ankus ~/.local/bin/ankus
```

`ankus` is now on your `PATH` (assuming `~/.local/bin` is). Run it from a
project's root directory.

Like the PHAR, this runs entirely on ankus's own code. When ankus is
installed inside a project instead, it uses that project's copy of
`nikic/php-parser`, which is one of the dependencies you are trying to check.

### As a development dependency

```sh
composer require --dev ankus/ankus
vendor/bin/ankus --version
```

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

GitHub Actions, using a pinned, verified release:

```yaml
- uses: actions/checkout@v4

- name: Install ankus
  run: |
    curl -sSL -o "$RUNNER_TEMP/ankus.phar" https://github.com/dotMavriQ/ankus/releases/download/v0.1.0/ankus.phar
    gh attestation verify "$RUNNER_TEMP/ankus.phar" --repo dotMavriQ/ankus
  env:
    GH_TOKEN: ${{ github.token }}

- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.4'

- name: Download dependencies without running any of their code
  run: composer install --no-plugins --no-scripts --no-interaction --no-progress

- name: Check dependency capabilities
  run: php "$RUNNER_TEMP/ankus.phar" check

- name: Finish installing (plugins and scripts run now)
  run: composer install --no-interaction --no-progress
```

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

### Capabilities reached through other packages

A package can also get a capability by calling code in another package. If
`acme/lib` starts running `new \Symfony\Component\Process\Process([...])`,
it can run commands without calling `exec()` itself. `ankus scan` lists these
under "via other packages", and `ankus check` reports them like this:

```
  ✗ acme/lib [1.4.0 -> 1.4.1]
      + EXEC via symfony/process  (runs shell commands or controls processes in symfony/process)
          Symfony\Component\Process\Process::run() at src/Job.php:31 in Acme\Job::handle()
```

ankus follows these kinds of calls, across any number of packages:

- `new Class(...)`: the code can now do anything that class can do
- `Class::method()` and namespaced functions such as `\Vendor\run()`
- method calls on `$this`, on parameters and properties with a declared class
  type (including promoted constructor parameters), and on variables assigned
  from `new`
- methods inherited from a parent class in another package
- class names built from strings, such as `$c = 'Vendor\\' . 'Shell'; $c::run()`

If a dependency itself gains a capability, every package that already called
into it would reach that capability too. That is reported once, under the
dependency that changed; the packages that use it show a note but do not fail.

## The lock file

`ankus.lock` is JSON. This is the entry for `laravel/pint` in a Laravel 13
application, exactly as `ankus lock` writes it:

```json
{
    "_readme": "Capabilities each dependency is approved to have, written by `ankus lock`. Review changes to this file like code.",
    "lock-version": 2,
    "packages": {
        "laravel/pint": {
            "version": "v1.32.1",
            "code": "0514dc3e0adcc6f497d0907492c9b1c1a2772dc1243ddc5e67b6d3cf5f1bf9db",
            "capabilities": [],
            "via": {
                "ENV": [
                    "symfony/process"
                ],
                "EXEC": [
                    "symfony/process"
                ],
                "FILE_READ": [
                    "symfony/finder"
                ]
            },
            "calls_into": [
                "illuminate\\support\\processutils",
                "symfony\\component\\console\\input\\inputinterface",
                "symfony\\component\\finder\\finder",
                "symfony\\component\\process\\phpexecutablefinder"
            ],
            "triggers": []
        }
    }
}
```

| Field | Meaning |
|---|---|
| `version` | The installed version. Changing it alone is not a failure. |
| `code` | A SHA-256 hash of the package's PHP files. |
| `capabilities` | Capabilities of the package's own code. |
| `via` | Capabilities reached by calling other packages, and which packages they are in. |
| `calls_into` | Classes and functions in other packages that this one calls. Used to tell a package that started using something new apart from one whose dependency changed. |
| `triggers` | See the triggers table under [Capabilities](#capabilities). |

File names and line numbers are not stored, so the lock file only changes
when what a package can do changes, not every time its code moves around.
Treat a change to it in a pull request like a change to code: someone should
read it.

`ankus check` fails when:

- a package gains a capability, a `via` capability or a trigger. A new
  package fails if it has any of them.
- a package's code changed but its version did not. A released version should
  never change; when it does, a tag was rewritten (as in the Laravel-Lang and
  bfunky attacks) or someone edited `vendor/`. Branch versions such as
  `dev-main` are exempt.

A `via` capability does not fail `check` for a package whose own code is
unchanged, or that only calls classes it already called whose package changed
in the same update. In those cases the change happened elsewhere, and that
package is checked on its own.

A removed package, or one that lost capabilities, is reported but does not
fail.

A lock file written by an older version of ankus (`"lock-version": 1`) is
rejected with a message asking you to run `ankus lock` again.

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
- Calls to other packages are followed when the target class is known from
  the code: `new`, static calls, `$this`, and declared parameter and property
  types. A method called on an object whose class is not declared anywhere
  (for example, one returned by a service container) is not followed.
- `new Class` counts as everything that class can do, even if the code then
  only calls a harmless method.
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
| First run | 5.6 s |
| `ankus check`, nothing changed | 0.6 s |
| `ankus check`, one package changed | 0.8 s |

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
php replay/run.php             # replays a year of real updates of two applications; slow
```

**Fixtures** (`tests/fixtures/`). Each file states the exact set of
capabilities ankus must report for it, in an `// @expect:` comment. A
capability that is missing fails the test, and so does one that should not be
there. There are 65 files that use a technique to hide what they do, 24
ordinary patterns that must not be reported (for example `$pdo->exec()`, a
namespaced `exec()` function, or callbacks), and 10 straightforward uses of
functions such as cURL, `proc_open` and `file_put_contents`.

**Capabilities through other packages** (`tests/CrossPackageTest.php`).
Small `vendor/` directories where one package starts using another: nine ways
of reaching a capability that must be reported (`new`, typed properties and
parameters, static calls, aliases, class names built from strings,
inheritance, functions, and through a third package), four references that
grant nothing and must stay quiet, and a dependency that gains a capability,
which must be reported once.

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

**Real update history** (`replay/`). Two open-source Laravel applications,
BookStack and Firefly III, with every `composer.lock` change from the past
year replayed in order: install the lock (with plugins and scripts off), run
`ankus check`, then `ankus lock` as a developer accepting the update would.

| | Updates | `check` failed |
|---|---|---|
| BookStack | 26 | 10 |
| Firefly III | 50 | 15 |
| **Total** | **76** | **25** |

That is about one review a month per application. Every failure was read by
hand. All but one were real changes in what a dependency can do, for example
nette/utils 4.1.4 adding a `Process` class that runs commands, firebase/php-jwt
starting to use phpseclib, sabberworm/php-css-parser adding code that runs on
every request, or a new dependency arriving. The one false alarm, a hashing
function on a path ankus could not resolve being treated as possible
networking, is fixed. 21 of the 25 failures involve a production dependency,
not only development tools. Details are in `replay/results.json`.

**Runtime comparison** (`oracle/`). Runs the test suites of 20 widely used
packages (Symfony components, monolog, guzzlehttp/psr7, phpdotenv, ramsey/uuid,
phpmailer, twig, league/commonmark, league/csv and others) under an Xdebug
function trace, in a sandbox without network access, and checks that every
capability the package's own code used at runtime was also reported by ankus.

| Capabilities used at runtime, counted as (file, capability) pairs | 116 |
|---|---|
| reported by ankus | 110 |
| passed in by the caller (a callback or a URL given as a file path) | 5 |
| inside code created with `eval()`, where ankus reported `CODE_EVAL` | 1 |
| **missed** | **0** |

This shows ankus is sound on the code these test suites run, not on every
path. Two suites ran only partly: symfony/cache stopped at the 15-minute
limit and symfony/var-dumper crashed early. Per-package results are in
`oracle/results.json`.

## License

MIT. See [LICENSE](LICENSE).
