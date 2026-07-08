<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * One iDoklad agenda (legal entity). The cleaning company splits its invoicing
 * across several iDoklad accounts as a tax optimization; each account has its
 * own OAuth credentials and its own cached access token, addressed by the
 * stable operator-assigned $key.
 *
 * The $key is persisted on every synced invoice (invoices.idoklad_account) so a
 * PDF can later be fetched from the exact account that issued it, and so the
 * same iDoklad invoice id coming from two different accounts never collides.
 */
final class IDokladAccount
{
    public function __construct(
        public readonly string $key,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $apiUrl
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }
}
