<?php

namespace App\Auth;

use Illuminate\Http\Request;

/**
 * Keys for rate limits: an IPv4 address as is, an IPv6 address by its /64
 * (one subscriber usually owns a whole /64), an e-mail address hashed.
 */
final class ClientKey
{
    public static function ip(Request $request): string
    {
        $ip = (string) $request->ip();

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
            }
        }

        return $ip;
    }

    public static function email(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
