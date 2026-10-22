<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * Read-only success predicates for the private Plus 46 TEST bootstrap commands.
 *
 * A plan may legitimately contain CREATE/APPEND_VERSION/BLOCK and still report its
 * proposed actions. Verify may NOT turn that same plan into a successful exit.
 * These predicates carry no database, identity, or execution authority.
 */
final class BootstrapVerification
{
    /** @param array<string, mixed> $plan */
    public static function modulesComplete(array $plan): bool
    {
        if (($plan['blocked'] ?? null) !== [] || !is_array($plan['modules'] ?? null)) {
            return false;
        }
        $modules = $plan['modules'];
        foreach (['P01', 'P02', 'P03', 'P04', 'P05'] as $key) {
            if (($modules[$key]['action'] ?? null) !== 'REUSE') {
                return false;
            }
        }
        return count($modules) === 5;
    }

    /** @param array<string, mixed> $plan */
    public static function loginComplete(array $plan): bool
    {
        return ($plan['blocked_by'] ?? null) === []
            && ($plan['would_create_login'] ?? null) === false
            && ($plan['would_create_trial'] ?? null) === false
            && is_string($plan['existing_login_id'] ?? null)
            && $plan['existing_login_id'] !== '';
    }

    /** @param array<string, mixed> $plan */
    public static function customerComplete(array $plan): bool
    {
        if (($plan['blocked_by'] ?? null) !== []
            || !is_array($plan['sections'] ?? null)
            || $plan['sections'] === []
            || !is_array($plan['intended'] ?? null)) {
            return false;
        }
        foreach ($plan['sections'] as $section) {
            if (!is_string($section)) {
                return false;
            }
            $item = $plan['intended'][$section] ?? null;
            if ($section === 'competitors') {
                if (!is_array($item) || $item === []) {
                    return false;
                }
                foreach ($item as $candidate) {
                    if (($candidate['action'] ?? null) !== 'REUSE') {
                        return false;
                    }
                }
                continue;
            }
            if (!in_array($section, ['company', 'icp', 'catalogue'], true)
                || !is_array($item)
                || ($item['action'] ?? null) !== 'REUSE') {
                return false;
            }
            if ($section === 'catalogue') {
                if (!is_array($item['products'] ?? null) || $item['products'] === []) {
                    return false;
                }
                foreach ($item['products'] as $product) {
                    if (($product['action'] ?? null) !== 'REUSE') {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
