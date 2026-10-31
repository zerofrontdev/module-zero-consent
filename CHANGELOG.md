# Changelog: zerofront/module-zero-consent

## 1.3.6

- An account without a password (made by a social sign-in) can now delete itself: the button emails a link to the account's address (valid one hour, used once, at most one email a minute), and only the confirm button on the link's page deletes the account.
- New setting Stores > Configuration > Zero > Cookie Consent > Personal Data (GDPR) > **Deletion Link Email**.
- After an account is deleted, the event `zero_consent_account_erased` (customer_id) fires.
- `setup:upgrade` adds the table `zero_privacy_deletion`.
- Accounts with a password are unchanged: they confirm the deletion with the password.

Earlier releases (1.3.5 and before): see the Git tags.
