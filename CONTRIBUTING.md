# Contributing

## Setup

```sh
git clone https://github.com/dotMavriQ/ankus.git
cd ankus
composer install
```

## Checks

All of these run in CI and must pass:

```sh
vendor/bin/phpunit          # unit tests and fixtures
vendor/bin/phpstan analyse  # static analysis, level 8
php corpus/run.php          # real attacks; needs network (set GITHUB_TOKEN to avoid rate limits)
```

`php oracle/run.php` compares ankus with runtime behaviour on 20 packages. It
needs Xdebug and bubblewrap and takes about half an hour; run it when you
change how capabilities are detected.

## Fixtures

Every change to detection comes with fixtures in `tests/fixtures/`:

- `evasion/`: code that hides what it does and must be reported
- `benign/`: ordinary code that must not be reported
- `sinks/`: straightforward uses of each kind of function

Each file starts with the exact set of capabilities ankus must report:

```php
<?php
// @expect: EXEC, OBFUSCATION
$f = strrev('metsys');
$f('id');
```

`// @expect: none` means nothing may be reported. A missing capability fails
the test, and so does an extra one.

If you find a false positive or a missed capability in real code, the fix
starts with a fixture that reproduces it.

## Adding a real attack to the corpus

Add an entry to `corpus/manifest.json` with the repository, the malicious
commit, its clean parent, and the capabilities and triggers that published
analyses of the attack describe. Write the expectations from those analyses,
not from ankus output. Never commit the samples themselves; `corpus/run.php`
downloads them by commit hash.
