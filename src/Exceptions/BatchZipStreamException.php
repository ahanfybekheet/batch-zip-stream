<?php

declare(strict_types=1);

namespace BatchZipStream\Exceptions;

/**
 * Base exception for all Batch ZIP Stream errors.
 */
class BatchZipStreamException extends \Exception
{
    protected string $context = '';

    public function setContext(string $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function getFullMessage(): string
    {
        return $this->context
            ? sprintf('[%s] %s', $this->context, $this->getMessage())
            : $this->getMessage();
    }
}
