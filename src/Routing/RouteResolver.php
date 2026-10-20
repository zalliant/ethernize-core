<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Ethernize\Core\Http\HttpException;

/**
 * Resolve método e caminho em uma rota. É implementada pelo Router
 * (registro e correspondência em tempo de execução) e pelo CompiledRouter
 * (tabelas geradas pelo Manifest). As duas precisam responder igual.
 */
interface RouteResolver
{
    /**
     * @throws HttpException 404 quando nenhum caminho corresponde, 405 quando o caminho existe para outros métodos
     */
    public function match(string $method, string $path): RouteMatch;

    /**
     * @throws \InvalidArgumentException quando o nome não existe
     */
    public function named(string $name): Route;

    /**
     * @return list<Route>
     */
    public function routes(): array;
}
