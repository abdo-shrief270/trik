<?php

declare(strict_types=1);

namespace Modules\Confirmation\Application;

use App\Support\Http\Problem;
use App\Support\Realtime\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Confirmation\Application\Jobs\AdvanceRun;
use Modules\Confirmation\Domain\ReplyAction;
use Modules\Confirmation\Domain\RunStatus;
use Modules\Confirmation\Infrastructure\Models\ConfirmationRun;
use Modules\Confirmation\Infrastructure\Models\ConfirmationSettings;
use Modules\Orders\Application\ManageOrder;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Application\OrderSnapshot;
use Modules\Orders\Application\OrderTimeline;
use Modules\Orders\Domain\OrderStatus;
use Modules\WhatsApp\Application\NumberDirectory;
use Modules\WhatsApp\Application\SendMessage;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Throwable;

/**
 * Confirms COD orders over WhatsApp (#106):
 *  1. a new COD order starts a run and the template goes out within seconds;
 *  2. a button reply (Confirm / Change / Cancel) settles the order, idempotently;
 *  3. no reply: reminders after the seller's delay, then escalation to a person ("needs a call").
 * Every step goes on the order's timeline. All methods run inside the order's workspace.
 */
final class ConfirmationEngine
{
    public function __construct(
        private readonly OrderReader $orders,
        private readonly ManageOrder $manage,
        private readonly SendMessage $send,
        private readonly NumberDirectory $numbers,
        private readonly Realtime $realtime,
    ) {}

    /** A new order arrived: start a run if the engine is on and the order is COD and unconfirmed. */
    public function start(string $orderId): ?ConfirmationRun
    {
        $settings = ConfirmationSettings::current();
        $order = $this->orders->find($orderId);

        if ($order === null || ! $settings->isActive() || ! $order->isCod() || $order->status !== OrderStatus::New) {
            return null;
        }

        $run = ConfirmationRun::query()->firstOrCreate(['order_id' => $orderId], [
            'status' => RunStatus::Pending,
            'next_action_at' => now(),
        ]);

        if ($run->wasRecentlyCreated) {
            AdvanceRun::dispatch($run->id)->afterCommit();
        }

        return $run;
    }

    /** Does whatever is due on a run: the first message, a reminder, or the escalation. */
    public function advance(string $runId): void
    {
        DB::transaction(function () use ($runId): void {
            /** @var ConfirmationRun|null $run */
            $run = ConfirmationRun::query()->lockForUpdate()->find($runId);

            if ($run === null || ! $run->status->isOpen() || ($run->next_action_at !== null && $run->next_action_at->isFuture())) {
                return; // settled meanwhile, or not due (a duplicate job)
            }

            $order = $this->orders->find($run->order_id);
            if ($order === null || ! $order->status->isPendingConfirmation()) {
                $this->close($run, RunStatus::Resolved, 'settled_elsewhere');

                return;
            }

            $settings = ConfirmationSettings::current();
            $first = $run->status === RunStatus::Pending;

            if ($first || $run->reminders_sent < $settings->max_reminders) {
                $this->sendTemplate($run, $order, $settings, $first);
            } else {
                $this->escalate($run, $order, 'no_reply');
            }
        });
    }

    /** A customer pressed a button on the template. Only the order's own customer counts. */
    public function reply(string $runId, ReplyAction $action, string $fromPhone): void
    {
        DB::transaction(function () use ($runId, $action, $fromPhone): void {
            /** @var ConfirmationRun|null $run */
            $run = ConfirmationRun::query()->lockForUpdate()->find($runId);
            $order = $run === null ? null : $this->orders->find($run->order_id);

            if ($run === null || $order === null) {
                return;
            }

            if ($order->customerPhone !== $fromPhone) {
                Log::warning('confirmation reply from a different number ignored', ['run' => $runId]);

                return;
            }

            if (! $run->status->isOpen() && $run->status !== RunStatus::Escalated) {
                return; // already settled: a second tap does nothing
            }

            OrderTimeline::add($order->id, 'confirmation_reply', null, ['action' => $action->value]);

            if ($action === ReplyAction::Change) {
                $this->escalate($run, $order, 'change_requested');
                $this->acknowledge($run, $order, ConfirmationSettings::current()->change_reply);

                return;
            }

            $to = $action === ReplyAction::Confirm ? OrderStatus::Confirmed : OrderStatus::Cancelled;
            // closed first, so the "order settled" listener that the move triggers finds nothing open
            $this->close($run, RunStatus::Resolved, $action === ReplyAction::Confirm ? 'confirmed' : 'cancelled');

            try {
                $this->manage->moveById($order->id, $to, null, 'whatsapp', $action === ReplyAction::Cancel ? 'customer_cancelled' : null);
            } catch (Problem $e) {
                // e.g. the address is flagged for a fix: a person takes it from here
                $this->escalate($run, $order, $e->problemCode);

                return;
            }

            $settings = ConfirmationSettings::current();
            $this->acknowledge($run, $order, $action === ReplyAction::Confirm ? $settings->confirmed_reply : $settings->cancelled_reply);
        });
    }

