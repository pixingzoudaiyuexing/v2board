<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SubscriptionEntryService;
use App\Utils\Helper;
use DomainException;
use Illuminate\Http\Request;
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

    public function generate(Request $request, SubscriptionEntryService $service)
    {
        $selectedBase = $this->rawBaseUrl($request);
        if (!is_string($selectedBase)) {
            return response(['message' => 'Selected subscription entry is invalid'], 422);
        }

        try {
            $baseUrl = $service->resolveSelectedBase($selectedBase);
        } catch (InvalidArgumentException $exception) {
            return response(['message' => 'Subscription entry configuration is invalid'], 500);
        } catch (DomainException $exception) {
            return response(['message' => 'Selected subscription entry is invalid'], 422);
        }

        $user = User::where('id', $request->user['id'])
            ->select(['id', 'token'])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        return response([
            'data' => [
                'subscribe_url' => Helper::getSubscribeUrlForBase($user->token, $baseUrl)
            ]
        ]);
    }

    private function rawBaseUrl(Request $request)
    {
        $payload = null;
        if ($request->isJson()) {
            $payload = json_decode($request->getContent(), true);
        } elseif ($request->getContentType() === 'form') {
            $payload = [];
            parse_str($request->getContent(), $payload);
        }

        if (!is_array($payload) || !array_key_exists('base_url', $payload)) {
            return null;
        }

        return $payload['base_url'];
    }
}
