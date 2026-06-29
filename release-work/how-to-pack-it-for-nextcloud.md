# How to pack & publish `synaplan_integration` for the Nextcloud App Store

A step-by-step, repo-specific guide for cutting a release of the Synaplan
Nextcloud app and publishing it to [apps.nextcloud.com](https://apps.nextcloud.com).

- **App id:** `synaplan_integration` (the tarball's top folder **must** be named this)
- **Build tool:** Vite (`npm run build` → `js/`)
- **Helpers:** the repo `Makefile` already implements `package`, `sign`, `appstore`
- **Example version used below:** `1.4.0`

> TL;DR for a routine release once the certificate exists:
> `make appstore` → upload `synaplan_integration.tar.gz` to a GitHub Release →
> paste its URL + the printed signature on apps.nextcloud.com.

---

## 0. One-time setup (certificate + app registration)

The App Store only accepts releases **signed with a certificate that Nextcloud
issued for this exact app id**. Do this once.

### 0.1 Register the app

Create the app page on <https://apps.nextcloud.com> (sign in → *Upload app* /
register the id `synaplan_integration`). You can only upload releases for an id
you own.

### 0.2 Generate a signing key + CSR

```bash
mkdir -p ~/.nextcloud/certificates
cd ~/.nextcloud/certificates

# Private key + certificate signing request. CN MUST equal the app id.
openssl req -nodes -newkey rsa:4096 -keyout synaplan_integration.key \
  -out synaplan_integration.csr -subj "/CN=synaplan_integration"
```

- `synaplan_integration.key` — **private key, keep secret**, never commit.
- `synaplan_integration.csr` — public request you submit to Nextcloud.

### 0.3 Get the CSR signed by Nextcloud

Open a PR adding `synaplan_integration.csr` to
<https://github.com/nextcloud/app-certificate-requests> (follow that repo's
README). Once merged, Nextcloud's CA returns your **certificate**; save it as:

```text
~/.nextcloud/certificates/synaplan_integration.crt
```

You now have the key + crt the `Makefile` expects (`cert_dir := $(HOME)/.nextcloud/certificates`).

---

## 1. Prepare the release (per version)

### 1.1 Bump the version (two files, must match)

- `appinfo/info.xml` → `<version>1.4.0</version>`
- `package.json` → `"version": "1.4.0"`

Use semantic versioning: **patch** for fixes (1.3.0→1.3.1), **minor** for
features (1.3.1→1.4.0), **major** for breaking changes.

### 1.2 Update the changelog

Add a dated section to `CHANGELOG.md` under the new version (Added / Changed /
Fixed). Keep `## [Unreleased]` on top.

### 1.3 Check `info.xml` metadata

- `<dependencies>`: `php min-version` and `nextcloud min/max-version` are correct
  (currently php ≥ 8.2, Nextcloud 30–34). **Bump `max-version` only after testing
  on that Nextcloud release.**
- Screenshots, summary, description, category, and the bug/repo URLs are current.

### 1.4 Run the quality gate (must be green)

```bash
make lint        # PHP CS + ESLint + Prettier
make test        # PHPUnit (lib/)
make build       # production Vite build → js/
```

All three must pass before packaging. (`js/` is gitignored and is produced by
`make build`; the package step needs it present.)

---

## 2. Package the tarball

```bash
make package
```

This runs `make build` first, then assembles `synaplan_integration.tar.gz` whose
**top-level folder is `synaplan_integration`** and contains **only the runtime
files**:

```text
synaplan_integration/
├── appinfo/      # info.xml, routes.php
├── img/          # icons / branding
├── js/           # built Vite bundles (NOT the src/)
├── lib/          # PHP backend
├── templates/    # PHP templates
├── l10n/         # translations
├── CHANGELOG.md
├── LICENSE
└── README.md
```

It deliberately **excludes** `src/`, `tests/`, `node_modules/`, `vendor/`,
`.git/`, and build config — keep it that way (smaller, no dev code shipped).

Sanity-check the contents:

