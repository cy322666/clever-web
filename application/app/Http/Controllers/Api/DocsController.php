<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocsController extends Controller
{
    public function redirect(Request $request)
    {
        Log::info(__METHOD__, [file_get_contents('php://input')]);
    }
}
