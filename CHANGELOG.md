# Changelog

All notable changes to the Synaplan Nextcloud Integration will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## 1.2.0 – 2026-06-24

Major upgrade focused on language handling, personalisation, real streaming, and a clearer, better-formatted chat experience.

### Added
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

### Fixed
- **Answers are no longer "always English"** — the chat/streaming path previously sent a hard-coded `en` output language; it now respects the admin default and/or the user's interface language.
- **Admin toggles are clickable** — the language/memory switches (and the chat "Use my memories" switch) used the wrong prop/event binding for `@nextcloud/vue` v9 and could not be toggled; they now use the correct `v-model`.
- **Knowledge upload no longer shows a false success** — when the server stores 0 chunks (empty/image-only file, or the embedding service is unavailable), the dialog reports a clear error instead of a green "File added" screen with "Chunks created: 0".
- **Removed leftover "this is a demo" text** from the knowledge-upload progress hint.

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