```bash
tar -tzf synaplan_integration.tar.gz | head -20
```

---

## 3. Sign the tarball

```bash
make sign
```

This computes the App Store signature with your private key:

```bash
openssl dgst -sha512 -sign ~/.nextcloud/certificates/synaplan_integration.key \
  synaplan_integration.tar.gz | openssl base64
```

It writes `synaplan_integration.tar.gz.sig` and prints the **base64 signature**
you'll paste into the App Store. (`make sign` fails fast if the key is missing —
see §0.)

> `make appstore` runs `package` + `sign` and then prints the final upload
> instructions in one go.

---

## 4. Publish

### 4.1 Tag + GitHub Release (gives a stable download URL)

The App Store needs a **public, stable URL** to the `.tar.gz`. A GitHub Release
asset is the standard choice.

```bash
# from the repo root, on the release commit
git tag v1.4.0
git push origin v1.4.0
gh release create v1.4.0 synaplan_integration.tar.gz \
  --title "v1.4.0" --notes-file <(sed -n '/## 1.4.0/,/## 1.3/p' CHANGELOG.md)
```

The download URL will look like:
`https://github.com/metadist/synaplan-nextcloud/releases/download/v1.4.0/synaplan_integration.tar.gz`

### 4.2 Upload the release on the App Store

1. Go to <https://apps.nextcloud.com/developer/apps/releases/new>.
2. **Download URL:** the GitHub asset URL from §4.1.
3. **Signature:** the base64 string printed by `make sign`.
4. (Optional) tick **nightly/pre-release** for test builds.
5. Submit. The App Store re-downloads the tarball, verifies the signature against
   your certificate, validates `info.xml`, and publishes.

> Production cluster note: `vultr-cluster` pulls
> `releases/latest/download/synaplan_integration.tar.gz`, so a GitHub Release
> (4.1) also feeds the self-hosted deployment — independent of the App Store.

---

## 5. Verify before/after publishing

Install the **packaged tarball** (not your dev checkout) into a clean Nextcloud
to confirm it runs as shipped:

```bash
# inside the target Nextcloud
tar -xzf synaplan_integration.tar.gz -C /path/to/nextcloud/apps/
php occ app:enable synaplan_integration
php occ app:list | grep synaplan          # confirms the installed version
```

Then smoke-test: open **Settings → Synaplan**, set the URL + API key, run
**Test connection**, and try the chat (`/pic` → image renders → *Save to
Nextcloud*).

---

## 6. Quick command reference

```bash
# routine release (certificate already set up)
vim appinfo/info.xml package.json CHANGELOG.md   # bump + changelog
make lint && make test && make build             # gate
make appstore                                     # package + sign + instructions
git tag vX.Y.Z && git push origin vX.Y.Z
gh release create vX.Y.Z synaplan_integration.tar.gz
# → paste URL + signature at apps.nextcloud.com/developer/apps/releases/new
```

---

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| App Store: *"Signature could not be verified"* | Tarball was modified after signing, or wrong key. Re-run `make sign` on the exact uploaded file; never edit the `.tar.gz` after signing. |
| App Store: *"App id mismatch"* | Tarball top folder isn't `synaplan_integration`, or CN of the cert ≠ app id. The `Makefile` names the folder correctly; check your cert CN. |
| `make sign` aborts: *private key not found* | Generate/place the key+crt under `~/.nextcloud/certificates/` (§0). |
| `info.xml` validation error | Fix schema issues (versions, required fields). The App Store validates on upload. |
| `js/` missing in tarball | Run `make build` (or just `make package`, which builds first). `js/` is gitignored on purpose. |
| Release shows old version after install | `occ app:list` reads `appinfo/info.xml`; ensure both `info.xml` and `package.json` were bumped and the **packaged** (not dev) copy is installed. |

---

_Keep this file updated when the release flow changes (e.g. if CI starts building
and signing automatically via [krankerl](https://github.com/ChristophWurst/krankerl)
or a GitHub Action)._
