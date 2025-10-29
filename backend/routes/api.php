<?php

use App\Models\User;
use App\Models\Video;
use App\Models\Question;
use App\Services\InfobipVerifyService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

Route::post('/upload', function (Request $request) {
    
    // Clear existing videos
    $videos = Video::where('lang', $request->input('lang', 'en'))
    ->where('user_id', $request->user()->id)
    ->get();
    foreach ($videos as $video) {
        Storage::disk('private')->delete($video->filename);
        $video->delete();
    }

    $nVideo = 1;
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
            'question' => $request->input('questions')[$nVideo - 1] ?? null,
            'lang' => $request->input('lang', 'en'),
        ]);
        $nVideo++;
    }
    return response()->json(['message' => 'upload_success']);
})->middleware('auth:sanctum');

Route::post('/admin/login', function (Request $request) {
    
    $data = $request->validate([
        'login' => 'required|string',
        'password' => 'required|string',
    ]);
    $user = User::where('name', $data['login'])->first();
    if (!$user || !Hash::check($data['password'], $user->password)) {
        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    $token = $user->createToken('admin')->plainTextToken;
    return response()->json(['message' => 'authenticated', 'token' => $token]);
});

Route::get('/admin/question', function (Request $request) {
    $questions = Question::where('lang', $request->query('lang', 'en'))->orderBy('position')->get();
    return response()->json(['questions' => $questions]);
})->middleware('auth:sanctum');

Route::post('/admin/question', function (Request $request) {
    $data = $request->validate([
        'questions' => 'required|array',
        'questions.*.id' => 'nullable|integer|exists:questions,id',
        'questions.*.question' => 'required|string',
        'questions.*.position' => 'integer',
    ]);

    // Clear existing questions
    Question::where('lang', $request->input('lang', 'en'))->delete();

    // Re-insert questions with new positions
    foreach ($data['questions'] as $index => $qData) {
        Question::create([
            'question' => $qData['question'],
            'position' => $qData['position'] ?? $index,
            'lang' => $request->input('lang', 'en'),
        ]);
    }

    return response()->json(['message' => 'Questions updated']);
})->middleware('auth:sanctum');

Route::get('/admin/interviews', function (Request $request) {
    $users = User::whereHas('videos')->with(['videos'])->get();

    $interviews = $users->map(function ($user) {
        return [
            'id' => $user->id,
            'user' => $user->name,
            'time' => $user->videos->max('created_at'),
            'comment' => $user->comment ?? '',
            'videos' => ($user->videos)->map(function ($video) {
                return [
                    'id' => $video->id,
                    'question' => $video->question,
                    // 'filename' => $video->filename,
                    // 'original_filename' => $video->original_filename
                ];
            }),
        ];
    });
    return response()->json(['interviews' => $interviews]);
})->middleware('auth:sanctum');

Route::get('/admin/video/{id}', function (Request $request, $id) {
    $video = Video::findOrFail($id);
    $path = Storage::disk('private')->path($video->filename);
    if (!file_exists($path)) {
        abort(404, 'File not found');
    }
    return response()->file($path, [
        'Content-Type' => $video->mime_type ?? 'application/octet-stream',
        'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
    ]);
})->middleware('auth:sanctum');

Route::post('/admin/interview/{id}/comment', function (Request $request, $id) {
    $data = $request->validate([
        'comment' => 'nullable|string',
    ]);
    $user = User::findOrFail($id);
    $user->comment = $data['comment'];
    $user->save();
    return response()->json(['message' => 'Comment saved']);
})->middleware('auth:sanctum');