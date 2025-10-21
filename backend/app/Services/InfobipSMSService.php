<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class InfobipSmsService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.infobip.base_url'), '/');
        $this->apiKey = config('services.infobip.api_key');
    }

    public function send(string $to, string $message): array
    {
        $response = Http::withHeaders([
            'Authorization' => "App {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/sms/2/text/advanced", [
            'messages' => [[
                'from' => 'FPGA-LAB',
                'destinations' => [['to' => $to]],
                'text' => $message,
            ]]
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to send SMS: ' . $response->body());
        }

        return $response->json();
    }
}
