<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class VideoController extends Controller
{

    private $chunkPath = 'app/private/video_chunks/';
    private $finalPath = 'app/private/videos/';

    public function chunk(Request $request)
    {
        $questionId = $request->input('question_id');
        $userId = $request->input('user_id');

        $dir = storage_path($this->chunkPath . "u{$userId}/q{$questionId}");
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        $file = $request->file('chunk');

        $filename = 'chunk_' . microtime(true) . '.webm';

        $file->move($dir, $filename);

        return response()->json(['status' => 'ok']);
    }

    public function merge(Request $request)
    {
        $questionId = $request->input('question_id');
        $userId = $request->input('user_id');
        $dir = storage_path($this->chunkPath . "u{$userId}/q{$questionId}");

        $fianlDir = storage_path($this->finalPath . "u{$userId}/");
        if (!is_dir($fianlDir)) mkdir($fianlDir, 0777, true);
        $final = $fianlDir . "answer_{$questionId}.webm";

        $chunks = glob($dir . "/*.webm");
        sort($chunks);
        $concat = "concat:" . implode("|", array_map('realpath', $chunks));

        $cmd = "ffmpeg -i \"$concat\" -c copy \"$final\" -y";
        exec($cmd);
        return response()->json(['status' => 'ok']);
    }

    public function prepare(Request $request)
    {
        $userId = $request->input('user_id');
        $questionId = $request->input('question_id');
        $chunks = glob(storage_path($this->chunkPath . "u{$userId}/q{$questionId}") . "/*.webm");
        foreach ($chunks as $chunk) {
            unlink($chunk);
        }
        $final = storage_path($this->finalPath . "u{$userId}/answer_{$questionId}.webm");
        if (file_exists($final)) unlink($final);
        return response()->json(['status' => 'ready']);
    }
}
