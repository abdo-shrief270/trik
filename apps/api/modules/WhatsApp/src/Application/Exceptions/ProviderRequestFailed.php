<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Exceptions;

use RuntimeException;
use Throwable;

final class ProviderRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array{message: string, retryable: bool, http_status: int|null, code: string|null} */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'retryable' => $this->retryable,
            'http_status' => $this->httpStatus,
            'code' => $this->providerCode,
        ];
    }
}
