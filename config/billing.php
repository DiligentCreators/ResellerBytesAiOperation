<?php

return [
    // Provisional operational default; commercial grace-period policy remains
    // configurable and must be reviewed before external billing activation.
    'payment_failure_grace_days' => (int) env('BILLING_PAYMENT_FAILURE_GRACE_DAYS', 7),

    // Empty until Shahrukh approves a payment provider and its adapter.
    'providers' => [],
];
