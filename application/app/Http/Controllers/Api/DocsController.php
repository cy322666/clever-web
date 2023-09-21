<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integrations\Docs\Doc;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class DocsController extends Controller
{
    public function redirect(Request $request)
    {
        dd($_GET);

        Log::info(__METHOD__, $request->all());
        //https://oauth.yandex.ru/authorize?response_type=token&client_id=b5ecb51c6a594755a2faefda59c2c6a9&state=test
    }

    public function hook(User $user, string $doc, Request $request)
    {
        Doc::query()->create([
            'user_id' => $user->id,
            'lead_id' => $request->leads['add'][0]['id'] ?? $request->leads['status'][0]['id'],
            'doc_id'  => $doc,
            'status'  => false,
        ]);
    }
}
