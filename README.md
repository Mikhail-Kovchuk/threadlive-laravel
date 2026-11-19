# threadlive/threadlive-laravel

**Threadlive — live chat in Telegram threads.** The Laravel side: the API the
widget talks to, the Telegram webhook, and one forum topic per conversation in
your support group. The widget is
[`threadlive-react`](https://www.npmjs.com/package/threadlive-react).

This is a read-only mirror of the `laravel/` folder of
[Mikhail-Kovchuk/threadlive](https://github.com/Mikhail-Kovchuk/threadlive),
written by its release workflow. Issues and pull requests go there.

Requirements: PHP 8.3+, Laravel 12 or 13, a queue worker and the scheduler.

## Install

```bash
composer require threadlive/threadlive-laravel
php artisan threadlive:install
```

`threadlive:install` publishes `config/threadlive.php`, the migration and
`app/Threadlive/ChatHost.php`, adds the `THREADLIVE_*` keys to `.env` (with a
webhook secret), and offers to migrate.

### The Telegram side

1. Create a bot with [@BotFather](https://t.me/BotFather) — a new one, used
   for nothing else — and put its token in `THREADLIVE_BOT_TOKEN`.
2. Create a Telegram group, turn **Topics** on, and add the bot as an admin
   with *Manage topics*, *Delete messages* and *Pin messages*. Add your team.
3. Put the group's id (`-100…`) in `THREADLIVE_GROUP_ID`.
4. Run `php artisan threadlive:setup`. It checks the bot, the group and the
   rights, sets the webhook to your HTTPS address, puts the commands in the
   group's "/" menu and pins a dashboard in General. On a machine without
   public HTTPS: `threadlive:setup --local`, then keep
   `php artisan threadlive:listen` running.
5. Set `THREADLIVE_ENABLED=true`.

Keep a queue worker running (messages go to Telegram from the queue) and the
scheduler (`php artisan schedule:run` every minute: auto-close, alerts,
offline e-mail, cleanup).

### Your site

- **CORS:** when the widget runs on another origin, add `threadlive/*` to
  `paths` in `config/cors.php`.
- **Who is writing:** `app/Threadlive/ChatHost.php` reads the signed-in user
  from your auth guard (`guard` in the config; null is the default guard) —
  a JWT API guard works the same way. Guests can write too
  (`allow_guests`). It also gives the managers' card its lines and an admin
  link, and the visitor a "About invoice INV-1042" card from what the page
  sent.

## What managers get

Each conversation is a topic named for its state — `❗ Anna · #12` waiting,
`💬 Maria → Anna · #12` answered, `✅` closed — with a card about the visitor
(🙋 Take / ✅ Close). Whatever a manager writes in the topic goes to the
visitor; a message starting `//` is a note that never does. Photos and
documents go both ways.

| In the group | |
|---|---|
| `/close` | close the conversation |
| `/translate [ru\|off]` | translate this conversation both ways (DeepL built in) |
| `/find 12` · `/find anna@example.com` · `/find refund` | find conversations |
| `/your_command …` | your own: `php artisan threadlive:make-command Invoice` |

General keeps a pinned dashboard: who is waiting and for how long, who is
answering what, messages that could not be delivered.

## Settings

`config/threadlive.php` holds everything, each setting with its comment:
support hours, topic buttons, the header's link out, timings (auto-close,
waiting alerts, offline e-mail), translation, attachments, polling. After a
package update, `php artisan threadlive:config` adds the settings it brought
and keeps your values.

`.env` holds only what differs between environments or is secret:
`THREADLIVE_ENABLED`, `THREADLIVE_BOT_TOKEN`, `THREADLIVE_GROUP_ID`,
`THREADLIVE_WEBHOOK_SECRET`, `THREADLIVE_TURNSTILE_SITE_KEY`,
`THREADLIVE_TURNSTILE_SECRET`, `THREADLIVE_QUEUE_CONNECTION`,
`THREADLIVE_TRANSLATION_KEY`.

- **Spam:** set the two Cloudflare Turnstile keys and a guest's first message
  carries a Turnstile check.
- **Offline e-mail:** replies a visitor has not read in the widget go to the
  e-mail they left, through your app's mailer. Reword it with
  `php artisan vendor:publish --tag=threadlive-views`.

## Commands

| | |
|---|---|
| `threadlive:install` | publish config, migration, ChatHost; `.env` keys |
| `threadlive:config` | create the config, or add new settings to yours |
| `threadlive:setup [--local]` | check and connect Telegram |
| `threadlive:listen` | take updates by polling (local development) |
| `threadlive:status` | webhook state, conversation counts, undelivered messages |
| `threadlive:retry-failed` | send undelivered messages again |
| `threadlive:test` | open, post in and close a test topic |
| `threadlive:make-command Name` | a command for managers |
| `threadlive:maintain`, `threadlive:prune` | scheduled by the package |

## License

[MIT](LICENSE)
