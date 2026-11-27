<?php

declare(strict_types=1);

namespace Modules\Waitlist\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Waitlist\Application\SignupInput;
use Modules\Waitlist\Domain\MonthlyOrders;
use Modules\Waitlist\Domain\Platform;

/** The landing form (apps/landing/app/utils/waitlist.ts#waitlistPayload). Unknown fields are ignored. */
final class JoinWaitlistRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'store_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'platform' => ['required', Rule::enum(Platform::class)],
            'monthly_orders' => ['required', Rule::enum(MonthlyOrders::class)],
            'locale' => ['sometimes', 'string', Rule::in(['ar', 'en'])],
            'consent' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['name', 'store_name'] as $field) {
            if (is_string($this->input($field))) {
                // One line of plain text: no control characters, collapsed spaces.
                $clean[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $this->input($field))));
            }
        }
        if (is_string($this->input('email'))) {
            $clean['email'] = mb_strtolower(trim($this->input('email')));
        }
        $this->merge($clean);
    }

    public function toInput(): SignupInput
    {
        return new SignupInput(
            name: $this->string('name')->toString(),
            storeName: $this->string('store_name')->toString(),
            email: $this->string('email')->toString(),
            phone: $this->string('phone')->toString(),
            platform: Platform::from($this->string('platform')->toString()),
            monthlyOrders: MonthlyOrders::from($this->string('monthly_orders')->toString()),
            locale: $this->string('locale', 'ar')->toString(),
            ip: $this->ip(),
        );
    }
}
