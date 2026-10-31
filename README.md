# Zero_Consent

Privacy for the Zero storefront: the visitor's cookie choice for every
tracker (GDPR, ePrivacy, CCPA/CPRA), and the customer's own personal data
in My Account (GDPR articles 15, 17 and 20).

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-consent
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Cookie consent

- **The banner**: Accept all, Reject all, or Choose analytics and
  marketing separately. Shown while a tracker is on (Google Analytics, …);
  necessary cookies (cart, checkout, sign-in) need no consent.
- **Cookie Settings** in the footer reopens the choice at any time.
- **Global Privacy Control**: a browser sending GPC opts out of marketing.
- **Do Not Sell or Share My Personal Information** (CCPA/CPRA): a footer
  link that opens the choice, for stores selling to California.

A tracker registers with `Zero\Consent\Model\Trackers` (di.xml) through
`Zero\Consent\Api\TrackerInterface`, and in the browser reads
`window.zeroConsent.get()` and listens for the `zero:consent` event.

## My Account > Privacy

`/privacy/account`, a link in the My Account menu:

- **Download my data**: one JSON file with everything the store keeps
  about the customer: the account and its addresses, orders (items,
  amounts, addresses, IP), newsletter subscriptions, reviews, wish list
  and carts, and the data of Zero's modules (stock alerts, returns, quote
  requests, reward points, requisition lists, social sign-in, blog
  comments, gift cards received, the emails sent to them).
- **Delete my account**, with the password and a confirmation box: the
  customer is signed out, then in one transaction every section is
  deleted or anonymized and the account is deleted. Orders stay for the
  accounts; with **Anonymize the Orders of a Deleted Account** they lose
  the customer's name, email, addresses, phone and IP (order, addresses,
  and the order, invoice, shipment and credit memo grids), keeping the
  amounts, items, country, region and tax. Reviews, blog comments and
  returns stay without the name and email. The log records that account
  N was deleted, not who it was.
  An account without a password (made by a social sign-in) has no
  password field: the button emails a link to the account's address
  (**Deletion Link Email**; valid one hour, used once, at most one email a
  minute). The link opens `/privacy/account/confirmdelete`, which moves the
  token out of the address and asks to confirm; only the POST deletes, so
  a mail scanner opening the link changes nothing. The browser does not
  need to be signed in. After the account is deleted,
  `zero_consent_account_erased` (customer_id) fires.

Settings: Stores > Configuration > Zero > Cookie Consent > Personal Data
(GDPR): the page on or off, whether customers can delete their account
(off: the page asks them to write to the store), and whether orders are
anonymized (off: they keep the data, where the law asks the store to keep
invoices as issued).

### Adding a module's data

A section implements `Zero\Consent\Api\PersonalDataInterface` (`export()`
and `erase()`) and is added to `Zero\Consent\Model\Privacy\Sections` in
di.xml under a code. For data in one table, `Model\Privacy\Section\Table`
needs no code: a virtual type names the table, the columns for the
download, the customer id or email column, and whether erasing deletes the
rows or anonymizes some columns (see this module's etc/di.xml). A table
that doesn't exist is skipped.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
