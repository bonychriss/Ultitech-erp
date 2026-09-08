<?php

namespace App\Http\Controllers;

use App\Domains\Sales\LegacyApiBridge;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales desk JSON API — Phase 1 domain bridge.
 */
class SalesDeskApiController extends Controller
{
    public function desk(Request $request, string $desk): Response
    {
        return (new LegacyApiBridge())->handle($request, $desk);
    }
}
