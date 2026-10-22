<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

/**
 * The code of the generators of Core: a controller (with its page template) and a service. They are plain
 * functions of their inputs, shared by the `make:*` commands and by `make:resource`.
 */
final class Stubs
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $namespace folders below src/ ("Controllers", then sub-folders)
     * @param string|null  $model     the model class (fully qualified) the index action lists, when there is one
     * @param bool         $api       a JSON controller without a template
     * @param string       $url       the path segment the route example uses
     */
    public static function controller(Project $project, array $namespace, string $class, string $page, string $title, string $url, ?string $model, bool $api): string
    {
        $ns = $project->namespaceFor($namespace);
        $imports = $api ? ['Ethernize\Core\Http\Response', 'Psr\Http\Message\ResponseInterface'] : ['Ethernize\Core\View\View'];

        if ($model !== null) {
            $imports[] = $model;
        }

        sort($imports);
        $use = implode("\n", array_map(static fn (string $import): string => 'use ' . $import . ';', $imports));
        $short = $model === null ? null : substr($model, (int) strrpos($model, '\\') + 1);

        $list = $short === null
            ? '[]'
            : sprintf('array_map(static fn (%s $item): array => $item->toArray(), %s::query()->orderBy(\'id\')->limit(50)->get())', $short, $short);

        $body = $api
            ? <<<PHP
                    public function index(): ResponseInterface
                    {
                        return Response::json(['items' => {$list}]);
                    }
                PHP
            : <<<PHP
                    public function index(): View
                    {
                        return new View('{$page}', ['title' => '{$title}', 'items' => {$list}]);
                    }
                PHP;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            {$use}

            /**
             * Routes are not registered for you. Add one to routes/web.php when this is ready to be reached:
             *
             *     Web::on('/{$url}')->get([{$class}::class, 'index'])->name('{$url}.index');
             */
            final class {$class}
            {
            {$body}
            }

            PHP;
    }

    /**
     * The page template of a controller's index action.
     *
     * @param string|null $label the field to show for each item, or null for a plain list of ids
     */
    public static function page(string $title, bool $withItems, ?string $label): string
    {
        $items = $withItems ? "    <ul>\n@each (item in items, key: item.id)\n        <li>{{ item." . ($label ?? 'id') . " }}</li>\n@end\n    </ul>\n" : '';

        return <<<WEAVE
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{{ title }}</title>
            </head>
            <body>
                <h1>{{ title }}</h1>
            {$items}</body>
            </html>

            WEAVE;
    }

    /**
     * @param list<string> $namespace folders below src/
     * @param string|null  $model     the model class (fully qualified) the service works with, when there is one
     */
    public static function service(Project $project, array $namespace, string $class, ?string $model): string
    {
        $ns = $project->namespaceFor($namespace);

        if ($model === null) {
            return <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$ns};

                /**
                 * Business rules live here, away from controllers and components, so both can use them and tests
                 * can reach them without HTTP. The container builds this class and fills its constructor.
                 */
                final class {$class}
                {
                }

                PHP;
        }

        $short = substr($model, (int) strrpos($model, '\\') + 1);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use {$model};

            /**
             * Business rules for {$short}, away from controllers and components so both can use them and tests can
             * reach them without HTTP.
             */
            final class {$class}
            {
                /**
                 * @param array<string, mixed> \$attributes only the properties the model marks #[Fillable] are accepted
                 */
                public function create(array \$attributes): {$short}
                {
                    return {$short}::transaction(static fn (): {$short} => {$short}::create(\$attributes));
                }

                public function find(int \$id): {$short}
                {
                    return {$short}::findOrFail(\$id);
                }
            }

            PHP;
    }
}
