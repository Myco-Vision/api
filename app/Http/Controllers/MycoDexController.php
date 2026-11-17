<?php

namespace App\Http\Controllers;

use App\Services\MycoDexService;
use Illuminate\Http\Request;

class MycoDexController extends Controller
{
    protected MycoDexService $mycoDexService;

    public function __construct(MycoDexService $mycoDexService)
    {
        $this->mycoDexService = $mycoDexService;
    }

    /**
     * GET /api/user/mycodex
     * Returns user points, redemption progress, unlocked species, and catalog grid.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $data = $this->mycoDexService->getMycoDexData($user);

        return response()->json($data);
    }

    /**
     * POST /api/user/redeem-subscription
     * Redeems 100 verified points for a 7-day Pro pass.
     */
    public function redeemSubscription(Request $request)
    {
        $user = $request->user();

        try {
            $result = $this->mycoDexService->redeemSubscription($user);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to redeem subscription: ' . $e->getMessage(),
            ], 500);
        }
    }
}
