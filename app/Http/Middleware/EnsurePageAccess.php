<?php

namespace App\Http\Middleware;

use App\Support\Erp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side page access: `page:expense_cats` or, without a parameter, the {page} route segment.
 * Hiding a menu item is not a permission check (ТЗ AUTH-01) — every page request passes through here.
 */
class EnsurePageAccess
{
    public function handle(Request $request, Closure $next, ?string $page = null): Response
    {
        $page ??= (string) $request->route('page');
        abort_unless(Erp::pageExists($page) && Erp::canAccess($request->user(), $page), 403);
        $request->attributes->set('erp_page', $page);

        return $next($request);
    }
}
