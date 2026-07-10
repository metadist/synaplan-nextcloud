# Synaplan Integration — Hosting-Company Guide

Audience: operators who host Nextcloud (or ownCloud / ownCloud.online) for their
customers and want to offer Synaplan AI features **with per-user isolation** and
**their own AI backbone**.

This guide covers the two features shipped for the hosting scenario:

1. **Per-user Synaplan accounts** — every Nextcloud user gets their own Synaplan
   account, API key, knowledge base, memories and usage. Nothing is shared
   across your customers.
2. **Bring-your-own OpenAI-compatible AI** — point Synaplan at your own LocalAI /
   vLLM / LiteLLM (or any OpenAI-compatible) endpoint and expose those models to
   users, including chat, embeddings and vision.

> Roadmap items that are planned but **not yet implemented** (bulk folder sync,
> stale re-index, admin cross-usage panel) are listed at the end under
> [Roadmap](#roadmap).

---

## 1. Prerequisites

- A running **Synaplan** instance reachable from your Nextcloud server over HTTPS.
- A Synaplan **admin account**, and an **admin API key** for it
  (Synaplan → Settings → API Keys → *Create key* while logged in as an admin).
  In per-user mode this key is used only to provision accounts and mint per-user
  keys — never for end-user traffic.
- Nextcloud 30–34, PHP 8.2+.

---

## 2. Enable per-user accounts

1. In Nextcloud, go to **Administration settings → Synaplan Integration**.
2. Set the **Synaplan URL** and paste the **admin API key**.
3. Turn on **“Give each user their own Synaplan account.”**
4. Click **Save**, then **Test connection**.

That is all. From now on, the first time any Nextcloud user triggers a Synaplan
feature (summarize, translate, add-to-knowledge, chat), the app will, using your
admin key:

- create a Synaplan account for that user (idempotent — one account per user),
  identified by `source = "nextcloud"` and
  `external_id = "<nextcloud-instance-id>:<uid>"`;
- mint a **per-user API key** scoped to `chat`, `files`, `rag`;
- store that key server-side in the user’s Nextcloud preferences (never exposed
  to the browser) and use it for all that user’s Synaplan traffic.

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

- If a user’s per-user key is revoked in Synaplan, the next request returns 401;
  the app automatically forgets the stale key and re-provisions on the following
  request. No user action required.
- Deleting the Nextcloud user does **not** yet auto-delete the Synaplan account
  (see [Roadmap](#roadmap)); revoke it in Synaplan’s admin UI if required.

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

- Per-user keys are stored in Nextcloud **user preferences** (server-side) and
  are never sent to the browser or written to logs.
- OpenAI-compatible endpoint secrets are **encrypted at rest** in Synaplan
  (AES-256, key derived from `APP_SECRET`).
- Treat the **admin API key** like a root credential: it can create users and
  mint keys. Store it only in Nextcloud’s admin settings (encrypted app config).
- Endpoint listings and settings responses never echo secrets back.

---

## 6. Testing checklist

1. **Per-user provisioning**
   - Enable per-user mode, save, test connection (should succeed with an admin
     key).
   - Log in as two different Nextcloud users; each opens Research Chat and adds a
     different document to knowledge.
   - Confirm in Synaplan’s admin UI that two separate accounts exist
     (`providerId = external`, source nextcloud), and that each user’s RAG only
     returns their own document.
2. **OpenAI-compatible backbone**
   - Register your endpoint, run the `/test` call (expect `ok: true`).
   - Register a chat + an embedding model.
   - In Research Chat, pick the chat model and ask a question; add a document and
     confirm retrieval works (uses your embedding model).

---

## Roadmap

Planned in the design docs, **not yet implemented** in this release:

- **Bulk folder sync** — automatically ingest whole Nextcloud folders and keep
  them in sync (filesystem events + nightly reconciliation), with GPU-cost-aware
  debouncing.
- **Stale / overwrite handling** — mark knowledge entries stale when the source
  file changes, overwrite-in-place by source id, delete-after-embed.
- **Admin cross-usage panel** — an in-Nextcloud table mapping NC users ↔ Synaplan
  accounts with per-user usage and controls (revoke key, pause sync, delete
  data).
- **API-key scope enforcement** — per-user keys currently carry scopes
  (`chat`, `files`, `rag`) but Synaplan does not yet *enforce* them; a scoped key
  still authenticates as its user with full access. Do not rely on scopes as a
  security boundary yet.

The detailed design for all of the above lives in the planning docs
(`synaplan/_devextras/planning/20260709-hosting-partner-core-requirements.md` and
`.local/20260709-hosting-partner-extension-plan.md`).
