<?php

namespace App\Domain\Identity\Oidc;

use RuntimeException;

/**
 * Any reason a sign-in through the provider is refused. The message is for the
 * security log and never reaches the browser: the user is always told the same
 * generic thing a wrong password gets (security.md §4).
 */
final class OidcException extends RuntimeException {}
