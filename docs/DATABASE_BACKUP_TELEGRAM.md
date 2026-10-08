# Database backups in Telegram

`app:backup-db` retains the existing daily database-only backup. Set
`BACKUP_TELEGRAM_ENABLED=true` to upload the resulting encrypted ZIP to the
administrator's Telegram before local backup cleanup. The existing
`TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` are used unless dedicated
`BACKUP_TELEGRAM_*` settings override them.

Requirements:

- `BACKUP_ARCHIVE_PASSWORD` must be set. Every file in the ZIP must use AES-256.
- The `local` backup destination must remain enabled. An alternative local
  destination can be selected with `BACKUP_TELEGRAM_DISK`.
- Archives must be at most 49,000,000 bytes, below Telegram Bot API's 50 MB limit.
- The response must confirm a message ID and the complete archive byte count.

The upload streams the archive and adds its SHA-256 to the caption. The password
is never sent in the message. Creation or delivery failure leaves old backups
untouched, exits with an error, and reports through the existing admin alerts.
If Telegram is also unavailable for alerts, inspect the application log.

To send the latest existing backup without creating or cleaning archives:

```sh
php artisan app:backup-db --send-latest
```

Run commands as the application's service user, not root. Archive creation,
encryption and receipt checks do not replace a restore test. Keep the archive
password, Laravel `APP_KEY` and any previous encryption keys outside the VPS in
a password manager. Never commit them, SQL dumps or backup ZIPs to GitHub.

Telegram is an additional off-server copy, not immutable archival storage.
If the archive exceeds the size limit, uploads fail safely and notify the admin;
switch to larger external storage before that limit is reached.
