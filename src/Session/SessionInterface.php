<?php

declare(strict_types=1);

namespace Ethernize\Core\Session;

/**
 * Sessão de uma requisição. O Shield usa este contrato para CSRF e
 * autenticação sem conhecer o mecanismo de armazenamento. Valores devem ser
 * apenas null, escalares e arrays desses tipos, para que o armazenamento nunca
 * precise instanciar classes ao ler uma sessão.
 */
interface SessionInterface
{
    public function id(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Troca o identificador mantendo os dados (use após login). O identificador antigo deixa de
     * valer imediatamente: é isso que impede a fixação de sessão.
     */
    public function regenerate(): void;

    /**
     * Descarta dados e identificador (use no logout). O que for gravado depois pertence a uma
     * sessão nova; o gerenciador remove o cookie se nada restar.
     */
    public function invalidate(): void;
}
