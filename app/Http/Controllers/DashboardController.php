<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $connection = DB::connection();
        $timezone = config('app.business_timezone');

        return Inertia::render('Dashboard', [
            'status' => [
                'laravel' => Application::VERSION,
                'php' => PHP_VERSION,
                'database' => [
                    'driver' => $connection->getDriverName(),
                    'version' => $connection->selectOne("select current_setting('server_version') as v")->v,
                    'name' => $connection->getDatabaseName(),
                    'migrations' => DB::table('migrations')->count(),
                    'users' => User::count(),
                ],
                'timezone' => $timezone,
                'serverTime' => now($timezone)->format('d.m.Y H:i:s'),
            ],
        ]);
    }
}
