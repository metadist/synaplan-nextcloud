# Changelog

All notable changes to the Synaplan Nextcloud Integration will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## 1.4.0 – 2026-06-28

### Added
- **Backend environment switch (Live / Local).** Admin settings now keep two
  separate Synaplan profiles — **Live (production)** and **Local
  (development)** — each with its own URL and API key, plus an **Active
  environment** toggle that flips the whole app (chat, media, knowledge, proxy)
  between them in one click. Makes it easy for any developer to point the app at
  a local Synaplan dev backend (e.g. `http://localhost:8000`) and switch back to
  live without re-entering credentials.

## 1.3.1 – 2026-06-28

Bug-fix release for the generated-media save flow.

### Fixed
- **Generated images/videos now display and can be saved.** A generated media
  card could show a broken image with a "Save to Nextcloud" button that saved a
  non-image (the backend served the file only after it had a database record).
  Paired with a Synaplan backend fix that registers generated media as a file,
  the image now loads and saving works.
- **No "Save" button for media that failed to load.** The "Save to Nextcloud"
  button now appears only once the image/video has actually loaded; if a
  generated file can't be loaded, a clear message is shown instead of an
  actionable save button.

## 1.3.0 – 2026-06-27

File delivery + provenance sprint — the first half of the cross-repo "Send to…"
work (Synaplan Release 4.0 Feature 7), coordinated with Synaplan's file-world
provenance model (`source` / original name).

### Added
- **Save any generated file into organised folders** — saving a Synaplan
  artifact to Nextcloud now sorts it into a typed sub-folder
  (`Synaplan/Documents`, `Synaplan/Audio`, `Synaplan/Calendar`,
  `Synaplan/Images`, `Synaplan/Video`); unknown types fall back to `Synaplan/`.
  The save endpoint is no longer limited to images/videos — it accepts any file
  type, ready for documents/audio/calendar as the chat surfaces them.
- **File provenance on knowledge uploads** — every file the app pushes to
  Synaplan (Add to Knowledge, and the Tika extraction step used for chat
  context) is now tagged `source=nextcloud` and carries its **original
  Nextcloud path** as the source name, so Synaplan can label where the file came
  from in its file manager / "Incoming" view.

### Notes
- Surfacing generated **documents/audio/calendar** inside the in-app chat (so
  they get a "Save to Nextcloud" button like images/videos) is a follow-up: the
  research chat currently generates only images/videos, and its text stream uses
  Synaplan's OpenAI-compatible endpoint, which does not emit generated-file
  events. The save + folder infrastructure added here is already type-agnostic
  and ready for that step.

## 1.2.1 – 2026-06-24

Major upgrade focused on language handling, personalisation, real streaming, and a clearer, better-formatted chat experience.

### Added
- **App version in the chat tagline** — the Research/launcher header now shows the running version (e.g. "Ask anything v1.2.1 — powered by Synaplan"), read dynamically from the installed app version.
- **Configurable answer language** — a new **"Language & AI behaviour"** admin section lets you pick a **default answer language** and toggle **"Use each user's Nextcloud interface language when supported"**. A German user then gets German answers automatically, with the default as the fallback. This replaces the previous behaviour where chat and document tools were effectively always English. The resolved language is injected as a system instruction into the streaming chat and passed through to summaries/translations, and the Summarize/Translate dialogs pre-select it.
- **Personal memories in chat** — when Synaplan's memory service (Qdrant) is reachable and the admin has enabled it (**"Allow personal memories in chat"**), the Research/launcher chat shows a **"Use my memories"** switch (on by default). Relevant memories are searched per question and injected as context so answers are personalised. The control is hidden entirely when the service is unavailable.
- **Live chat status row** — at-a-glance chips show the **active model**, the **answer language**, whether **image (`/pic`) and video (`/vid`) generation** are available, the selected knowledge base, and the memory state. Each answer is captioned with the model that actually produced it.
- **Staged progress while waiting** — instead of a static "Thinking…", the chat now reports what the backend is doing: *Sorting your request → Searching the knowledge base → Recalling your memories → Generating the answer*, stopping the moment streaming begins.
- **Markdown rendering for document results** — Summarize and Translate results now render Markdown (headings, bold, lists, code, links) via `NcRichText`, matching the chat, instead of showing raw `**`/`-` source.
- **Client-config endpoint** (`api#clientConfig`) exposing the resolved language and memory availability to the frontend.
- **Staged upload progress** — the "Add to Knowledge" dialog shows a progress bar and a phase checklist (Uploading → Extracting text → Creating chunks → Generating embeddings) with elapsed time.

