<?php

namespace App\Exceptions;

/**
 * Thrown for business-rule violations (double-booking, invalid state
 * transition, insufficient stock, day already locked, etc). Caught
 * globally and rendered as a friendly validation-style message —
 * never a raw stack trace — per spec section 27.
 */
class HmsException extends \RuntimeException
{
}
