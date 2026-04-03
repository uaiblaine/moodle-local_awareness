# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning principles where possible.

## [Unreleased]

### Added
- GitHub Actions workflows for CI and Moodle plugin release.
- Baseline repository files for quality tooling (`.gitignore`, `.stylelintrc.json`).

### Changed
- Rebranded documentation and test navigation paths from "Site Notice" to "Awareness".
- Expanded plugin documentation with full feature and usage guidance.
- Updated Behat administration navigation path to `Awareness > Settings`.

### Fixed
- PHPUnit data provider typo in awareness tests (`allowdeltion` -> `allowdeletion`).
- CI reusable workflow input mismatch (`disable_phpcpd` removed).
- AMD JSDoc compatibility issue in `amd/src/course_search.js`.
