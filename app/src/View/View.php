<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Konto;
use App\Http\HttpMethod;

/**
 * Minimal template renderer: plain PHP templates inside the one layout.
 * Taken over from Vereinsbelege. Data keys become local variables in the
 * template (EXTR_SKIP: the reserved names $file and $data cannot be
 * overridden).
 *
 * There is exactly one layout file (app/views/layout.php). What differs
 * between the public pages, the user area and the admin area is chrome, and
 * all of that hangs off App\View\Area.
 */
final class View
{
    /** Set once per request by the Kernel, for aria-current in the navigation. */
    private string $currentPath = '/';

    /**
     * Who is logged in, and the token their forms need. Set once per
     * request by App\Http\LoginGuard: it belongs to the chrome, not to a
     * page, and no controller should have to remember to pass it.
     */
    private ?Konto $konto = null;

    private ?string $csrfToken = null;

    /** @var (\Closure(HttpMethod, string): bool)|null */
    private ?\Closure $routeVorhanden = null;

    /** @var (\Closure(): ?Flash)|null */
    private ?\Closure $flashQuelle = null;

    /**
     * @param list<string> $stylesheets URLs below the docroot, in order:
     *        the design system first, then the parts in public/css/teile/
     *        (App\View\Stylesheets)
     */
    public function __construct(
        private readonly string $viewsDir,
        private readonly string $version,
        private readonly array $stylesheets = ['/css/app.css'],
        private readonly string $appName = 'Mitgliederverwaltung',
    ) {
    }

    public function setCurrentPath(string $path): void
    {
        $this->currentPath = $path;
    }

    public function setAnmeldung(?Konto $konto, ?string $csrfToken): void
    {
        $this->konto = $konto;
        $this->csrfToken = $csrfToken;
    }

    /** The logged-in account of this request, as the guard set it. */
    public function konto(): ?Konto
    {
        return $this->konto;
    }

    /**
     * Tells the navigation which entries have a route already - the router
     * of this request, set once in the bootstrap.
     *
     * @param \Closure(HttpMethod, string): bool $routeVorhanden
     */
    public function setRouteVorhanden(\Closure $routeVorhanden): void
    {
        $this->routeVorhanden = $routeVorhanden;
    }

    /**
     * Where full pages behind the login take the flash message of the
     * previous request from (the session) - pulled only when such a page is
     * rendered, so an htmx fragment or a JSON answer never swallows it.
     *
     * @param \Closure(): ?Flash $flashQuelle
     */
    public function setFlashQuelle(\Closure $flashQuelle): void
    {
        $this->flashQuelle = $flashQuelle;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], Area $bereich = Area::Oeffentlich): string
    {
        // A page that renders nothing but static markup still gets the token
        // of the session it runs in - the logout button lives in the chrome,
        // and it is a form like any other.
        $data['csrf'] ??= $this->csrfToken;
        if ($bereich !== Area::Oeffentlich && !array_key_exists('flash', $data) && $this->flashQuelle !== null) {
            $data['flash'] = ($this->flashQuelle)();
        }

        $content = $this->renderFile($this->viewsDir . '/' . $template . '.php', $data);

        return $this->renderFile(
            $this->viewsDir . '/layout.php',
            [
                ...$data,
                // After the spread, so a template cannot shadow the frame
                // it renders into.
                'konto' => $this->konto,
                'content' => $content,
                'bereich' => $bereich,
                'pfad' => $this->currentPath,
                'version' => $this->version,
                'appName' => $this->appName,
                'stylesheets' => $this->stylesheets,
                'routeVorhanden' => $this->routeVorhanden ?? static fn(HttpMethod $methode, string $pfad): bool => false,
                'partialsDir' => $this->viewsDir . '/partials',
            ],
        );
    }

    /**
     * A template without the layout - the answer to an htmx request that
     * swaps one part of a page. Gets the CSRF token like a full page.
     *
     * @param array<string, mixed> $data
     */
    public function fragment(string $template, array $data = []): string
    {
        $data['csrf'] ??= $this->csrfToken;

        return $this->renderFile($this->viewsDir . '/' . $template . '.php', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
