<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Request;
use App\Response;
use App\Service\JobRunner;
use App\Service\ProductDescriptionService;
use App\Service\ReportService;
use App\Integration\Integrations;
use App\View;

/** Admin / marketing információs felület. Minden végpont belépéshez kötött, és minden módosító kérés CSRF-tokent vár. */
final class AdminController
{
    public function loginForm(): Response
    {
        if (Auth::check()) {
            return Response::redirect('/admin');
        }
        return Response::html(View::page('admin/login', ['error' => null], 'Belépés', 'admin/layout'));
    }

    public function login(Request $r): Response
    {
        Auth::start();
        $error = null;
        if (!Auth::verifyCsrf($r)) {
            $error = 'Lejárt az űrlap, kérjük próbálja újra.';
        } elseif (!Auth::attempt((string) ($r->post['username'] ?? ''), (string) ($r->post['password'] ?? ''))) {
            $error = 'Hibás felhasználónév vagy jelszó.';   // szándékosan nem mondjuk meg, melyik
        } else {
            return Response::redirect('/admin');
        }
        return Response::html(View::page('admin/login', ['error' => $error], 'Belépés', 'admin/layout'), 401);
    }

    public function logout(Request $r): Response
    {
        if (Auth::verifyCsrf($r)) {
            Auth::logout();
        }
        return Response::redirect('/admin/login');
    }

    public function dashboard(): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/admin/login');
        }
        return Response::html(View::page('admin/dashboard', ['jobs' => JobRunner::names()], 'Áttekintés', 'admin/layout'));
    }

    // ---------------------------------------------------------------- JSON végpontok (a jQuery hívja)

    public function summary(Request $r): Response
    {
        if (!Auth::check()) {
            return $this->unauthorized();
        }
        return Response::json((new ReportService())->dashboard((int) ($r->q('days', '30'))));
    }

    public function runJob(Request $r, array $params): Response
    {
        if (!Auth::check()) {
            return $this->unauthorized();
        }
        if (!Auth::verifyCsrf($r)) {
            return Response::json(['error' => 'Érvénytelen CSRF-token'], 403);
        }
        // A job neve a JobRunner ismert nevei közül való kell legyen; bármi más hibát kap.
        if (!in_array($params['name'], JobRunner::names(), true)) {
            return Response::json(['error' => 'Ismeretlen feladat'], 404);
        }
        return Response::json(JobRunner::run($params['name']));
    }

    public function reviewAi(Request $r, array $params): Response
    {
        if (!Auth::check()) {
            return $this->unauthorized();
        }
        if (!Auth::verifyCsrf($r)) {
            return Response::json(['error' => 'Érvénytelen CSRF-token'], 403);
        }
        $approve = $params['action'] === 'approve';
        if (!$approve && $params['action'] !== 'reject') {
            return Response::json(['error' => 'Ismeretlen művelet'], 404);
        }
        $ok = (new ProductDescriptionService(Integrations::ai()))->review((int) $params['id'], $approve);
        return Response::json(['ok' => $ok], $ok ? 200 : 409);
    }

    private function unauthorized(): Response
    {
        return Response::json(['error' => 'Belépés szükséges'], 401);
    }
}
