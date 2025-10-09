<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for('otp-send', function (Request $request) {
    $identifier = $request->input('email') ?? $request->input('phone') ?? 'unknown';
    $key = sha1($identifier . '|' . $request->ip());
    return Limit::perMinute(5)->by($key);
});

RateLimiter::for('otp-verify', function (Request $request) {
    $key = sha1($request->ip());
    return Limit::perMinute(5)->by($key);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/auth/passwordless/send', function (Request $request) {
    $data = $request->validate([
        'email' => 'nullable|email',
        'phone' => 'nullable|string',
    ]);

    if (empty($data['email']) && empty($data['phone'])) {
        return response()->json(['error' => 'Email или телефон обязателен'], 422);
    }

    $identifier = $data['email'] ?? $data['phone'];
    $code = random_int(100000, 999999);

    Cache::put("otp_{$identifier}", $code, now()->addMinutes(5));

    if (isset($data['email'])) {
        Mail::raw("Ваш код входа: $code", fn($msg) => $msg->to($data['email'])->subject('Код для входа'));
    } else {
        // Пример — заглушка под SMS
        // Http::post('https://sms-provider.example.com/send', ['to' => $data['phone'], 'text' => "Ваш код: $code"]);
    }

    return response()->json(['message' => 'code_sent']);//, 200);
})->middleware('throttle:otp-send');

Route::post('/auth/passwordless/verify', function (Request $request) {
    $data = $request->validate([
        'identifier' => 'nullable|string',
        'code' => 'required|integer',
    ]);
    $data['code'] = (int)$data['code'];
    if (empty($data['identifier'])) {
        return response()->json(['error' => 'Identifier обязателен'], 422);
    }

    $identifier = $data['identifier'];
    $code = Cache::get("otp_{$identifier}");
    if ($code !== $data['code']) {
        return response()->json(['error' => 'Неверный код'], 422);
    }
    Cache::forget("otp_{$identifier}");
    $user = User::where('email', $identifier)->orWhere('phone', $identifier)->first();
    if (!$user) {
        $user = User::create([
            'email' => filter_var($identifier, FILTER_VALIDATE_EMAIL) ? $identifier : null,
            'phone' => !filter_var($identifier, FILTER_VALIDATE_EMAIL) ? $identifier : null,
            'name' => $identifier,
        ]);
    }

    $token = $user->createToken('mobile')->plainTextToken;
    return response()->json(['message' => 'authenticated', 'token' => $token]);
})->middleware('throttle:otp-verify');

Route::get('/login', fn() => response()->json(['error' => 'Login required'], 401))->name('login');

Route::get('/whoami', function (Request $request) {
    return [
        'user' => $request->user(),
        'session' => $request->session()->all(),
        'cookies' => request()->cookies->all(),
    ];
});

