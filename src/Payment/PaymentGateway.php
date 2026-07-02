<?php

declare(strict_types=1);

namespace Panel\Payment;

interface PaymentGateway
{
    /** Begin payment for an order; returns a URL to send the customer to.
     *  Real gateways (Mollie, Stripe, ...) create a payment here and return their checkout URL. */
    public function start(array $order, string $returnUrl): string;

    /** Verify the outcome when the customer returns (and/or via webhook). True = paid. */
    public function confirm(array $order, array $requestData): bool;
}
