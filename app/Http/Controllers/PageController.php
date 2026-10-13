<?php

namespace App\Http\Controllers;

use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    /** «/» opens the first page of the role, like legacy doLogin. */
    public function home(Request $request): RedirectResponse
    {
        $first = Erp::firstPage($request->user());
        abort_if($first === null, 403, 'Для вашей роли нет доступных разделов.');

        return redirect('/'.$first);
    }

    /** Pages that are not migrated yet: an honest notice instead of mock data. */
    public function notMigrated(string $page): Response
    {
        $meta = Erp::pages()[$page];

        return Inertia::render('NotMigrated', ['module' => $meta['module'], 'label' => $meta['label']]);
    }
}
