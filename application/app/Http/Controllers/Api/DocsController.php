<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integrations\Docs\Doc;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class DocsController extends Controller
{
    /**
     * @param Request $request
     * @return View|Application|Factory|\Illuminate\Contracts\Foundation\Application
     */
    public function redirect(Request $request): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        return view('api.yandex-redirect');
    }

    public function hook(User $user, string $doc, Request $request)
    {
        $doc = Doc::query()->create([
            'user_id' => $user->id,
            'lead_id' => $request->leads['add'][0]['id'] ?? $request->leads['status'][0]['id'],
            'doc_id'  => $doc,
            'status'  => false,
        ]);

        Artisan::call('app:doc-generate', [
            'doc' => $doc->id,
            'account' => $user->account->id,
            'setting' => $user->doc_settings->id,
        ]);
    }
}
