# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-11-17

### Added

- "Account active before approval" (off by default and after the upgrade): a company account can sign in
  and shop right after registering, with the default customer group, and stays in the approval queue.
  The shop gets the usual notice e-mail. After registering on the registration page the customer stays
  signed in and goes to the home page. Approve assigns the group and sends the "price list updated" e-mail
  (or "company account approved" when the group stays the same); Reject keeps the account as a regular
  one. The company option on the registration form says that regular prices apply until approval.
- One-time notices at the top of the home page content for a signed-in customer: after registering a
  company account with "Account active before approval" on, and after approval assigns a group. The page
  stays cacheable: a small script loads the notices from an uncached module controller, and each notice is
  removed once shown. Notices are kept in the `m4p_customerverify_notice` table.
- "Price list updated" e-mail, sent when approval assigns a group to an account that is already active.
- "Only the group after approval" (on by default and after the upgrade): an approved account keeps only
  the group set for approval, so prices and a full-page cache keyed on the customer's group follow that
  group. Off adds the group next to the existing ones.
- A group picker in each row of the approval queue, preselected with the group from the settings; any
  group except visitor and guest can be chosen, or "Do not change the group".

### Changed

- The main switch is called "Company account verification".
- Approve and Reject in the queue are form buttons (POST).
- Only switching a pending account from inactive to active in Customers counts as approval; other edits
  of a pending account leave it in the queue.

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
- The modal styles and script are registered as assets.
