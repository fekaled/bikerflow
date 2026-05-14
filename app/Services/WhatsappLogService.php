<?php

namespace App\Services;

use App\Contracts\WhatsappServiceInterface;
use Illuminate\Support\Facades\Log;

class WhatsappLogService implements WhatsappServiceInterface
{
    public function sendMagicLink(string $phone, string $url): void
    {
        Log::info("[WhatsApp Fake] Magic link sent to {$phone}: {$url}");
    }

    public function sendMessage(string $phone, string $message): void
    {
        Log::info("[WhatsApp Fake] Message sent to {$phone}: {$message}");
    }
}
