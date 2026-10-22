<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

/**
 * An exception that can say more about itself to the developer than it may say to a visitor. The debug
 * page and the command line show the hint and the context; a response outside debug mode never does.
 */
interface Diagnosable
{
    /**
     * What to do about it, in one or two sentences, or null.
     */
    public function hint(): ?string;

    /**
     * Facts that help find the cause (the SQL text, the driver message...). Never shown outside debug mode,
     * and never to a visitor.
     *
     * @return array<string, string>
     */
    public function context(): array;
}
