<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider confirmed this initiation was not accepted and failover is safe.
 */
class PaymentGatewayRetryableException extends RuntimeException {}
