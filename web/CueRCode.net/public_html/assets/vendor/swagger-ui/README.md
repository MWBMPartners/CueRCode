# Swagger UI (vendored local fallback)

Path: `web/CueRCode.net/public_html/assets/vendor/swagger-ui/`

## What this is

This directory holds an **unmodified**, locally-hosted copy of the
[Swagger UI](https://swagger.io/tools/swagger-ui/) distribution files, used
by `/api/docs/` (`web/CueRCode.net/public_html/api/docs/index.php`) as a
fallback when the jsDelivr CDN copy can't be reached (Issue #111).

The docs page loads Swagger UI CDN-first from jsDelivr, pinned to a fixed
version with a Subresource Integrity (SRI) hash on every file. If that
request fails - no internet, jsDelivr is down, or the file somehow doesn't
match its SRI hash - the page falls back to loading these local files
instead, so the interactive API documentation keeps working even with no
CDN reachable at all.

## Version

**5.33.0**

## Source

Downloaded from the official npm registry tarball for the
[`swagger-ui-dist`](https://www.npmjs.com/package/swagger-ui-dist) package
(the same package jsDelivr serves at
`https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/` - jsDelivr mirrors npm
packages byte-for-byte, which is what makes the SRI hashes below valid for
both the CDN copy and this local copy).

Files vendored from the tarball (unmodified):

- `swagger-ui.css`
- `swagger-ui-bundle.js`
- `swagger-ui-standalone-preset.js`
- `swagger-ui-bundle.js.LICENSE.txt`
- `swagger-ui-standalone-preset.js.LICENSE.txt`
- `LICENSE`
- `NOTICE`

## Licence

**Apache License 2.0.** See the `LICENSE` file in this directory for the
full text, and `NOTICE` for the required notice text:

```
swagger-ui
Copyright 2020-2021 SmartBear Software Inc.
```

This project's own `/attributions` page (`public_html/attributions/index.php`)
also credits Swagger UI / SmartBear Software, matching the style of the
other vendored-library entries there.

## Why these files are committed (not `.gitignore`d)

Unlike Bootstrap, jQuery, and Font Awesome (which are only used as fallback
copies but are `.gitignore`d and expected to be downloaded/deployed
separately), the three Swagger UI files above ARE committed straight into
the repository. That's deliberate: the SFTP deploy workflow only ships what
is actually in the repo, and this docs page needs to work correctly on
Dreamhost shared hosting - which has no server-side build step, no Docker,
and no guaranteed outbound access to a CDN - with zero extra deploy-time
setup. Committing the files guarantees they're always present.

## SRI hashes (sha384)

These MUST stay in sync with the `integrity="..."` attributes on the
`<link>`/`<script>` tags in
`web/CueRCode.net/public_html/api/docs/index.php`:

| File                                | SHA-384 (base64)                                              |
| ------------------------------------ | --------------------------------------------------------------- |
| `swagger-ui.css`                     | `sha384-Ov4/wv3j2bmct8cDc5X4ngJZohVPzEmc6uDPH8WeljUxO5vtoykvMEfbu9Vh6RaW` |
| `swagger-ui-bundle.js`               | `sha384-YDALVcy8kj8yltLBVi1vBiBAUqdxvus673gM8XKwiy6aDUJFXivF/KCufekjYbVf` |
| `swagger-ui-standalone-preset.js`    | `sha384-My2aDM4r2Mbm3ybHcubKm9O9U8FEjvF/O5nGvE9YK5dzqOTbWEKa79RPJ1krdMaF` |

## How to upgrade to a newer version

1. Download the new version's tarball from the npm registry, e.g.:
   `https://registry.npmjs.org/swagger-ui-dist/-/swagger-ui-dist-<NEW_VERSION>.tgz`
2. Extract it, and copy the following files into this directory, overwriting
   the old ones:
   - `swagger-ui.css`
   - `swagger-ui-bundle.js`
   - `swagger-ui-standalone-preset.js`
   - `swagger-ui-bundle.js.LICENSE.txt`
   - `swagger-ui-standalone-preset.js.LICENSE.txt`
   - `LICENSE`
   - `NOTICE`
3. Recompute the SRI hash for each of the three `.css`/`.js` files with:
   ```bash
   openssl dgst -sha384 -binary FILE | openssl base64 -A
   ```
   Prefix the result with `sha384-` to get the full `integrity` value.
4. Update **both** of the following together, so the version number and
   hashes never drift out of sync:
   - This README (`Version` section above, and the SRI hash table).
   - `web/CueRCode.net/public_html/api/docs/index.php` (the jsDelivr URL
     version number in each `href`/`src`, and the matching `integrity`
     attribute on each tag).
5. Verify: `php -l` the docs page, then load `/api/docs/` locally and in a
   browser with the network tab open to confirm both the CDN request and
   (by temporarily blocking jsDelivr, e.g. via browser devtools request
   blocking) the local fallback both render Swagger UI correctly.
