# Security policy

## Reporting a vulnerability

Please report security problems privately, using GitHub's
**Report a vulnerability** button on the repository's **Security** tab. Do
not open a public issue.

Include what you found, how to reproduce it, and which version or commit you
tested. You will get a reply within a week. Fixes are released as soon as they
are ready, and you will be credited unless you prefer otherwise.

## What counts as a vulnerability

- ankus running, including or loading code from the packages it analyzes, or
  any other way analyzing a package can make ankus execute something
- a way for a package to get a capability or a new trigger past `ankus check`
  without being reported, where the technique is not already listed under
  "Limitations" in the README
- ankus writing outside its cache directory and the lock file you point it at

Evasions are reported privately for the same reason as other vulnerabilities:
until a fix is released, they help attackers more than defenders.

## Supported versions

Only the latest release receives fixes.
