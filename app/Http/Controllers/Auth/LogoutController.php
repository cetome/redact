<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SecurityLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            SecurityLog::log('sign-out', $user, [], $user);
        }

        // This device only: its "Keep me signed in" cookie goes, other remembered devices stay
        // (logout() would cycle the shared remember token). "Sign out everywhere" ends them all.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('auth.signed_out'));
    }
}