    /** The team (or the store) settled the order: stop reminding. */
    public function settled(string $orderId): void
    {
        $run = ConfirmationRun::query()->where('order_id', $orderId)->first();

        if ($run !== null && $run->status->isOpen()) {
            $this->close($run, RunStatus::Resolved, 'settled_elsewhere');
        }
    }

    private function sendTemplate(ConfirmationRun $run, OrderSnapshot $order, ConfirmationSettings $settings, bool $first): void
    {
        $numberId = $run->number_id ?? $this->numberFor($settings);

        if ($numberId === null) {
            $this->close($run, RunStatus::Failed, 'no_number');
            OrderTimeline::add($order->id, 'confirmation_failed', null, ['reason' => 'no_number']);

            return;
        }

        $template = $first ? $settings->template_name : ($settings->reminder_template_name ?? $settings->template_name);

        try {
            $message = $this->send->fromNumber($numberId, PhoneNumber::fromString($order->customerPhone), new TemplateMessage(
                (string) $template,
                $settings->template_language,
                self::components($run->id, $order),
            ));
        } catch (Throwable $e) {
            report($e);
            $this->close($run, RunStatus::Failed, 'send_failed');
            OrderTimeline::add($order->id, 'confirmation_failed', null, ['reason' => 'send_failed']);

            return;
        }

        if ($first) {
            $this->manage->moveById($order->id, OrderStatus::Confirming, null, 'whatsapp');
        }

        $reminders = $run->reminders_sent + ($first ? 0 : 1);
        $run->fill([
            'status' => RunStatus::Sent,
            'number_id' => $numberId,
            'messages_sent' => $run->messages_sent + 1,
            'reminders_sent' => $reminders,
            'last_message_id' => $message->id,
            'next_action_at' => now()->addMinutes($reminders < $settings->max_reminders ? $settings->reminder_after_minutes : $settings->escalate_after_minutes),
        ])->save();

        // the message's cost follows from its status webhook (pricing), keyed by message_id
        OrderTimeline::add($order->id, $first ? 'confirmation_sent' : 'confirmation_reminder', null, [
            'message_id' => $message->id, 'template' => $template, 'attempt' => $run->messages_sent,
        ]);
    }

    private function escalate(ConfirmationRun $run, OrderSnapshot $order, string $reason): void
    {
        $this->close($run, RunStatus::Escalated, $reason);
        OrderTimeline::add($order->id, 'confirmation_escalated', null, ['reason' => $reason]);

        $message = [['channel' => 'inbox:'.$run->workspace_id, 'data' => [
            'type' => 'confirmation.escalated', 'order_id' => $order->id, 'contact_id' => $order->contactId, 'reason' => $reason,
        ]]];
        DB::afterCommit(fn () => $this->realtime->publish($message));
    }

    private function close(ConfirmationRun $run, RunStatus $status, string $outcome): void
    {
        $run->fill(['status' => $status, 'outcome' => $outcome, 'next_action_at' => null, 'resolved_at' => now()])->save();
    }

    /** A short reply after a button press (the customer just wrote, so the 24-hour window is open). */
    private function acknowledge(ConfirmationRun $run, OrderSnapshot $order, ?string $text): void
    {
        if ($text === null || trim($text) === '' || $run->number_id === null || ! $this->numbers->isActive($run->number_id)) {
            return;
        }

        $this->send->fromNumber($run->number_id, PhoneNumber::fromString($order->customerPhone), new TextMessage($text));
    }

    private function numberFor(ConfirmationSettings $settings): ?string
    {
        if ($settings->number_id !== null && $this->numbers->isActive($settings->number_id)) {
            return $settings->number_id;
        }

        return $this->numbers->defaultNumberId();
    }

    /**
     * Template parameters: {{1}} customer name, {{2}} order number, {{3}} amount to pay on delivery;
     * three quick-reply buttons in this order: Confirm, Change, Cancel.
     *
     * @return list<array<string, mixed>>
     */
    public static function components(string $runId, OrderSnapshot $order): array
    {
        $amount = number_format($order->total / 100, $order->total % 100 === 0 ? 0 : 2).' '.($order->currency === 'EGP' ? 'ج.م' : $order->currency);

        return [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => $order->customerName ?? 'عميلنا'],
                ['type' => 'text', 'text' => $order->number],
                ['type' => 'text', 'text' => $amount],
            ]],
            ...array_map(fn (int $i, ReplyAction $a): array => [
                'type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i,
                'parameters' => [['type' => 'payload', 'payload' => $a->payload($runId)]],
            ], [0, 1, 2], [ReplyAction::Confirm, ReplyAction::Change, ReplyAction::Cancel]),
        ];
    }
}
