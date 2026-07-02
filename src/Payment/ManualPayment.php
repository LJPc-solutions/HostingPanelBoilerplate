<?php

declare(strict_types=1);

namespace Panel\Payment;

// Placeholder gateway that auto-approves every order. Replace it with a real PSP
// integration (for example Mollie or Stripe): start() creates the PSP payment and
// returns its checkout URL, confirm() verifies the PSP webhook or return.
final class ManualPayment implements PaymentGateway
{
    public function start(array $order, string $returnUrl): string
    {
        return $returnUrl;
    }

    public function confirm(array $order, array $requestData): bool
    {
        return true;
    }
}
