<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — Offline</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            padding: 1rem;
            text-align: center;
        }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 0.5rem; }
        p { margin: 0; color: #64748b; font-size: 0.9375rem; }
    </style>
</head>
<body>
    <div>
        <h1>You're offline</h1>
        <p>Check your connection and try again.</p>
    </div>
</body>
</html>
