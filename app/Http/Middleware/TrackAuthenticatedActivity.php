<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackAuthenticatedActivity
{
    public function __construct(private readonly DeviceService $deviceService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->deviceService->touchCurrent($user);
        }

        return $next($request);
    }
}
