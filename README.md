# M4P Customer Account Verification for PrestaShop 8 & 9

**Decide who gets to see your wholesale prices — new accounts stay inactive until someone at your shop approves them.**

> **Meta description (149 chars):** Hold new PrestaShop accounts until an administrator approves them. Both sides are told by e-mail, no account reaches your prices first. Free MIT module.

---

## Why a wholesale shop cannot open registration to everyone

A B2B shop shows different prices to different customers, and often hides them from the public
entirely. An account created in ten seconds by anyone with an e-mail address defeats that:

- **Prices stay with your customers** — a competitor cannot register and read your price list
- **Someone checks the company first** — a VAT number, an order history, a phone call
- **The customer knows what is happening** — a message on screen and an e-mail, not silence
- **No account slips through** — verification happens at registration, not at the first order

## What the module does

When someone registers, the module deactivates the account, logs the visitor back out and shows a
message explaining that the account is waiting for approval. Your shop gets an e-mail with the
details. The moment an administrator activates the account in **Customers**, the customer receives
an e-mail with a link to sign in.

### Key features

- **Nothing to remember** — approval is the usual "Enable" switch on the customer, no separate screen
- **Both sides are told** — a notice to the shop at registration, an invitation to the customer at approval
- **One e-mail per account** — editing an already active customer does not send the invitation again
- **Own table for pending accounts** — nothing is added to PrestaShop's `customer` table
- **One switch** — turn verification off and registration goes back to normal

### What it does not do

The module does not check VAT numbers, does not build an approval queue with its own screen and does
not reject anyone by itself. It stops the account and tells you; the decision stays with a person.

## Compatibility

| | |
|---|---|
| PrestaShop | 1.7.6 – 9.x |
| PHP | 7.2.5+ |
| Requirements | a working shop e-mail configuration |
| Multistore | Settings and pending accounts are shared across shops |
| Themes | The message needs a theme that renders `displayAfterBodyOpeningTag` (all standard themes do) |

The module performs no core overrides. It creates one table, `m4p_pending_verification`, and drops
it on uninstall.

## Installation

1. Upload and install the module from **Modules → Module Manager**.
2. Open the module configuration and set the address for notifications, or leave it empty to use the
   shop e-mail.
3. Register a test account in the front office — it should stay inactive and you should get an e-mail.
4. Activate that customer in **Customers** and check that the invitation arrives.

## Configuration options

| Setting | Description |
|---|---|
| **Hold new accounts for approval** | Turns verification on. Off means registration works as usual. |
| **Notification e-mail** | Where the notice about a new account is sent. Empty means the shop e-mail. |

## Frequently asked questions

**How do I approve an account?**
In **Customers**, open the account and switch **Enable** on. That is the whole workflow — the module
watches that change and sends the invitation.

**What does the customer see right after registering?**
A message saying the account is waiting for approval. They are signed out, because the account is
not active yet.

**Does an existing customer get the e-mail again when I edit their details?**
No. The invitation is sent once, when an account that was waiting for approval becomes active.

**What happens to accounts created before the module was installed?**
Nothing — they are untouched and stay active. Verification applies to registrations from now on.

**What happens to pending accounts when I uninstall the module?**
The table is dropped. The accounts stay in PrestaShop, inactive, and you activate them by hand.

---

**Keywords:** PrestaShop account approval, B2B registration, wholesale customer verification, manual
account activation, hide prices from public.

## License

MIT — see [LICENSE](LICENSE). Free to use commercially, fork and modify; keep the copyright notice.

## Contributing

Bug reports and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). For security
issues, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

---

Built by [Nice Code](https://nice-code.com/pl/produkty/sklep-b2b-prestashop) — we build B2B stores on PrestaShop.

© Nice Code sp. z o.o. (Modules4Presta) — released under the MIT license.
