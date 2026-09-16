<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionEntryService;
use InvalidArgumentException;

class SubscriptionEntryController extends Controller
{
    public function index(SubscriptionEntryService $service)
    {
        try {
            return response(['data' => ['entries' => $service->entries()]]);
        } catch (InvalidArgumentException $exception) {
            return response(['message' => 'Subscription entry configuration is invalid'], 500);
        }
    }
}
