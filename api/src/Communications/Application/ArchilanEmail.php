<?php

declare(strict_types=1);

namespace App\Communications\Application;

abstract class ArchilanEmail
{
    abstract public function to(): string;

    abstract public function toName(): ?string;

    abstract public function subject(): string;

    abstract public function textBody(): string;

    public function htmlBody(): ?string
    {
        return null;
    }
}
