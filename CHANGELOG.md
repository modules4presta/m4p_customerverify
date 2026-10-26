# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-10-21

### Added

- Company name and tax number (NIP or EU VAT number) on the registration form, required for a company
  account and saved in the customer's `company` and `siret`. A Polish NIP is checked by its checksum,
  an EU VAT number in VIES through `m4p_viesintegration` when that module is enabled.
- Company and tax number in the queue and in the notice e-mail to the shop.

### Changed

- The company account checkbox is an account type choice: private person or company.

## [1.1.0] - 2026-10-02

### Added

- "Only company accounts" mode: a checkbox on the registration form, and only the accounts that tick it
  are held for approval. Checkout and the identity page keep their forms. The upgrade leaves the mode
  off, so every new account is still held.
- Back-office queue in Customers > Accounts to approve, with Approve and Reject actions.
- A group assigned on approval (added and set as the default group).
- Rejection activates the account as a regular one and sends the customer an e-mail.

### Changed

- The pending notice is shown on the sign-in page through the shop's notifications, a page that
  full-page caches do not store.

### Removed

- The home page modal, its stylesheet and script.

## [1.0.0] - 2026-09-28

### Added

- New accounts are held inactive until an administrator activates them, with an e-mail to the shop
  at registration and an invitation to the customer at approval.
- A message for the customer right after registering.
- English and Polish translations, MIT license and the standard documentation set.

### Fixed

- English mail templates alongside the Polish ones.
- The pending notice expires after five minutes, so it does not depend on the theme rendering
  `displayAfterBodyOpeningTag`.
- The modal styles and script are registered as assets instead of being inlined in the template.
