# Changelog

All notable changes to the Synaplan Nextcloud Integration will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
