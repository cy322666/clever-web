<?php

namespace App\Services\Telegram;

class Client
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://api.telegram.org/bot'.config('services.telegram.token');
    }

    public function send(string $message)
    {
        if (config('services.telegram.chat_id'))
            file_get_contents($this->baseUrl.'/sendMessage?chat_id='.config('services.telegram.chat_id').'&text='.$message);
    }
}
