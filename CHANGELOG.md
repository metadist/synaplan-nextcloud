# Changelog

All notable changes to the Synaplan Nextcloud Integration will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- **Knowledge upload no longer shows a false success** — when the server stores 0 chunks (empty/image-only file, or the embedding service is unavailable), the dialog now reports a clear error instead of a green "File added" screen with "Chunks created: 0".
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
