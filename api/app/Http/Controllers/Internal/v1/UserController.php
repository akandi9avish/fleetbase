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
            $record = $this->model->createRecordFromRequest(
                $request,
                null, // No onBefore callback needed - base controller handles everything
                function (&$request, &$user) {
                    // Set password explicitly (it's guarded from mass assignment)
                    if ($request->filled('user.password')) {
                        $user->password = $request->input('user.password');
                        $user->save();

                        Log::info("✅ Set password for user", [
                            'user_email' => $user->email
                        ]);
                    }
                }
            );

            return response()->json($record);
        } catch (\Exception $e) {
            Log::error("❌ Failed to create user", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->error($e->getMessage());
        }
    }
}
