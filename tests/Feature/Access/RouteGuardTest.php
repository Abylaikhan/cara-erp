<?php

namespace Tests\Feature\Access;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route of the application, today's and tomorrow's (ТЗ AUTH-01): a signed-in user is required except on the public
 * routes; every signed-in route checks the page of the role except the home redirect and the logout; every route that
 * changes something goes through the web group (session, CSRF). A guest is sent to /login from any route.
 */
class RouteGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Public by design: the login forms and the health check. */
    private const PUBLIC = ['login', 'login/pin', 'up'];

    /** Framework routes guarded by their own means: files of the local disk only by a signed URL (ServeFile, ReceiveFile). */
    private const FRAMEWORK = ['storage/{path}'];

    /** Signed in, no page of a role: the redirect to the first allowed page and the logout. */
    // export/xlsx serves every page: it checks the page named in the request (ExportController, XlsxExportTest)
    private const ANY_PAGE = ['/', 'logout', 'export/xlsx'];

    /** @return list<RouteDefinition> */
    private function routes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(),
            fn (RouteDefinition $r) => ! in_array($r->uri(), self::FRAMEWORK, true) && ! str_starts_with($r->uri(), '_inertia/')));
    }

    public function test_every_route_but_the_public_ones_requires_a_signed_in_user(): void
    {
        $open = [];
        foreach ($this->routes() as $route) {
            if (! in_array($route->uri(), self::PUBLIC, true) && ! in_array('auth', $route->gatherMiddleware(), true)) {
                $open[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        $this->assertSame([], $open, 'routes without a signed-in user');
        $this->assertGreaterThan(60, count($this->routes()));
    }

    public function test_every_signed_in_route_checks_the_page_of_the_role(): void
    {
        $unchecked = [];
        foreach ($this->routes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('auth', $middleware, true) || in_array($route->uri(), self::ANY_PAGE, true)) {
                continue;
            }
            if (! array_filter($middleware, fn ($m) => is_string($m) && ($m === 'page' || str_starts_with($m, 'page:')))) {
                $unchecked[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        $this->assertSame([], $unchecked, 'signed-in routes without a page check');
    }

    public function test_every_route_that_changes_something_goes_through_the_web_group(): void
    {
        $bare = [];
        foreach ($this->routes() as $route) {
            if (array_diff($route->methods(), ['GET', 'HEAD']) && ! in_array('web', $route->gatherMiddleware(), true)) {
                $bare[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        $this->assertSame([], $bare, 'changing routes without the session and CSRF of the web group');
    }

    public function test_a_guest_is_sent_to_login_from_every_route(): void
    {
        $checked = 0;
        foreach ($this->routes() as $route) {
            if (in_array($route->uri(), self::PUBLIC, true)) {
                continue;
            }
            $uri = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->sample($route->wheres[$m[1]] ?? null), $route->uri()), '/');
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $this->call($method, $uri)->assertRedirect('/login');
            $checked++;
        }
        $this->assertGreaterThan(60, $checked);
    }

    /** A value of a route parameter that its constraint accepts (a number, a code, a month, the first page of a list). */
    private function sample(?string $pattern): string
    {
        $candidates = ['1', 'a', '2031-03', ...($pattern !== null && preg_match('/^[\w|]+$/', $pattern) ? explode('|', $pattern) : [])];
        foreach ($candidates as $value) {
            if ($pattern === null || preg_match('#^(?:'.$pattern.')$#u', $value)) {
                return $value;
            }
        }
        $this->fail("no sample value for the route parameter pattern {$pattern}");
    }
}
