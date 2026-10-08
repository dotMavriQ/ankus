# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

First release.

- `ankus scan`, `lock`, `check` and `diff`: report what each installed Composer
  package can do (run commands, use the network, evaluate code, read
  environment variables and more) and fail when an update gives a package a
  capability or trigger it did not have.
- Sees through disguised function names and URLs: concatenation, `sprintf`,
  `implode`, `strrev`, `base64_decode`, `str_rot13`, `hex2bin`, `gzinflate`,
  `chr()` sequences, constants, properties and values passed between functions.
- Reports capabilities reached through other packages, such as
  `EXEC via symfony/process`.
- Reports Composer plugins and `autoload.files` as triggers.
- `ankus.lock` records the approved state.
- Content-addressed result cache, parallel analysis, and a restart without
  Xdebug and with the opcache JIT.
- Never loads or runs the code it analyzes, including the project's
  `vendor/autoload.php`.

[0.1.0]: https://github.com/dotMavriQ/ankus/releases/tag/v0.1.0
