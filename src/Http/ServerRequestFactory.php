<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

final class ServerRequestFactory
{
    /**
     * Cria a requisição a partir das superglobais. Quando a aplicação roda em
     * um subdiretório (como /ethernize/apps/aether/public no XAMPP), o prefixo
     * do script é removido do caminho para que as rotas sejam independentes
     * do local de instalação.
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        // Only a script (index.php) tells where the application is installed. PHP's built-in server sets
        // SCRIPT_NAME to the URL itself for addresses that look like files (/_pulse/runtime.js), and that
        // is not an installation folder.
        $base = is_string($script) && str_ends_with($script, '.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';

        return self::withoutBase($request, $base);
    }

    public static function withoutBase(ServerRequestInterface $request, string $base): ServerRequestInterface
    {
        if ($base === '' || $base === '.') {
            return $request;
        }

        $path = $request->getUri()->getPath();

        if ($path === $base) {
            $path = '/';
        } elseif (str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        } else {
            return $request;
        }

        return $request
            ->withUri($request->getUri()->withPath($path))
            ->withAttribute(RequestAttributes::BASE_PATH, $base);
    }
}
