<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Per account and client: failures for one email from one IP. Another email (or the same
        // email from another client) has its own counter, so one attacker can't lock everyone out.
        $throttleKey = 'login:'.mb_strtolower($credentials['email']).'|'.$request->ip();
        $maxAttempts = config('app.login_rate_limit');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning('Login throttled', ['email' => $credentials['email'], 'ip' => $request->ip()]);

            return response()->json([
                'message' => "Too many login attempts. Try again in {$seconds} seconds.",
            ], 429)->header('Retry-After', (string) $seconds);
        }

        if (Auth::attempt($credentials)) {
            RateLimiter::clear($throttleKey);

            // Only said after the password checked out, so it doesn't reveal which accounts exist.
            if (Auth::user()->isDeactivated()) {
                Auth::guard('web')->logout();

                return response()->json(['message' => 'This account is deactivated.'], 403);
            }

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            return response()->json([
                'user' => Auth::user(),
                'message' => 'Login successful',
            ]);
        }

        RateLimiter::hit($throttleKey, 60);
        // Never the password: only who was tried, from where.
        Log::warning('Failed login', ['email' => $credentials['email'], 'ip' => $request->ip()]);

        return response()->json([
            'message' => 'The provided credentials do not match our records.',
        ], 422);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}
