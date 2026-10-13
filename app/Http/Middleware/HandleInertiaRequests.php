<?php

namespace App\Http\Middleware;

use App\Support\Erp;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    /** @return array{id: string, title: string, module: string}|null */
    private function currentPage(Request $request): ?array
    {
        $id = $request->attributes->get('erp_page');
        $meta = $id ? (Erp::pages()[$id] ?? null) : null;

        return $meta ? ['id' => $id, 'title' => $meta['title'], 'module' => $meta['module']] : null;
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            // Only what the shell renders; never the model itself (no password, login or e-mail in props).
            'auth' => [
                'user' => $request->user()?->sidebarCard(),
            ],
            'nav' => fn () => $request->user() ? Erp::navigation($request->user()) : [],
            'page' => fn () => $this->currentPage($request),
            'flash' => fn () => ['alert' => $request->session()->get('alert'), 'toast' => $request->session()->get('toast'), 'result' => $request->session()->get('result'), 'dialog' => $request->session()->get('dialog')],
        ];
    }
}
