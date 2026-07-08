<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Config;

/**
 * Resolves the configured iDoklad accounts from .env.
 *
 * Preferred config — a keyed list, one line naming the accounts plus a
 * credential pair per key (the key, upper-cased, forms the env prefix):
 *
 *   IDOKLAD_ACCOUNTS=1,2
 *   IDOKLAD_1_CLIENT_ID=...    IDOKLAD_1_CLIENT_SECRET=...
 *   IDOKLAD_2_CLIENT_ID=...    IDOKLAD_2_CLIENT_SECRET=...
 *
 * An optional per-account IDOKLAD_<KEY>_API_URL overrides the shared
 * IDOKLAD_API_URL. Keys must be alphanumeric/underscore so they map to valid
 * env var names, and they are the stable identity stored against each invoice —
 * do not renumber a key once it has synced invoices.
 *
 * Backward compatible: when IDOKLAD_ACCOUNTS is unset, the legacy single-account
 * IDOKLAD_CLIENT_ID / IDOKLAD_CLIENT_SECRET pair is exposed as account 'default'.
 */
final class IDokladAccountRegistry
{
    public const LEGACY_KEY = 'default';

    /**
     * @return IDokladAccount[] Only accounts whose credentials are actually set.
     */
    public static function all(): array
    {
        $defaultApiUrl = (string) Config::get('IDOKLAD_API_URL', 'https://api.idoklad.cz/v3');
        $keys = Config::getArray('IDOKLAD_ACCOUNTS');

        if ($keys === []) {
            $legacy = new IDokladAccount(
                self::LEGACY_KEY,
                (string) Config::get('IDOKLAD_CLIENT_ID', ''),
                (string) Config::get('IDOKLAD_CLIENT_SECRET', ''),
                $defaultApiUrl
            );

            return $legacy->isConfigured() ? [$legacy] : [];
        }

        $accounts = [];
        $seen = [];

        foreach ($keys as $rawKey) {
            $key = trim($rawKey);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $envPrefix = 'IDOKLAD_' . strtoupper($key);
            $account = new IDokladAccount(
                $key,
                (string) Config::get($envPrefix . '_CLIENT_ID', ''),
                (string) Config::get($envPrefix . '_CLIENT_SECRET', ''),
                (string) Config::get($envPrefix . '_API_URL', $defaultApiUrl)
            );

            if ($account->isConfigured()) {
                $accounts[] = $account;
            } else {
                error_log(sprintf(
                    'iDoklad account "%s" is listed in IDOKLAD_ACCOUNTS but %s_CLIENT_ID/%s_CLIENT_SECRET are not set — skipping.',
                    $key,
                    $envPrefix,
                    $envPrefix
                ));
            }
        }

        return $accounts;
    }
}
