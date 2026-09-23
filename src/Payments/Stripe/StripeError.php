<?php

namespace EmbedForms\Payments\Stripe;

/**
 * An answer from Stripe that says the request failed: a declined card
 * (type card_error, HTTP 402), an invalid request or rejected keys. Nothing
 * was charged.
 */
final class StripeError extends \RuntimeException {

  public int $status;

  public array $error;

  public function __construct(int $status, array $error) {
    $this->status = $status;
    $this->error = $error;
    parent::__construct((string) ($error['message'] ?? ('Stripe error (HTTP ' . $status . ')')));
  }

  public function type(): string {
    return (string) ($this->error['type'] ?? '');
  }

  public function isCardError(): bool {
    return $this->type() === 'card_error';
  }

  /**
   * The same idempotency key was used with different parameters: another
   * attempt of the same payment is on its way.
   */
  public function isIdempotencyConflict(): bool {
    return $this->type() === 'idempotency_error';
  }

  /**
   * The object the failed request left behind (a declined PaymentIntent).
   */
  public function paymentIntent(): ?array {
    return is_array($this->error['payment_intent'] ?? NULL) ? $this->error['payment_intent'] : NULL;
  }

  /**
   * For the admin: message plus decline code.
   */
  public function detail(): string {
    $codes = array_filter([(string) ($this->error['code'] ?? ''), (string) ($this->error['decline_code'] ?? '')]);
    return $this->getMessage() . ($codes ? ' (' . implode(', ', array_unique($codes)) . ')' : '');
  }

}
