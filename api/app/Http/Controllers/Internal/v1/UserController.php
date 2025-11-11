<?php

namespace App\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Internal\v1\UserController as BaseUserController;
use Illuminate\Support\Facades\Log;

/**
 * REEUP override: Allow password to be set during user creation.
 *
 * Fleetbase guards the password field from mass assignment for security,
 * but we need to set it programmatically when syncing users from backend API.
 *
 * Note: Auth::getCompany() already has built-in fallback to read company_uuid
 * from request payload, so no custom company injection is needed.
 */
class UserController extends BaseUserController
{
    /**
     * Override createRecord to set password explicitly.
     *
     * The base controller handles all standard user creation logic including:
     * - Company assignment (via Auth::getCompany() which reads from session OR payload)
     * - Role assignment
     * - User type setting
     *
     * We ONLY need to set the password since it's guarded from mass assignment.
     */
    public function createRecord(\Illuminate\Http\Request $request)
    {
        try {
            // Call parent's createRecord to leverage ALL base functionality
            // This ensures username generation, timezone handling, and all other
            // standard Fleetbase user creation logic runs properly
            $record = parent::createRecord($request);

            // After parent creates the user, set password if provided
            // (password is guarded from mass assignment, so parent can't set it)
            if ($request->filled('user.password')) {
                // Extract user from parent's response
                $userData = $record instanceof \Illuminate\Http\JsonResponse
                    ? $record->getData(true)
                    : $record;

                if (isset($userData['user'])) {
                    $userId = $userData['user']['uuid'] ?? $userData['user']['id'];
                    $user = \Fleetbase\Models\User::where('uuid', $userId)->first();

                    if ($user) {
                        $user->password = $request->input('user.password');
                        $user->save();

                        Log::info("✅ Set password for user", [
                            'user_email' => $user->email
                        ]);
                    }
                }
            }

            return $record;
        } catch (\Exception $e) {
            Log::error("❌ Failed to create user", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->error($e->getMessage());
        }
    }
}
