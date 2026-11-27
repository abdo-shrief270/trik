<?php

declare(strict_types=1);

namespace Modules\Waitlist\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;
use Modules\Waitlist\Infrastructure\WaitlistLinks;
use Symfony\Component\Mime\Email;

/**
 * The double opt-in email. Carries only the entry id: the worker reads the entry when it sends, and
 * skips the email if the person has confirmed, left the list or been pruned since.
 */
final class WaitlistConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $entryId)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->entry()?->status === EntryStatus::Pending;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry() ?? throw new \RuntimeException("Waitlist entry {$this->entryId} no longer exists.");

        $links = app(WaitlistLinks::class);
        $copy = WaitlistCopy::confirm($entry->locale);
        $confirm = $links->confirm($entry);
        $unsubscribe = $links->unsubscribe($entry);
        $replace = [':name' => $entry->name, ':store' => $entry->store_name];
        $text = array_map(fn (string $line): string => strtr($line, $replace), $copy);

        return (new MailMessage)
            ->subject($copy['subject'])
            ->view(['waitlist::confirm', 'waitlist::confirm-text'], [
                'copy' => $text,
                'locale' => $entry->locale,
                'confirmUrl' => $confirm,
                'unsubscribeUrl' => $unsubscribe,
            ])
            // RFC 2369 / 8058: Gmail and Yahoo show an unsubscribe button and send a one-click POST.
            ->withSymfonyMessage(function (Email $message) use ($unsubscribe): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$unsubscribe}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    private function entry(): ?WaitlistEntry
    {
        return WaitlistEntry::query()->find($this->entryId);
    }
}
