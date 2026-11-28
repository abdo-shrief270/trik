<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The team invitation email, in Arabic and English (the invitee has no language setting yet). Sent from the queue
 * after the invitation commits. The workspace and inviter names are typed by users and the mail body is Markdown, so
 * they are reduced to plain words: a name must never turn into a link or an image in a mail sent from trik.chat.
 */
final class WorkspaceInvitationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $workspace,
        public readonly string $inviter,
        public readonly string $url,
        public readonly Carbon $expiresAt,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = self::plain($this->workspace);
        $inviter = self::plain($this->inviter);
        $days = max(1, (int) round(now()->diffInDays($this->expiresAt)));

        return (new MailMessage)
            ->subject("{$workspace} · دعوة للانضمام إلى تريك / Join {$workspace} on Trik")
            ->greeting('أهلاً،')
            ->line($inviter !== '' ? "{$inviter} بيدعوك تنضم لفريق {$workspace} على تريك." : "اتدعيت تنضم لفريق {$workspace} على تريك.")
            ->line("الرابط شغال {$days} أيام ولمرة واحدة.")
            ->action('انضم للفريق · Join the team', $this->url)
            ->line('---')
            ->line($inviter !== '' ? "{$inviter} invited you to join {$workspace} on Trik." : "You're invited to join {$workspace} on Trik.")
            ->line("The link works once, for {$days} days.")
            ->line('لو مش متوقع الدعوة دي، تجاهل الإيميل. · If you weren\'t expecting this, ignore this email.');
    }

    /** Letters, digits, spaces and plain punctuation only: no Markdown, HTML or URLs. */
    public static function plain(string $text): string
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}\p{M} .,\-&\'’]/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        // "evil.com" in a name must not become a clickable host in mail clients
        $text = (string) preg_replace('/(\p{L}|\p{N})\.(\p{L})/u', '$1 . $2', $text);

        return trim(mb_substr($text, 0, 80));
    }
}
