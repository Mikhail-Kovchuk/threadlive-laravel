<?php

use App\Threadlive\ChatHost;

/*
 * Threadlive — live chat in Telegram threads.
 *
 * Every scalar here can be set from .env. Publish this file
 * (php artisan vendor:publish --tag=threadlive-config) only to change what
 * .env cannot express, such as the middleware list or the host class.
 */

return [

    // Off until the bot and the group are set up. The widget reads this from
    // the bootstrap endpoint and stays hidden while it is false.
    'enabled' => env('THREADLIVE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | A bot of its own, never one the application already uses: the webhook
    | this package sets turns getUpdates off for that bot. chat_id is the
    | forum group (-100…), with Topics on and the bot an admin in it.
    |
    */

    'bot_token' => env('THREADLIVE_BOT_TOKEN'),
    'chat_id' => env('THREADLIVE_GROUP_ID'),
    'webhook_secret' => env('THREADLIVE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | The widget calls {APP_URL}/{route_prefix}/…. When the website and this
    | API are on different origins, add "{route_prefix}/*" to `paths` in
    | config/cors.php, or the browser blocks every request.
    |
    | guard: the guard that knows the signed-in user (null = the default one).
    | host: the class that tells the package about your users; it is
    | published into your app by threadlive:install.
    |
    */

    'route_prefix' => env('THREADLIVE_ROUTE_PREFIX', 'threadlive'),
    'middleware' => ['api'],
    'guard' => env('THREADLIVE_GUARD'),
    'host' => ChatHost::class,
    'allow_guests' => env('THREADLIVE_ALLOW_GUESTS', true),

    /*
    |--------------------------------------------------------------------------
    | Conversation lifecycle
    |--------------------------------------------------------------------------
    |
    | A topic closes after close_after_hours without activity and is deleted
    | from Telegram delete_topic_after_days after that; the conversation stays
    | in the database for retention_days (0 = forever).
    |
    */

    'close_after_hours' => (int) env('THREADLIVE_CLOSE_AFTER_HOURS', 24),
    'delete_topic_after_days' => (int) env('THREADLIVE_DELETE_TOPIC_AFTER_DAYS', 7),
    'retention_days' => (int) env('THREADLIVE_RETENTION_DAYS', 90),
    'waiting_alert_minutes' => (int) env('THREADLIVE_WAITING_ALERT_MINUTES', 10),
    'email_unread_after_minutes' => (int) env('THREADLIVE_EMAIL_UNREAD_AFTER_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | show_agent_name: the visitor sees "Maria · Support" when true, only
    | agent_display_name when false.
    |
    */

    'claim_button' => env('THREADLIVE_CLAIM_BUTTON', true),
    'assign_on_first_reply' => env('THREADLIVE_ASSIGN_ON_FIRST_REPLY', true),
    'show_agent_name' => env('THREADLIVE_SHOW_AGENT_NAME', true),
    'agent_display_name' => env('THREADLIVE_AGENT_DISPLAY_NAME', 'Support'),

    /*
    |--------------------------------------------------------------------------
    | Widget
    |--------------------------------------------------------------------------
    |
    | Served to the widget by the bootstrap endpoint, so changing them never
    | needs a frontend release. Outside hours the widget says when the team
    | is back and asks for an e-mail.
    |
    */

    'hours' => [
        'from' => env('THREADLIVE_HOURS_FROM', '09:00'),
        'to' => env('THREADLIVE_HOURS_TO', '23:00'),
        'tz' => env('THREADLIVE_HOURS_TZ', 'UTC'),
    ],

    'poll_ms' => [
        'open' => (int) env('THREADLIVE_POLL_MS_OPEN', 3000),
        'closed' => (int) env('THREADLIVE_POLL_MS_CLOSED', 30000),
    ],

    'max_message_length' => (int) env('THREADLIVE_MAX_MESSAGE_LENGTH', 2000),

    'attachments' => [
        'enabled' => env('THREADLIVE_ATTACHMENTS', false),
        'disk' => env('THREADLIVE_ATTACHMENTS_DISK', 'local'),
        'max_kb' => (int) env('THREADLIVE_ATTACHMENTS_MAX_KB', 10240),
    ],

    // Where sends to Telegram are queued; null = the application's defaults.
    'queue' => [
        'connection' => env('THREADLIVE_QUEUE_CONNECTION'),
        'name' => env('THREADLIVE_QUEUE'),
    ],

    // Cloudflare Turnstile on a visitor's first message; empty = off.
    'turnstile_secret' => env('THREADLIVE_TURNSTILE_SECRET'),

];
