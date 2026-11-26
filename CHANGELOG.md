# Changelog

All notable changes to `threadlive/threadlive-laravel`. The widget,
`threadlive-react`, is released with the same version numbers; use the two
at the same version.

## 0.2.0 — 2026-11-26

- **AI assistant** (`assistant` block, off by default; Google Gemini with
  `THREADLIVE_GEMINI_KEY`, or your own `Contracts\Assistant` as `driver`).
  It answers a new or reopened conversation first (`first`: `always` or
  `off_hours`), from `about` and the whole FAQ (`show: false` entries too:
  out of the widget, still known to the AI), keeping its facts as written
  and promising nothing, reading the conversation with its earlier visits
  marked as such (a close or two hours of silence) and answering the
  visitor's last message — a "test" or "hi" in the browser's language, with
  no manager called — after the visitor's message is in
  the topic; its replies show in the topic silently under 🤖, with the
  managers' translation. It hands the conversation to the managers —
  ❗ again, "🙋 Needs a manager" with the reason and a summary, with sound —
  when it cannot help, when the visitor presses "Talk to a person"
  (`POST /handoff`), after `max_replies`, past `daily_limit`, or on any
  error. A manager's reply or 🙋 Take takes over at once; `/ai on|off` and
  the ⚙️ Actions buttons switch it per conversation. Topics read 🤖, the
  dashboard counts "🤖 With the assistant", and waiting alerts count from
  the handoff. The API adds `conversation.handler`,
  `conversation.assistant_typing` and `message.ai`. `ai` is now a reserved
  command name.
- **Managers called by name.** A handoff calls the manager who has the
  conversation in its topic; with nobody on it, it is also posted in
  General with a "💬 Open the conversation" button and the usernames of the
  new `mention` setting (`['@maria']`) — a mention reaches them even in a
  muted group. Waiting alerts mention them the same way.
- **The prompt is yours to replace.** The package's is a general one for a
  website's live chat; `php artisan threadlive:make-prompt` copies it into
  `app/Threadlive/AssistantPrompt.php`, which replaces it whole from then on
  (`assistant.prompt` names the class). `PromptContext` gives it who we are
  (`about`), the time, the hours, the FAQ, the page and the languages; the
  answer's shape is fixed by the package, so a rewritten prompt cannot
  break a handoff. `threadlive:assistant show|ask` prints and tries it.
- **Schema:** a new migration (`handler`, `handed_off_at`,
  `handoff_reason`, `assistant_replies` on conversations, `by_assistant` on
  messages). `php artisan threadlive:install` publishes it and runs
  migrations; run `php artisan threadlive:config` for the `assistant`
  block, and `threadlive:setup` to list `/ai` in the group.
- **Fixes:** files reach Telegram with the oldest dependencies allowed
  (Laravel 12.0 with guzzlehttp/psr7 1.x refused an empty file part).
  `/menu`, `/close`, `/translate` and `/ai` sent in General (or
  a topic of no conversation) now say where they work instead of doing
  nothing. `threadlive:listen` waits for Telegram when it cannot reach it
  at the start (a timeout, a reset connection) instead of exiting — under
  `composer dev` that took the server and the queue down with it; a refusal
  from Telegram (a wrong token) still stops it. A delivery job lost before it ran no longer blocks the
  conversation's later messages (its lock expires after 60 s); the `deepl`
  driver without a key is off instead of failing every translation.

- **Google Gemini translation.** `translation.driver = 'gemini'` with
  `THREADLIVE_GEMINI_KEY` and the new `gemini` block (model, default
  `gemini-3.5-flash-lite`; thinking level). DeepL stays a driver. Run `php artisan threadlive:config` to add
  the block to a published config.
- **Translation by itself** (`translation.auto`, default false): the first
  message of a conversation tells the visitor's language; one other than
  `translation.target` turns translation on, with a silent line in the
  topic. Once per conversation — a manager's 🚫 Off stays off. One
  translation per new conversation.
- **Buttons instead of commands.** ⚙️ Actions on the visitor card opens,
  in place, one button per translation language (the current one ticked)
  and one per manager command (it asks for its argument); `/menu` posts the
  same buttons at the bottom of the topic. `menu` is now a reserved command
  name. Run `php artisan threadlive:setup` to list `/menu` in the group.
- **Quick answers (FAQ).** Send a `faq.json` in the group with the caption
  `/faq` (checked whole: with a mistake nothing changes, and the bot lists
  what is wrong); `/faq` alone sends the current file back. Also
  `php artisan threadlive:faq import|export`. Texts may come in several
  languages (`{"en": "…", "uk": "…"}`); with `faq.translate` on, the
  translation driver fills in `faq.languages` an entry lacks, in the
  background. `/bootstrap` serves the list; the answers a visitor opened
  show on the visitor card. `faq` is now a reserved command name.
- **The chat speaks the visitor's language.** New `language` block:
  `browser` (default true) picks the browser's language when there is a
  text in it, else `default` (`en`). `/bootstrap` now returns `languages`
  (best first) for the widget's own texts and serves the FAQ in that
  language. Run `php artisan threadlive:config` to add the `language` and
  `faq` blocks.

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
