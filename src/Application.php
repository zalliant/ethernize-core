<?php

declare(strict_types=1);

namespace Ethernize\Core;

use ErrorException;
use Ethernize\Core\Config\Config;
use Ethernize\Core\Config\Environment;
use Ethernize\Core\Container\Container;
use Ethernize\Core\Events\Dispatcher as Events;
use Ethernize\Core\Events\RequestReceived;
use Ethernize\Core\Events\Terminated;
use Ethernize\Core\Http\CurrentRequest;
use Ethernize\Core\Http\Emitter;
use Ethernize\Core\Http\ExceptionHandler;
use Ethernize\Core\Http\Response;
use Ethernize\Core\Http\ServerRequestFactory;
use Ethernize\Core\Manifest\Manifest;
use Ethernize\Core\Manifest\ManifestContributor;
use Ethernize\Core\Manifest\ManifestFile;
use Ethernize\Core\Middleware\Cors;
use Ethernize\Core\Middleware\ErrorMiddleware;
use Ethernize\Core\Middleware\HostGuard;
use Ethernize\Core\Middleware\MiddlewareResolver;
use Ethernize\Core\Middleware\Pipeline;
use Ethernize\Core\Middleware\SecurityHeaders;
use Ethernize\Core\Middleware\StartSession;
use Ethernize\Core\Middleware\TrustedProxies;
use Ethernize\Core\Routing\Dispatcher;
use Ethernize\Core\Routing\InvalidRouteException;
use Ethernize\Core\Routing\RouteResolver;
use Ethernize\Core\Routing\Router;
use Ethernize\Core\Routing\TrailingSlash;
use Ethernize\Core\Routing\UrlGenerator;
use Ethernize\Core\Routing\Web;
use Ethernize\Core\Session\SessionManagerInterface;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Kernel da aplicação: concentra container, configuração, rotas, eventos e
 * o ciclo de vida da requisição.
 *
 * Configuração lida:
 *   app.env              nome do ambiente (padrão: production)
 *   app.debug            exibe detalhes de erro; só vale em ambientes de desenvolvimento
 *   app.url              URL base pública, usada para gerar URLs absolutas
 *   app.autowire         namespaces liberados para autowiring
 *   app.providers        classes ServiceProvider
 *   app.middleware       middlewares globais, executados antes do roteamento
 *   app.routes           arquivos de rotas, relativos ao diretório base
 *   app.trailing_slash   ignore (padrão), redirect ou strict
 *   http.trusted_hosts   hosts aceitos no cabeçalho Host (vazio: apenas valida a sintaxe)
 *   http.trusted_proxies IPs/CIDRs de proxies cujos X-Forwarded-* são considerados
 *   cors.*               veja Middleware\Cors::fromConfig (desligado sem cors.origins)
 */
final class Application implements RequestHandlerInterface
{
    public const VERSION = '0.1.0';

    /** Ambientes em que app.debug é respeitado. Qualquer outro ignora o debug. */
    private const DEBUG_ENVIRONMENTS = ['local', 'development', 'testing'];

    private readonly string $basePath;
    private readonly Container $container;
    private readonly Router $router;
    private readonly Events $events;
    private readonly ExceptionHandler $exceptions;
    private bool $booted = false;
    private readonly RouteResolver $routes;
    /** @var array<string, ManifestContributor> */
    private array $contributors = [];

    /** @var list<MiddlewareInterface|string> */
    private array $moduleMiddleware = [];
    private ?Throwable $bootError = null;
    private ?RequestHandlerInterface $kernel = null;

    /**
     * Com um Manifest, as rotas e as fábricas de serviços vêm dele e os
     * arquivos de rotas não são executados. Sem Manifest, tudo é descoberto
     * no boot (modo convencional, o padrão em desenvolvimento).
     */
    public function __construct(
        string $basePath,
        private readonly Config $config = new Config(),
        private readonly ?Manifest $manifest = null,
    ) {
        $resolved = realpath($basePath);

        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException(sprintf('O diretório base "%s" não existe.', $basePath));
        }

