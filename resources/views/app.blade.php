<!DOCTYPE html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        @vite('resources/js/app.ts')
        <x-inertia::head>
            <title>PromTech ERP</title>
        </x-inertia::head>
    </head>
    <body>
        {{-- Legacy theme switch: same localStorage key, applied before the first paint. --}}
        <script>if (localStorage.getItem('erp_theme') === 'light') document.body.classList.add('light-theme');</script>
        <x-inertia::app />
    </body>
</html>
