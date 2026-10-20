<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

use Ethernize\Core\Events\ExceptionOccurred;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ponto único de conversão de exceções em respostas HTTP.
 *
 * Fora do modo debug, a resposta contém apenas o status e a mensagem padrão
 * (ou a mensagem de uma HttpException). Detalhes internos vão somente para o log.
 */
final class ExceptionHandler
{
    private ?EventDispatcherInterface $events = null;

    public function __construct(
        private readonly bool $debug,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function useLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Nunca lança exceção: se a própria montagem da resposta falhar (por
     * exemplo, mensagem com UTF-8 inválido), devolve um 500 mínimo.
     */
    public function render(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        try {
            $status = $exception instanceof HttpException ? $exception->status : 500;
            $headers = $exception instanceof HttpException ? $exception->headers : [];

            if ($status >= 500) {
                $this->report($exception);
            }

            $this->events?->dispatch(new ExceptionOccurred($request, $exception));

            $message = $exception instanceof HttpException || $this->debug
                ? $exception->getMessage()
                : 'Internal Server Error';

            $details = $this->debug && $status >= 500 ? $this->details($exception) : null;

            return $this->wantsJson($request)
                ? Response::json($this->jsonPayload($status, preg_match('//u', $message) === 1 ? $message : '[mensagem com bytes inválidos]', $details), $status, $headers)
                : Response::html($this->htmlPage($status, $message, $details), $status, $headers);
        } catch (Throwable) {
            return Response::text('Internal Server Error', 500);
        }
    }

    public function useEvents(?EventDispatcherInterface $events): void
    {
        $this->events = $events;
    }

    /**
     * Registra a exceção. Falhas do próprio log nunca interrompem a resposta.
     */
    public function report(Throwable $exception): void
    {
        try {
            if ($this->logger !== null) {
                $this->logger->error($exception->getMessage(), ['exception' => $exception]);

                return;
            }

            error_log(sprintf(
                '[ethernize] %s: %s em %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));
        } catch (Throwable) {
            // Sem alternativa: o log é o último recurso.
        }
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'json');
    }

    /**
     * @return array{class: class-string, file: string, line: int, trace: list<string>}
     */
    private function details(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ];
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>
     */
    private function jsonPayload(int $status, string $message, ?array $details): array
    {
        $error = ['status' => $status, 'message' => $message];

        if ($details !== null) {
            $error['debug'] = $details;
        }

        return ['error' => $error];
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function htmlPage(int $status, string $message, ?array $details): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = sprintf('<h1>%d</h1><p>%s</p>', $status, $escape($message));

        if ($details !== null) {
            $body .= sprintf(
                '<h2>%s</h2><p>%s:%d</p><pre>%s</pre>',
                $escape($details['class']),
                $escape($details['file']),
                $details['line'],
                $escape(implode("\n", $details['trace'])),
            );
        }

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $status . '</title></head><body>' . $body . '</body></html>';
    }
}
