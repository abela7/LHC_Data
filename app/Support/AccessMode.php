<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

class AccessMode
{
    public const ADMIN = 'admin';

    public const DATA_ENTRY = 'data_entry';

    public static function current(): ?string
    {
        $mode = session('access_mode');

        return in_array($mode, [self::ADMIN, self::DATA_ENTRY], true) ? $mode : null;
    }

    public static function isSet(): bool
    {
        return self::current() !== null;
    }

    public static function isAdmin(): bool
    {
        return self::current() === self::ADMIN;
    }

    public static function isDataEntry(): bool
    {
        return self::current() === self::DATA_ENTRY;
    }

    public static function allowsRoute(?Request $request = null): bool
    {
        if (! self::isDataEntry()) {
            return true;
        }

        $request ??= request();

        return $request->routeIs([
            'data-entry.dashboard',
            'brand-catalogue.*',
            'body-care-brand-catalogue',
            'retail-products.*',
            'access.choose',
            'access.admin',
            'access.admin.submit',
            'access.data-entry',
            'access.switch',
        ]);
    }
}
