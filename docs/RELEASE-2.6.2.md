# ArgueSecure 2.6.2

A fix for pages that came up blank behind a reverse proxy, two security
updates, and a way to cite the standard a defence implements.

## Fixes

- **Pages load over https behind a reverse proxy.** In 2.6.1, visitors
  reaching the site from a public address got a blank page: the stylesheet and
  script links came out as `http://` and the browser blocked them. Testing on a
  private network never showed it. The application now trusts the proxy's
  `X-Forwarded-Proto` header, as intended.

## Security

Two dependency updates close low-severity advisories, neither reachable in a
correctly configured deployment (`APP_DEBUG=false`):

- Laravel framework 12.64 to 12.69, for a debug-page issue.
- League Flysystem 3.35 to 3.36, for a path normalisation issue.

## Standards

- **Cite the control a defence implements.** A defence can carry a reference of
  the form `FRAMEWORK:CONTROL` for ISO 27001:2022, ISO 42001:2023, the EU AI Act,
  GDPR, NIS2, SOC 2 and CIS v8. References are checked against each framework's
  own format, and the link is generated, not typed.
- **The bundled standards templates cite their controls,** over 500 citations.
- **A Control coverage section in the report** (on screen and in the HTML, Word
  and Excel exports) lists, for each framework, every cited control, the
  defences citing it and whether they are in place.

## Upgrading from 2.6.1

1. **Take a database dump.** A database change runs on start.
2. **Pull the new images and restart.**
3. **If you worked around the blank-page bug in your proxy,** for example by not
   forwarding `X-Forwarded-For` or by fixing `X-Forwarded-Proto` by hand,
   restore the standard configuration.

Templates already loaded stay as they were, without control citations.
Defences you add can cite controls straight away, and a fresh install gets the
citations.
