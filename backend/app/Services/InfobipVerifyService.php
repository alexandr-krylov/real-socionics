<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class InfobipVerifyService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.infobip.base_url'), '/');
        $this->apiKey = config('services.infobip.api_key');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => "App {$this->apiKey}",
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /** 🔹 1. Создание приложения */
    public function createApplication(string $appName): ?string
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/verify/2/applications", [
                'name' => $appName,
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create Infobip application: ' . $response->body());
        }

        $data = $response->json();
        $this->storeId('application', $data['applicationId']);

        return $data['applicationId'];
    }

    /** 🔹 2. Создание шаблона */
    public function createTemplate(string $templateName, string $messageText): ?string
    {
        $appId = $this->getId('application');

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/verify/2/applications/{$appId}/templates", [
                'name' => $templateName,
                'pinType' => 'NUMERIC',
                'pinLength' => 6,
                'messageText' => $messageText,
                'senderId' => 'InfoSMS',
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create Infobip template: ' . $response->body());
        }

        $data = $response->json();
        $this->storeId('template', $data['templateId']);

        return $data['templateId'];
    }

    /** 🔹 3. Отправка проверочного кода */
    public function sendCode(string $phone): array
    {
        $templateId = $this->getId('template');

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/verify/2/verifications", [
                'applicationId' => $this->getId('application'),
                'templateId' => $templateId,
                'destination' => [
                    'to' => $phone,
                ],
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to send verification code: ' . $response->body());
        }

        return $response->json();
    }

    /** 🔹 4. Проверка кода */
    public function verifyCode(string $pinId, string $code): bool
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/verify/2/verifications/{$pinId}", [
                'code' => $code,
            ]);

        if ($response->failed()) {
            return false;
        }

        $data = $response->json();

        return isset($data['verified']) && $data['verified'] === true;
    }

    /** 🔹 Хранение ID (в storage/app/infobip.json) */
    protected function storeId(string $key, string $value): void
    {
        $data = $this->loadIds();
        $data[$key] = $value;
        Storage::disk('local')->put('infobip.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function getId(string $key): ?string
    {
        $data = $this->loadIds();
        return $data[$key] ?? null;
    }

    protected function loadIds(): array
    {
        if (!Storage::disk('local')->exists('infobip.json')) {
            return [];
        }
        return json_decode(Storage::disk('local')->get('infobip.json'), true) ?? [];
    }
}
