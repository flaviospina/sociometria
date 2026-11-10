<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Saúde para monitoramento externo: informa só o estado, sem detalhes internos. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection('control')->select('SELECT 1');

            return response()->json(['status' => 'ok']);
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }
    }
}
