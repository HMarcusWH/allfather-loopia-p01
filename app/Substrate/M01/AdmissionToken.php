<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M01;

/**
 * The opaque admission token.
 *
 * It carries NO state: everything authoritative lives in `DispatchAdmission`'s WeakMap, keyed by
 * this object's identity. A caller that somehow obtains a token still holds nothing — the binding
 * is not in it, and a token with no binding is refused as NOT_GOVERNED.
 *
 * `create()` is deliberately not marked internal by convention alone: it is the only constructor,
 * and it is called from exactly one place. A token minted outside `DispatchAdmission::mint()` never
 * enters the binding map, so it authorizes nothing.
 */
final class AdmissionToken
{
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }
}
