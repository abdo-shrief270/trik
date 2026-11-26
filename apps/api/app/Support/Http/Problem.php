<?php

declare(strict_types=1);

namespace App\Support\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An API error in RFC 9457 (problem+json) form. Throw it from anywhere in a request:
 *
 *     throw Problem::conflict('number-already-connected', 'This number is already connected.');
 *
 * `code` is the stable, machine-readable part clients switch on; `detail` is for people.
 */
final class Problem extends RuntimeException
{
    public const TYPE_BASE = 'https://trik.chat/problems/';

    /**
     * @param  array<string, mixed>  $extra  extension members, e.g. ['errors' => [...]]
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    /** @param array<string, mixed> $extra */
    public static function make(int $status, string $code, ?string $detail = null, array $extra = []): self
    {
        return new self($status, $code, Response::$statusTexts[$status] ?? 'Error', $detail, $extra);
    }

    public static function badRequest(string $code, string $detail): self
    {
        return self::make(400, $code, $detail);
    }

    public static function forbidden(string $code, string $detail): self
    {
        return self::make(403, $code, $detail);
    }

    public static function notFound(string $code = 'not-found', ?string $detail = null): self
    {
        return self::make(404, $code, $detail);
    }

    public static function conflict(string $code, string $detail): self
    {
        return self::make(409, $code, $detail);
    }

    /** @param array<string, mixed> $extra */
    public static function unprocessable(string $code, string $detail, array $extra = []): self
    {
        return self::make(422, $code, $detail, $extra);
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors, ?string $detail = null): self
    {
        $first = array_values($errors)[0][0] ?? 'The request is invalid.';

        return self::make(422, 'validation-failed', $detail ?? $first, ['errors' => $errors]);
    }

    /** @return array<string, mixed> */
    public function toArray(?string $instance = null, ?string $requestId = null): array
    {
        return array_filter([
            'type' => self::TYPE_BASE.$this->problemCode,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'code' => $this->problemCode,
            'instance' => $instance,
            'request_id' => $requestId,
            ...$this->extra,
        ], fn ($v) => $v !== null);
    }
}
