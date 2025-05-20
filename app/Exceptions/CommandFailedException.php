<?php

namespace App\Exceptions;

class CommandFailedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public string $command,
        public array $errorOutput,
    ) {
        parent::__construct($message);
    }

    public function context(): array
    {
        return [
            'command' => $this->command,
            'errorOutput' => $this->errorOutput,
        ];
    }
}
