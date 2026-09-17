<?php

namespace App\Http\Controllers;

use App\Services\YClients\YClientsMarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class YClientsMarketplaceController extends Controller
{
    public function register(Request $request, YClientsMarketplaceService $service): RedirectResponse
    {
        return $service->registrationRedirect($request);
    }

    public function callback(Request $request, YClientsMarketplaceService $service): JsonResponse
    {
        return $service->handleCallback($request);
    }
}
