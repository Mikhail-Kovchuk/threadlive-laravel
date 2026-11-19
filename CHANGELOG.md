# Changelog

All notable changes to `threadlive/threadlive-laravel`. The widget,
`threadlive-react`, is released with the same version numbers; use the two
at the same version.

## 0.1.0 — 2026-11-19

First release.

- **Conversations in Telegram topics.** One forum topic per conversation,
  named for its state (❗ waiting, 💬 answered, ✅ closed) and renamed only
  when that changes; a visitor card with 🙋 Take and ✅ Close; a returning
  visitor's deleted topic is recreated with a summary.
- **Widget API** (`/threadlive/*`): bootstrap, messages, read receipts,
  offline e-mail; guests by a token (stored hashed), signed-in users through
  the app's auth guard (`ChatHost`); retried sends stored once.
- **Managers:** replies, `//` internal notes, edits, `/close`, `/find`,
  the app's own commands (`threadlive:make-command`), a pinned dashboard in
  General, "⏰ waiting" alerts within support hours.
- **Translation** per conversation (`/translate`): DeepL built in, any other
  through `Contracts\Translator`; replies reach the visitor in their language.
- **Attachments** both ways: images and PDF, private disk, signed links.
- **Offline e-mail** of replies not read in the widget; publishable view.
- **Spam protection:** Cloudflare Turnstile on a guest's first message;
  per-IP and per-conversation limits.
- **Delivery** through the queue, within Telegram's limits; undelivered
  messages on the dashboard and `threadlive:retry-failed`.
- **Commands:** `install`, `config`, `setup` (`--local`), `listen`,
  `status`, `test`, `make-command`, `retry-failed`; `maintain` and `prune`
  scheduled by the package.
- PHP 8.3+, Laravel 12 and 13.
