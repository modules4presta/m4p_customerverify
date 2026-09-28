# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-28

### Added

- New accounts are held inactive until an administrator activates them, with an e-mail to the shop
  at registration and an invitation to the customer at approval.
- A message for the customer right after registering.
- English and Polish translations, MIT license and the standard documentation set.

### Fixed

- The English mail templates were Polish copies.
- The pending notice could pin a visitor to the home page on a theme that does not render
  `displayAfterBodyOpeningTag`; it now expires after five minutes.
- The modal styles and script were inlined in the template instead of being registered as assets.
