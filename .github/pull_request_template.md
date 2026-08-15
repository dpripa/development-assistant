## Summary

Describe the problem and the focused change that addresses it.

Related issue: <!-- Add an issue URL or write "None". -->

## Verification

List the exact automated checks and manual WordPress scenarios performed.

```text
make lint
make build-src
```

## Screenshots

Add before-and-after screenshots for visible interface changes, or write "Not applicable".

## Impact

- Compatibility:
- Security and privacy:
- Stored data, files, users, or plugin state:
- Documentation, translations, or WordPress.org readme:

## Checklist

- [ ] The change is focused and contains no unrelated refactoring.
- [ ] Required automated checks pass.
- [ ] Affected WordPress behavior was tested manually.
- [ ] Minimum PHP and WordPress compatibility is preserved or the change was discussed first.
- [ ] Inputs, capabilities, nonces, output escaping, and redirects were reviewed where relevant.
- [ ] No credentials, logs, backups, uploads, generated certificates, runtime state, or generated assets are included.
- [ ] Public documentation and translations are updated where needed.
