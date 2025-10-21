<?php

use App\Models\User;
use App\Models\Video;
use App\Services\InfobipVerifyService;
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
    if (isset($data['email'])) {
        $code = random_int(100000, 999999);
        Cache::put("otp_{$identifier}", $code, now()->addMinutes(5));
        Mail::raw("Ваш код входа: $code", fn($msg) => $msg->to($data['email'])->subject('Код для входа'));
    } else {
        $res = app(App\Services\InfobipVerifyService::class)->sendCode($data['phone']);
        $pinId = $res['pinId'];
        Cache::put("otp_{$identifier}", $pinId, now()->addMinutes(5));
        // Пример — заглушка под SMS
        // Http::post('https://sms-provider.example.com/send', ['to' => $data['phone'], 'text' => "Ваш код: $code"]);
    }

    return response()->json(['message' => 'code_sent' . $pinId ?? '']);//, 200);
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
    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $pinId = Cache::get("otp_{$identifier}");
        $res = app(InfobipVerifyService::class)->verifyCode($pinId, $data['code']);
        if (!$res) {
            return response()->json(['error' => 'Неверный код'], 422);
        }
        Cache::forget("otp_{$identifier}");
    } else {
        $code = Cache::get("otp_{$identifier}");
        if ($code !== $data['code']) {
            return response()->json(['error' => 'Неверный код'], 422);
        }
        Cache::forget("otp_{$identifier}");
    }

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

Route::post('/upload', function (Request $request) {
    $nVideo = 1;
    // dd($request->hasFile('answer_' . $nVideo), $request->all());
    while ($request->hasFile('answer_' . $nVideo)) {
        $request->validate([
            "answer_$nVideo" => 'required|file|mimetypes:video/webm|max:204800'
        ]);

        $path = $request->file("answer_$nVideo")->store('videos', 'private');
        Video::create([
            'user_id' => $request->user()->id,
            'filename' => $path,
            'original_filename' => $request->file("answer_$nVideo")->getClientOriginalName(),
            'filesize' => $request->file("answer_$nVideo")->getSize(),
            'mime_type' => $request->file("answer_$nVideo")->getClientMimeType(),
        ]);
        $nVideo++;
    }
    return response()->json(['path' => $path]);
})->middleware('auth:sanctum');

