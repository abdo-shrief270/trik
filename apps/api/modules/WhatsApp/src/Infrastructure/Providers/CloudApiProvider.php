<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Modules\WhatsApp\Application\ConnectedNumber;
use Modules\WhatsApp\Application\Contracts\WhatsAppProvider;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\Exceptions\UnsupportedCapability;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Domain\SendResult;

/** Shared behaviour for providers that speak the Cloud API wire format. */
abstract class CloudApiProvider implements WhatsAppProvider
{
    /**
     * Meta error codes that are worth retrying: throttling, temporary errors, pair rate limits.
     * https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
     */
    private const RETRYABLE_CODES = ['1', '2', '4', '80007', '130429', '131000', '131016', '131048', '131056', '133004'];

    public function __construct(
        protected readonly ConnectedNumber $number,
        protected readonly HttpFactory $http,
    ) {}

    abstract protected function client(): PendingRequest;

    abstract protected function messagesPath(): string;

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function send(PhoneNumber $to, OutgoingMessage $message, ?string $replyToMessageId = null): SendResult
    {
        if ($message->type() === 'interactive') {
            $this->guard(Capability::InteractiveMessages);
        }

        $response = $this->request('post', $this->messagesPath(), CloudApiPayload::message($to, $message, $replyToMessageId));
        $id = data_get($response, 'messages.0.id');

        if (! is_string($id) || $id === '') {
            throw new ProviderRequestFailed('Provider accepted the message but returned no id.', retryable: false);
        }

        return new SendResult($id);
    }

    public function markRead(string $messageId): void
    {
        $this->guard(Capability::MarkRead);

        $this->request('post', $this->messagesPath(), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    protected function guard(Capability $capability): void
    {
        if (! $this->supports($capability)) {
            throw UnsupportedCapability::for($this->provider(), $capability);
        }
    }

    /**
     * @param  'get'|'post'|'delete'  $method
     * @param  array<string, mixed>  $data  JSON body (post) or query (get/delete)
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $data = []): array
    {
        try {
            $response = match ($method) {
                'get' => $this->client()->get($path, $data),
                'post' => $this->client()->post($path, $data),
                'delete' => $this->client()->delete($path.($data === [] ? '' : '?'.http_build_query($data))),
            };
        } catch (ConnectionException $e) {
            throw new ProviderRequestFailed('Could not reach '.$this->provider()->value.': '.$e->getMessage(), retryable: true, previous: $e);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function failure(Response $response): ProviderRequestFailed
    {
        $error = $response->json('error') ?? $response->json('meta') ?? [];
        $error = is_array($error) ? $error : [];
        $code = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null;
        $message = $error['message'] ?? $error['developer_message'] ?? $response->body();

        return new ProviderRequestFailed(
            is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status(),
            retryable: $response->status() === 429 || $response->serverError() || in_array($code, self::RETRYABLE_CODES, true),
            httpStatus: $response->status(),
            providerCode: $code,
        );
    }

    protected function base(PendingRequest $request): PendingRequest
    {
        return $request->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }
}
