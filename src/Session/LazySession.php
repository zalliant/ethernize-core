<?php

declare(strict_types=1);

namespace Ethernize\Core\Session;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Sessão aberta somente no primeiro uso. Requisições que nunca tocam a
 * sessão não leem o armazenamento nem emitem cookies.
 */
final class LazySession implements SessionInterface
{
    private ?SessionInterface $session = null;

    public function __construct(
        private readonly SessionManagerInterface $manager,
        private readonly ServerRequestInterface $request,
    ) {
    }

    public function isStarted(): bool
    {
        return $this->session !== null;
    }

    /**
     * A sessão real, ou null se ela nunca foi usada nesta requisição.
     */
    public function started(): ?SessionInterface
    {
        return $this->session;
    }

    public function id(): string
    {
        return $this->session()->id();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session()->get($key, $default);
    }

    public function has(string $key): bool
    {
        return $this->session()->has($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session()->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->session()->remove($key);
    }

    public function regenerate(): void
    {
        $this->session()->regenerate();
    }

    public function invalidate(): void
    {
        $this->session()->invalidate();
    }

    private function session(): SessionInterface
    {
        return $this->session ??= $this->manager->start($this->request);
    }
}