        $this->basePath = $resolved;
        $this->container = new Container();
        $this->router = new Router();
        $this->routes = $manifest?->routes() ?? $this->router;
        $this->events = new Events();
        $this->exceptions = new ExceptionHandler($this->isDebug());
        $this->exceptions->useEvents($this->events);
    }

    /**
     * Cria a aplicação.
     *
     * Por padrão lê .env e config/ do diretório base (modo convencional).
     * Se a variável de ambiente do processo APP_MANIFEST valer "required", usa
     * exclusivamente o Manifest gerado antes da publicação: nada é descoberto
     * nem recompilado durante a requisição. Manifest ausente, corrompido ou
     * incompatível resulta em uma resposta 500 genérica, com o motivo no log.
     * A variável precisa vir do processo (por exemplo SetEnv no Apache), nunca
     * do arquivo .env, que também deixa de ser lido nesse modo.
     */
    public static function create(string $basePath): self
    {
        if (self::processEnvironment('APP_MANIFEST') === 'required') {
            try {
                $manifest = ManifestFile::load(
                    ManifestFile::path($basePath),
                    self::processEnvironment('APP_ENV') ?? 'production',
                );

                return new self($basePath, $manifest->config(), $manifest);
            } catch (Throwable $exception) {
                $failed = new self($basePath, new Config(['app' => ['env' => 'production']]));
                $failed->bootError = $exception;

                return $failed;
            }
        }

        $environment = Environment::load($basePath);
        $config = Config::fromDirectory(rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'config', $environment);

        return new self($basePath, $config);
    }

    public function basePath(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : $this->basePath . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function events(): Events
    {
        return $this->events;
    }

    public function environment(): string
    {
        $name = $this->config->get('app.env', 'production');

        return is_string($name) ? $name : 'production';
    }

    public function isDebug(): bool
    {
        return $this->config->get('app.debug', false) === true
            && in_array($this->environment(), self::DEBUG_ENVIRONMENTS, true);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * O Manifest em uso, ou null no modo convencional.
     */
    public function manifest(): ?Manifest
    {
        return $this->manifest;
    }

    /**
     * Adiciona um middleware global em nome de um módulo (por exemplo, o Pulse
     * emite o cookie de contexto). Deve ser chamado durante o boot. Roda antes
     * dos middlewares de app.middleware, e depois do tratamento de erros, de
     * HostGuard, TrustedProxies, CORS e sessão.
     */
    public function addMiddleware(MiddlewareInterface|string $middleware): void
    {
        if ($this->booted) {
            throw new \LogicException('Module middleware must be added during boot.');
        }

        $this->moduleMiddleware[] = $middleware;
    }

    /**
     * Registra um módulo que contribui com dados e artefatos para o build do
     * Manifest. Deve ser chamado durante o boot (por exemplo, em um provider).
     */
    public function addManifestContributor(ManifestContributor $contributor): void
    {
        $this->contributors[$contributor->key()] = $contributor;
    }

    /**
     * @return list<ManifestContributor>
     */
    public function manifestContributors(): array
    {
        return array_values($this->contributors);
    }

    /**
     * Indica se a aplicação usa um Manifest em vez de descobrir tudo no boot.
     */
    public function isCompiled(): bool
    {
        return $this->manifest !== null;
    }

    /**
     * Prepara serviços, providers, rotas e o pipeline. É idempotente. Se o
     * boot falhar, a mesma falha é relançada nas chamadas seguintes, sem
     * tentar de novo (o que duplicaria rotas e providers).
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        if ($this->bootError !== null) {
            throw $this->bootError;
        }

        try {
            $this->performBoot();
        } catch (Throwable $exception) {
            $this->bootError = $exception;

            throw $exception;
        }

        $this->events->lock();
        $this->booted = true;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Defesa em processos persistentes: nada com escopo de requisição sobrevive à anterior.
        $this->container->resetScoped();

        try {
            $this->boot();
            $this->events->dispatch(new RequestReceived($request));
        } catch (Throwable $exception) {
            return $this->exceptions->render($exception, $request);
        }

        return $this->kernel->handle($request);
    }

    /**
     * Libera o que depende do fim da requisição: dispara Terminated para que
     * os módulos descartem seus recursos. Erros dos ouvintes são apenas registrados.
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        try {
            $this->events->dispatch(new Terminated($request, $response));
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        } finally {
            $this->container->resetScoped();
        }
    }

    /**
     * Atende a requisição atual do PHP, envia a resposta e encerra o ciclo.
     */
    public function run(): void
    {
        ini_set('display_errors', $this->isDebug() ? '1' : '0');
        ini_set('default_mimetype', '');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            try {
                $request = ServerRequestFactory::fromGlobals();
            } catch (Throwable $exception) {
                // Requisição que nem chega a ser interpretada (URI ou cabeçalhos inválidos).
                Emitter::emit(Response::text('Bad Request', 400));

                return;
            }

            $response = $this->handle($request);
            Emitter::emit($response, $request->getMethod());

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->terminate($request, $response);
        } finally {
            restore_error_handler();
        }
    }

    private function performBoot(): void
    {
        $this->registerCoreServices();

        foreach ($this->stringList('app.autowire') as $namespace) {
            $this->container->allow($namespace);
        }

        $providers = [];

        foreach ($this->stringList('app.providers') as $class) {
            if (!is_subclass_of($class, ServiceProvider::class)) {
                throw new InvalidArgumentException(sprintf('"%s" não implementa ServiceProvider.', $class));
            }

            $provider = new $class();
            $provider->register($this->container, $this);
            $providers[] = $provider;
        }

        foreach ($providers as $provider) {
            if ($provider instanceof BootableProvider) {
                $provider->boot($this);
            }
        }

        if ($this->manifest !== null) {
            $this->container->useCompiled($this->manifest->services());
        } else {
            foreach ($this->stringList('app.routes') as $file) {
                $this->loadRoutes($file);
            }

            $this->router->routes();
        }

        if ($this->container->has(LoggerInterface::class)) {
            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);
            $this->exceptions->useLogger($logger);
        }

        $this->kernel = $this->buildKernel();
    }

    private function buildKernel(): RequestHandlerInterface
    {
        $resolver = new MiddlewareResolver($this->container);
        $trailing = TrailingSlash::tryFrom((string) $this->config->get('app.trailing_slash', 'ignore'))
            ?? throw new InvalidArgumentException('app.trailing_slash deve ser ignore, redirect ou strict.');

        // O tratamento de erros aparece duas vezes: o interno garante que os
        // middlewares globais sempre recebam uma resposta (inclusive 404, 405
        // e 500 do roteamento); o externo cobre falhas dos próprios middlewares.
        $errors = new ErrorMiddleware($this->exceptions);

        $stack = [
            new SecurityHeaders(),
            $errors,
            new HostGuard($this->listOption('http.trusted_hosts')),
            new TrustedProxies($this->listOption('http.trusted_proxies')),
        ];

        $cors = $this->config->get('cors', []);

        if (is_array($cors) && ($cors['origins'] ?? []) !== []) {
            $stack[] = Cors::fromConfig($cors);
        }

        if ($this->container->has(SessionManagerInterface::class)) {
            /** @var SessionManagerInterface $manager */
            $manager = $this->container->get(SessionManagerInterface::class);
            $stack[] = new StartSession($manager);
        }

        array_push($stack, ...$resolver->resolveAll([...$this->moduleMiddleware, ...$this->middlewareList('app.middleware')]));
        $stack[] = $errors;

        return new Pipeline(
            $stack,
            new Dispatcher($this->routes, $this->container, $resolver, $this->events, $trailing),
        );
    }

    private function registerCoreServices(): void
    {
        $factory = new Psr17Factory();
        $url = $this->config->get('app.url');

        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(UrlGenerator::class, new UrlGenerator($this->routes, is_string($url) ? $url : null));
        $this->container->scoped(CurrentRequest::class, static fn (): CurrentRequest => new CurrentRequest());
        $this->container->instance(Events::class, $this->events);
        $this->container->instance(EventDispatcherInterface::class, $this->events);
        $this->container->instance(ExceptionHandler::class, $this->exceptions);
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
    }

    /**
     * Carrega um arquivo de rotas, desde que ele esteja dentro do diretório base.
     */
    private function loadRoutes(string $relative): void
    {
        $path = realpath($this->basePath . DIRECTORY_SEPARATOR . $relative);

        if (
            $path === false
            || !is_file($path)
            || !str_starts_with($path, $this->basePath . DIRECTORY_SEPARATOR)
            || !str_ends_with($path, '.php')
        ) {
            throw new InvalidArgumentException(sprintf('Arquivo de rotas inválido: "%s".', $relative));
        }

        try {
            Web::within($this->router, static function () use ($path): void {
                require $path;
            });
        } catch (InvalidRouteException $exception) {
            // Say which line of the routes file made the mistake, not only which rule it broke.
            $line = null;

            foreach ($exception->getTrace() as $frame) {
                if (isset($frame['file']) && realpath($frame['file']) === $path) {
                    $line = $frame['line'] ?? null;
                    break;
                }
            }

            throw new InvalidRouteException($exception->getMessage() . ($line !== null ? sprintf(' (%s:%d)', $relative, $line) : sprintf(' (%s)', $relative)), 0, $exception);
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = $this->config->get($key, []);

        if (!is_array($values)) {
            throw new InvalidArgumentException(sprintf('A configuração "%s" deve ser uma lista.', $key));
        }

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('A configuração "%s" deve conter apenas textos.', $key));
            }
        }

        return array_values($values);
    }

    private static function processEnvironment(string $key): ?string
    {
        $value = getenv($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $_SERVER[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $key): array
    {
        return $this->stringList($key);
    }

    /**
     * @return list<MiddlewareInterface|string>
     */
    private function middlewareList(string $key): array
    {
        $values = $this->config->get($key, []);

        return is_array($values) ? array_values($values) : [];
    }
}
