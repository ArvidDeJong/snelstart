# Security policy

This package holds the keys to a bookkeeping: the SnelStart client key, the subscription key and the
access token it gets for them. A way to leak one of them, to send one to another host than the
configured ones, or to get one into a URL, a log line, an exception message or command output counts
as a security issue.

## Supported versions

Only the latest minor release of 1.x receives security fixes. Upgrade before reporting.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately instead:

- via [GitHub private vulnerability reporting](https://github.com/ArvidDeJong/snelstart/security/advisories/new), or
- by email to info@arvid.nl.

Include the package version, the Laravel version and the steps that show the problem. Leave your
keys, the token and data from a real administration out.

You will get a reply within a week. Once a fix is released, the advisory is published and you are
credited, unless you prefer not to be.

## Out of scope

- The response body of a failed call is part of the exception message, with the keys and the token
  replaced by `[redacted]`. The rest of that body can hold data of the administration, and so can
  the company info `php artisan snelstart:test` prints. Who can read your logs and who can run
  artisan is up to the host application.
- The package sends the keys to the URLs in `base_url` and `token_url`. Whoever can change the config
  or the environment of the application can point them elsewhere; protecting those is up to the host
  application.
- The package passes your arrays on to SnelStart and returns what comes back. Which user may read or
  write which relation, article or order is up to the host application, and what SnelStart accepts or
  returns for a given input is not a vulnerability in this package.
- Calls that fail or are refused because you make too many of them. The package does not retry and
  does not rate limit; put the calls behind your own authorisation, queue and limits.
- The standalone client has no timeout, which is documented. A slow server keeps the calling process
  busy; set your own limit around it.
