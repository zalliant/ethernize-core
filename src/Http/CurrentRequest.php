<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The request as the route handler received it: after every middleware had the chance to enrich it
 * (session, authentication, route parameters). PSR-7 requests are immutable, so a module that captured
 * the request in its own middleware would otherwise keep a copy without what the modules after it added.
 * Registered as a scoped service, discarded at the end of every request.
 */
final class CurrentRequest
{
    private ?ServerRequestInterface $request = null;

    public function set(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function get(): ?ServerRequestInterface
    {
        return $this->request;
    }
}
