<?php

namespace EmbedForms\Payments\Stripe;

/**
 * No usable answer from Stripe: the request may or may not have happened.
 * Repeating it with the same idempotency key finds out.
 */
final class AmbiguousError extends \RuntimeException {
}
