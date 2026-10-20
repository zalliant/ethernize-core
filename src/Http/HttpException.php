<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

use Nyholm\Psr7\Response as Psr7Response;
use RuntimeException;
use Throwable;

/**
 * Erro com status HTTP. A mensagem é destinada ao cliente, portanto nunca
 * deve conter informações internas.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : (new Psr7Response($status))->getReasonPhrase(), $status, $previous);
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    /**
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, '', ['Allow' => implode(', ', $allowed)]);
    }
}