### Changed
- **Real token-by-token streaming** — chat answers now stream live. The SSE relay was rewritten to a direct cURL pass-through (`SseProxyResponse`) that writes each chunk straight to the browser and defeats PHP/Nextcloud output buffering (disables `zlib`/output buffering, `Accept-Encoding: identity`, `X-Accel-Buffering: no`, connection priming). Previously the answer arrived all at once after a long pause.
- **Higher-contrast status chips** — active capabilities use a solid brand fill with guaranteed-contrast text; disabled ones are clearly muted but still legible in both light and dark mode.
- **Single-ring loading spinner** — Summarize/Translate dialogs use one clean spinner instead of the twin-circle icon.
- **Tighter Markdown spacing** — list items in rendered answers no longer have oversized gaps.
- **Per-chunk CSS injection** (`relativeCSSInjection`) so shared component styles (NcSelect, NcCheckboxRadioSwitch) are injected by every entry that uses them — fixes unstyled dropdowns/switches on the Research page, the in-page launcher, and the file-action dialogs.

### Fixed
- **Answers are no longer "always English"** — the chat/streaming path previously sent a hard-coded `en` output language; it now respects the admin default and/or the user's interface language.
- **Admin toggles are clickable** — the language/memory switches (and the chat "Use my memories" switch) used the wrong prop/event binding for `@nextcloud/vue` v9 and could not be toggled; they now use the correct `v-model`.
- **Knowledge upload no longer shows a false success** — when the server stores 0 chunks (empty/image-only file, or the embedding service is unavailable), the dialog reports a clear error instead of a green "File added" screen with "Chunks created: 0".
- **Removed leftover "this is a demo" text** from the knowledge-upload progress hint.

### Security / dependencies
- Updated `vite` to `7.3.5`, fixing a moderate dev-server advisory affecting `7.0.0–7.3.3`.
- Refreshed dependency lockfiles: `friendsofphp/php-cs-fixer` `3.95.2`, `prettier` `3.8.4`, `@nextcloud/vue` `9.8.2`, `@nextcloud/axios` `2.6.0`, `@nextcloud/dialogs` `7.4.0`, `vue` `3.5.38`, and `@nextcloud/initial-state` `3.0.0`.

## 1.1.2 – 2026-06-02

### Fixed
- **Chat launcher polish** — taller window (+50px), Knowledge/Model selectors closer together, and the input field no longer clipped at the bottom edge (box-sizing fix).

## 1.1.1 – 2026-06-02

### Fixed
- **Chat launcher layout** — enlarged the window, stacked the Knowledge/Model selectors (no more overlap), tuned fonts/margins, raised contrast (brand-coloured header), and the floating button now hides while the window is open so they never overlap.

## 1.1.0 – 2026-06-02

### Added
- **In-page chat launcher** — a floating button (bottom-right) on every Nextcloud page that opens the Synaplan AI assistant in a compact chat window, without navigating to the full-page Research view. Reuses the existing Research chat (knowledge group + model selection, `/pic` & `/vid` media commands).

### Fixed
- **Boxed Office/Collabora editor** — the modal-fix styles were global and capped Nextcloud's own Viewer modal (which hosts the Collabora editor) at `max-width: 900px`, boxing the editor chrome. These rules are now scoped (via `:has()`) to the plugin's own modals only, so the Office editor renders full-width again.

## 1.0.0 – 2025-02-14

### Added
- **Summarize documents** via file context menu (bullet points, paragraph, abstractive; short/medium/long; 12 output languages)
- **Translate documents** via file context menu (12 target languages)
- **Add to AI Knowledge** — upload files to the Synaplan vector knowledge base with group management
- **Research Chat** — full-page AI assistant accessible from top navigation with knowledge group and LLM model selection
- Support for binary document formats (PDF, DOCX, ODT, XLSX, PPTX) via Synaplan's Tika text extraction
- Admin settings page with Synaplan URL and API key configuration
- Connection test functionality in admin settings
- Top-level "Synaplan" navigation entry with custom bird logo
- Upload progress spinner with elapsed time counter for long-running knowledge uploads
- Improved success state with high-contrast SVG checkmark for dark theme compatibility
- Unit tests for all controllers (42 tests, 114 assertions)
- CI pipeline with PHP lint (PSR-12), PHPUnit, ESLint, Prettier, and Vite build checks
