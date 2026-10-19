# Synaplan Integration — Hosting-Company Guide

Audience: operators who host Nextcloud (or ownCloud / ownCloud.online) for their
customers and want to offer Synaplan AI features **with per-user isolation** and
**their own AI backbone**.

This guide covers the features shipped for the hosting scenario:

1. **How users connect** — one shared account, accounts created by the
   administrator (`provision`), or people connect their own Synaplan account
   (`link`).
2. **Bring-your-own OpenAI-compatible AI** — point Synaplan at your own LocalAI /
   vLLM / LiteLLM (or any OpenAI-compatible) endpoint and expose those models to
   users, including chat, embeddings and vision.

> This guide describes version 1.7.0. What is planned but **not yet
> implemented** (watched folders) is listed at the end under
> [Roadmap](#roadmap).

---

## 1. Prerequisites

- A running **Synaplan** instance reachable from your Nextcloud server over HTTPS.
- A Synaplan **admin account**, and an **admin API key** for it
  (Synaplan → Settings → API Keys → *Create key* while logged in as an admin).
  In per-user mode this key is used only to provision accounts and mint per-user
  keys — never for end-user traffic.
- Nextcloud 33–35, PHP 8.2+.

---

## 2. Enable per-user accounts

1. In Nextcloud, go to **Administration settings → Synaplan**.
2. Set the **Synaplan URL** and paste the **admin API key**.
3. Under **How users connect**, pick **Accounts created by the administrator**
   (`provision`) or **Connect your Synaplan account** (`link`).
4. In `link` mode click **Register this instance** (once). People then open
   **Personal settings → Synaplan → Connect Synaplan**, sign in, and confirm.
5. Click **Save**, then **Test connection**.

In **link** mode people open **Personal settings → Synaplan → Connect Synaplan**
first; nothing is created until they confirm (or choose **Create one for me**,
if you turned on **Offer to create an account for users who have none**). A
Files action before that offers **Connect my Synaplan account**. In
**provision** mode a person turns AI on once with **Activate AI**; the app
then uses your admin key to:

- create a Synaplan account for that user (idempotent — one account per user),
  identified by `source = "nextcloud"` and
  `external_id = "<nextcloud-instance-id>:<uid>"`;
- mint a **per-user API key** scoped to `chat`, `files`, `rag`. Synaplan
  enforces the scopes: the key reaches chat, files and search, and gets
  `403 insufficient_scope` everywhere else, the admin endpoints included;
- store that key encrypted on the Nextcloud server (never sent to the
  browser) and use it for all that user’s Synaplan traffic.

### What “isolation” means

| Concern | Behaviour in per-user mode |
| ------- | -------------------------- |
| Knowledge base (files + vectors) | Per user — user A can never retrieve user B’s documents. |
| Personal memories | Per user. |
| Chat history / usage | Per user. |
| AI models available | Shared catalog configured by you (the operator). |

Isolation is enforced on the Synaplan side: every file, vector and memory is
scoped to the owning Synaplan user id, and a per-user API key authenticates only
as that user.

### Key rotation / revocation

- If a **provisioned** user’s key is revoked in Synaplan, the next request
  returns 401; the app forgets the stale key and re-provisions on the following
  request. In **link** mode a 401 means the person disconnected in Synaplan:
  the next action says **Connect your Synaplan account first.** with a link to
  Personal settings → Synaplan, which shows **Connect Synaplan** again. No new
  account is created.
- **Disconnect** in Personal settings → Synaplan revokes the person’s key in
  Synaplan.
- A key that no longer decrypts (for example after the Nextcloud server secret
  changed) counts as no key: linked people connect again, provisioned people
  get a new key, and you enter the admin key again.
- Deleting a **provisioned** Nextcloud user also deletes their Synaplan
  account. A **linked** account is only disconnected — the Synaplan user stays.
  That cleanup still runs if you later switch **How users connect** back to
  shared: a once-linked user is never treated as provisioned.
- After a `provision` → `link` switch, people who already have a provisioned
  key can still choose **Connect Synaplan** in personal settings. They are
  not locked on Disconnect-only.

### Backward compatibility

If you leave per-user accounts **off**, the app behaves exactly as before: all
traffic uses the single configured key (one shared Synaplan account). This is
fine for a single-tenant / personal install but **not** for multi-customer
hosting.

---

## 3. Bring your own OpenAI-compatible AI backbone

Synaplan can use any OpenAI-compatible endpoint (LocalAI, vLLM, LiteLLM, Ollama’s
`/v1`, a cloud gateway, …) as a first-class model provider. Models you register
this way appear in the normal model pickers and support **chat (incl.
streaming), embeddings and vision**.

This is configured in **Synaplan** (not in Nextcloud), via the admin API. All
calls below use your Synaplan **admin API key**.

### 3.1 Register an endpoint

```bash
curl -X POST https://synaplan.example.com/api/v1/admin/openai-endpoints \
  -H "X-API-Key: $SYNAPLAN_ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "localai",
    "label": "Local AI (GPU 1)",
    "base_url": "https://localai.internal.example.com/v1",
    "api_key": "sk-your-upstream-key-or-empty",
    "capabilities": ["chat", "vectorize", "pic2text"]
  }'
```

- `base_url` must be the OpenAI-compatible base (usually ends in `/v1`).
- `api_key` is optional (some self-hosted gateways need none). It is stored
  **encrypted at rest**; the API never returns it — only a `has_api_key` flag.
- To edit the URL/headers later without re-entering the secret, omit `api_key`
  (send `null`); send `""` to clear it.

Test connectivity (lists the upstream’s models):

```bash
curl -X POST https://synaplan.example.com/api/v1/admin/openai-endpoints/test \
  -H "X-API-Key: $SYNAPLAN_ADMIN_KEY" -H "Content-Type: application/json" \
  -d '{"name": "localai"}'
# → {"ok":true,"status":200,"model_count":12,"sample":["llama-3.3-70b", ...]}
```

### 3.2 Register models on that endpoint

Create BMODELS rows with service `OpenAICompatible`, a capability `tag`
(`chat`, `vectorize`, or `pic2text`), the upstream model id as `providerId`, and
the endpoint name in `json.endpoint`:

```bash
# A chat model
curl -X POST https://synaplan.example.com/api/v1/admin/models \
  -H "X-API-Key: $SYNAPLAN_ADMIN_KEY" -H "Content-Type: application/json" \
  -d '{
    "service": "OpenAICompatible",
    "tag": "chat",
    "providerId": "llama-3.3-70b-instruct",
    "name": "Llama 3.3 70B (Local)",
    "json": {"endpoint": "localai"}
  }'

# An embedding model (for the knowledge base / RAG)
curl -X POST https://synaplan.example.com/api/v1/admin/models \
  -H "X-API-Key: $SYNAPLAN_ADMIN_KEY" -H "Content-Type: application/json" \
  -d '{
    "service": "OpenAICompatible",
    "tag": "vectorize",
    "providerId": "bge-m3",
    "name": "BGE-M3 (Local)",
    "json": {"endpoint": "localai", "meta": {"dimensions": 1024}}
  }'
```

For embedding models, set `json.meta.dimensions` to the real vector width of the
upstream model so the vector store is created at the right size.

### 3.3 Use them

The models now appear in Synaplan’s model pickers (and, via the Nextcloud
Research Chat, in the model dropdown there). Set them as user or global defaults
in Synaplan as usual.

If an endpoint serves only one model family, you can omit `json.endpoint` on the
model — with exactly one endpoint configured, Synaplan resolves it
automatically. With multiple endpoints, always pin the model to its endpoint.

### Endpoints reference

| Method & path | Purpose |
| ------------- | ------- |
| `GET /api/v1/admin/openai-endpoints` | List endpoints (keys never returned) |
| `POST /api/v1/admin/openai-endpoints` | Create/update an endpoint |
| `POST /api/v1/admin/openai-endpoints/test` | Probe `GET {base_url}/models` |
| `DELETE /api/v1/admin/openai-endpoints/{name}` | Remove an endpoint |

Full, interactive docs: **Synaplan → `/api/doc`** (Swagger UI).

---

## 4. Provisioning & usage API (for automation)

These admin-key endpoints back the per-user flow and are available for your own
automation / dashboards:

| Method & path | Purpose |
| ------------- | ------- |
| `POST /api/v1/admin/users` | Create/fetch a user for `(source, external_id)`; idempotent |
| `POST /api/v1/admin/users/{id}/api-keys` | Mint a per-user key (returned once) |
| `GET  /api/v1/admin/users/{id}/usage` | Per-user usage (messages, files, storage) |

Example — provision and inspect a user:

```bash
curl -X POST https://synaplan.example.com/api/v1/admin/users \
  -H "X-API-Key: $SYNAPLAN_ADMIN_KEY" -H "Content-Type: application/json" \
  -d '{"source":"nextcloud","external_id":"inst42:alice","email":"alice@example.com","display_name":"Alice"}'
# → {"success":true,"created":true,"user":{"id":501, ...}}

curl https://synaplan.example.com/api/v1/admin/users/501/usage \
  -H "X-API-Key: $SYNAPLAN_ADMIN_KEY"
```

Notes:

- `POST /admin/users` refuses to attach an external identity to an email that
  already belongs to a different Synaplan account (409) — no silent hijacking.
- `POST /admin/users/{id}/api-keys` refuses to mint a key for an admin account.

---

## 5. Security notes

- Per-user keys are stored **encrypted** in Nextcloud (sensitive user values,
  encrypted with the server secret), and are never sent to the browser or
  written to logs.
- OpenAI-compatible endpoint secrets are **encrypted at rest** in Synaplan
  (AES-256, key derived from `APP_SECRET`).
- Treat the **admin API key** like a root credential: it can create users and
  mint keys. Enter it only in Nextcloud’s admin settings, which store it
  encrypted (a sensitive app value).
- Endpoint listings and settings responses never echo secrets back.
- Upgrading to 1.7.0 encrypts keys that earlier versions stored in plain text,
  each on first use. Going back to 1.6 afterwards is not supported: 1.6 cannot
  read the encrypted admin key, so you would have to enter it again.

---

## 6. Testing checklist

1. **Per-user provisioning**
   - Enable per-user mode, save, test connection (should succeed with an admin
     key).
   - Log in as two different Nextcloud users; each clicks **Activate AI** and
     makes a different document searchable (right-click →
     **Make searchable by AI**).
   - Confirm in Synaplan’s admin UI that two separate accounts exist
     (`providerId = external`, source nextcloud), and that in the AI chat (the
     chat button at the bottom right) each user only finds their own document.
2. **Connect your Synaplan account**
   - Click **Register this instance**; it reports **Registered and ready**.
   - As a person with a Synaplan account: Personal settings → Synaplan →
     **Connect Synaplan**, sign in, confirm, then **Summarize with Synaplan** on
     a file.
   - Disconnect that link in Synaplan: the next Summarize asks to connect again.
3. **OpenAI-compatible backbone**
   - Register your endpoint, run the `/test` call (expect `ok: true`).
   - Register a chat + an embedding model.
   - In the AI chat, pick the chat model and ask a question; make a document
     searchable and confirm the chat finds it (uses your embedding model).

---

## Roadmap

Shipped since this guide first listed them as planned:

- **Changed files** (1.5.0) — making a file searchable again updates its copy
  in place, found by the Nextcloud file id. The **Make searchable by AI**
  dialog warns when the file changed in Nextcloud since, and **Update** brings
  the copy up to date in one click.
- **AI users panel** — Administration settings → Synaplan → **AI Users** lists
  the people who turned AI on, with their Synaplan account and usage.
  **Deactivate** turns AI off for one person: a linked person’s key is revoked
  in Synaplan; for a provisioned person Nextcloud forgets the key, which stays
  valid in Synaplan until you revoke it there.
- **Scope enforcement** — Synaplan enforces the scopes of per-user keys (§2).

Planned, **not yet implemented**:

- **Watched folders** — choose a folder once; a new or changed file in it is
  made searchable, summarized or translated without a click, with a list of
  what happened. Until then nothing re-reads a changed file on its own: a
  person opens the dialog and clicks **Update**.

The design lives in the public Synaplan repository, under
`_devextras/planning/20261013-cloud-file-sharing/`.
