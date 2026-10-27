<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Dynamic client registration only accepts redirect URIs on these origins:
    | Claude (web, desktop and phone apps) and local clients such as Claude Code.
    |
    */

    'redirect_domains' => [
        'https://claude.ai',
        'https://claude.com',
        'http://localhost',
        'http://127.0.0.1',
    ],

];
