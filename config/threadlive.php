<?php

use Threadlive\Host\GuardChatHost;

/*
 * Threadlive — live chat in Telegram threads.
 *
 * Change things here, not in vendor/. `php artisan threadlive:config`
 * creates this file in the application, and after a package update adds the
 * settings the update brought, leaving the values you changed alone.
 *
 * Only what differs between environments, or is secret, comes from .env:
 * THREADLIVE_ENABLED, THREADLIVE_BOT_TOKEN, THREADLIVE_GROUP_ID,
 * THREADLIVE_WEBHOOK_SECRET, THREADLIVE_TURNSTILE_SITE_KEY,
 * THREADLIVE_TURNSTILE_SECRET, THREADLIVE_QUEUE_CONNECTION,
 * THREADLIVE_TRANSLATION_KEY and THREADLIVE_GEMINI_KEY. Everything else is
 * set below.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Environment (.env)
    |--------------------------------------------------------------------------
    */

    // Off until the bot and the group are set up. The widget reads this from
    // the bootstrap endpoint and stays hidden while it is false.
    'enabled' => env('THREADLIVE_ENABLED', false),

    // A bot of its own, never one the application already uses: the webhook
    // this package sets turns getUpdates off for that bot. Use another bot
    // for each environment.
    'bot_token' => env('THREADLIVE_BOT_TOKEN'),

    // The forum group (-100…), with Topics on and the bot an admin in it.
    'chat_id' => env('THREADLIVE_GROUP_ID'),

    // Telegram sends it back with every update; threadlive:install makes one.
    'webhook_secret' => env('THREADLIVE_WEBHOOK_SECRET'),

    // Cloudflare Turnstile on a guest's first message: both keys from the
    // Cloudflare dashboard (Turnstile → Add widget, your site's domains);
    // either empty = off. The widget loads Cloudflare's script for it: a
    // Content-Security-Policy must allow https://challenges.cloudflare.com
    // in script-src and frame-src.
    'turnstile_site_key' => env('THREADLIVE_TURNSTILE_SITE_KEY'),

    // The pair's secret key: it stays on the server.
    'turnstile_secret' => env('THREADLIVE_TURNSTILE_SECRET'),

    // Where sends to Telegram are queued; null = the application's defaults.
    'queue' => [
        'connection' => env('THREADLIVE_QUEUE_CONNECTION'),
        'name' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP and users
    |--------------------------------------------------------------------------
    */

    // The widget calls {APP_URL}/{route_prefix}/…. When the website and this
    // API are on different origins, add "{route_prefix}/*" to `paths` in
    // config/cors.php, or the browser blocks every request.
    'route_prefix' => 'threadlive',

    'middleware' => ['api'],

    // The guard that knows the signed-in user, e.g. 'api' or 'sanctum'.
    // null = the application's default guard.
    'guard' => null,

    // The class that tells the package about your users (implements
    // Threadlive\Contracts\ChatHost). The default reads the guard and adds
    // nothing; threadlive:install publishes app/Threadlive/ChatHost.php to
    // adapt, and points this at it.
    'host' => GuardChatHost::class,

    // false: only signed-in users may write.
    'allow_guests' => true,

    /*
    |--------------------------------------------------------------------------
    | Conversation lifecycle
    |--------------------------------------------------------------------------
    */

    // A conversation with no activity for this long is closed (✅).
    'close_after_hours' => 24,

    // A closed topic is deleted from Telegram after this long; the
    // conversation stays in the database. 0 = never delete.
    'delete_topic_after_days' => 7,

    // Conversations with no activity for this long are deleted, with their
    // files. 0 = keep forever.
    'retention_days' => 90,

    // Within support hours, a visitor waiting this long without a reply gets
    // a "⏰ … is waiting" post in General, with sound, once. 0 = off.
    'waiting_alert_minutes' => 10,

    // A reply not read in the widget within this long goes to the visitor's
    // e-mail, when they left one (or the signed-in user has one) — once per
    // batch of replies, through the app's mailer (MAIL_* in .env). 0 = off.
    // The text: php artisan vendor:publish --tag=threadlive-views; its
    // strings go through __(), so lang/{locale}.json translates them.
    'email_unread_after_minutes' => 15,

    // The e-mail's "Continue the conversation" button: your site, where the
    // chat is — e.g. 'https://example.com'. The visitor's own page is used
    // instead when it is on the same host. null: no button.
    'email_link' => null,

    /*
    |--------------------------------------------------------------------------
    | Managers
    |--------------------------------------------------------------------------
    */

    // Commands for the managers in the support group ("/" menu), each a class
    // implementing Threadlive\Contracts\ManagerCommand — what they look up is
    // up to you. php artisan threadlive:make-command Name creates one and
    // adds it here; run threadlive:setup after changing the list.
    'commands' => [],

    // The 🙋 Take button on each new topic.
    'claim_button' => true,

    // The first manager to reply is assigned the conversation.
    'assign_on_first_reply' => true,

    // true: the visitor sees "Maria · Support"; false: only the name below.
    'show_agent_name' => true,

    'agent_display_name' => 'Support',

    // Translation, switched on per conversation with /translate in its topic:
    // the visitor's messages reach the managers with a translation into
    // `target`, and the managers' replies reach the visitor in the visitor's
    // language. driver: 'gemini' (uses the `gemini` block below), 'deepl'
    // (key: THREADLIVE_TRANSLATION_KEY), a class implementing
    // Threadlive\Contracts\Translator, or null (off). `languages`: what
    // /translate <language> may switch to. auto: the first message of a
    // conversation tells the visitor's language, and one other than
    // `target` turns translation on by itself (a manager can still turn it
    // off); one translation per new conversation.
    'translation' => [
        'driver' => null,
        'key' => env('THREADLIVE_TRANSLATION_KEY'),
        'target' => 'en',
        'languages' => ['en', 'ru'],
        'auto' => false,
    ],

    // Google Gemini, for translation (driver 'gemini'). Use a key of a paid
    // project: on the free tier Google may train on what visitors write.
    // Flash-Lite translates in about a second; a manager's reply waits for
    // its translation, so a slower model shows. thinking: the thinking
    // level ('low' is enough to translate); null for a model without levels.
    'gemini' => [
        'key' => env('THREADLIVE_GEMINI_KEY'),
        'model' => 'gemini-3.5-flash-lite',
        'thinking' => 'low',
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget
    |--------------------------------------------------------------------------
    |
    | Served to the widget by the bootstrap endpoint, so changing them never
    | needs a frontend release.
    |
    */

    // Support hours. Outside them the widget says when the team is back and
    // asks for an e-mail. Equal ends: around the clock.
    'hours' => [
        'from' => '09:00',
        'to' => '23:00',
        'tz' => 'UTC',
    ],

    // Buttons on the widget's first screen; pressing one sends it as the
    // visitor's first message. Empty: no buttons.
    'topics' => [
        // 'A question about my account',
        // 'Billing',
        // 'Something else',
    ],

    // The language the chat speaks to a visitor: the widget's own texts and
    // the FAQ. browser: their browser's language when there is a text in it;
    // false — always `default`.
    'language' => [
        'default' => 'en',
        'browser' => true,
    ],

    // Quick answers on the widget's first screen, opened without writing to
    // anyone. Loaded from a faq.json sent in the group with the caption /faq
    // (/faq alone gives the current one back), or with
    // php artisan threadlive:faq import; kept at `path`. Each text may come
    // in several languages ({"en": "…", "uk": "…"}); a visitor gets theirs
    // as `language` above says. translate: the translation driver fills in
    // the `languages` an entry lacks when a file is loaded — never replacing
    // what the file has.
    'faq' => [
        'path' => storage_path('app/threadlive/faq.json'),
        'translate' => false,
        'languages' => [],
    ],

    // The header's "go elsewhere" button — your support account in Telegram,
    // WhatsApp, an e-mail address. url null: no button. Only https, http,
    // tg and mailto links. label null: the widget's own text ("Continue in
    // Telegram instead").
    'fallback' => [
        'url' => null,
        'label' => null,
    ],

    // How often the widget asks for new messages, in ms. `closed` runs only
    // for a live conversation (live_hours) and stops once the visitor has
    // been idle for `idle_after`; with the panel open, idling slows it to
    // `idle`. A hidden tab never polls.
    'poll_ms' => [
        'open' => 3000,
        'closed' => 30000,
        'idle' => 15000,
        'idle_after' => 300000,
    ],

    // A conversation is live — worth polling with the panel closed — while it
    // is not closed and had activity within this many hours. Replies to any
    // other still arrive with the next page load.
    'live_hours' => 24,

    'max_message_length' => 2000,

    // Files both ways: the visitor attaches (📎, paste, drop), a manager sends
    // a photo or document in the topic. Stored on `disk` (private: served
    // only through signed links), checked by content, not by name. `types`
    // are extensions; Telegram lets the bot fetch files up to 20 MB.
    'attachments' => [
        'enabled' => true,
        'disk' => 'local',
        'max_kb' => 10240,
        'max_files' => 5,
        'types' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'],
    ],

];
