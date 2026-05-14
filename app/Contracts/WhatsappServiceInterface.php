<?php

namespace App\Contracts;

interface WhatsappServiceInterface
{
    /**
     * Send a magic link login URL to the given phone number.
     */
    public function sendMagicLink(string $phone, string $url): void;

    /**
     * Send a generic message to the given phone number.
     */
    public function sendMessage(string $phone, string $message): void;
}
