<?php

declare(strict_types=1);

namespace App\Identity\Local;

/**
 * The supplied password was correct, but it is a temporary (must-change) password that has
 * passed PAL_TEMP_PASSWORD_MAX_AGE_HOURS. Sign-in is refused until an administrator resets it.
 *
 * Thrown by {@see LocalAccountService::verify()} only after the password is verified, so it
 * never reveals whether an account exists to a caller who does not already hold the credential.
 */
final class TemporaryPasswordExpiredException extends \RuntimeException
{
}
