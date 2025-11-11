<?php

namespace App\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Internal\v1\UserController as BaseUserController;
use Fleetbase\Models\Company;
use Fleetbase\Support\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Custom UserController to handle Railway internal networking issues.
 *
 * Overrides the createRecord method to inject company from request payload
 * when session context is unavailable (Railway internal HTTP calls).
 */
class UserController extends BaseUserController
{
    /**
     * Override to inject company from payload when session is unavailable.
     *
     * This fixes the issue where Railway's internal networking doesn't propagate
     * session cookies, causing Auth::getCompany() to return null.
     */
    public function createRecord(\Illuminate\Http\Request $request)
    {
        try {
            $record = $this->model->createRecordFromRequest($request, function (&$request, &$input) {
                // Apply standard user info
                $input = $this->model::applyUserInfoFromRequest($request, array_merge($input, [
                    'company_uuid' => session('company'),
                    'type' => $request->input('user.type', 'user'),
                ]));
            }, function (&$request, &$user) {
                // Set password explicitly (it's guarded from mass assignment)
                if ($request->filled('user.password')) {
                    $user->password = $request->input('user.password');
                    $user->save();

                    Log::info("✅ Set password for user", [
                        'user_email' => $user->email
                    ]);
                }

                // Get company from session (may be null in Railway internal networking)
                $company = Auth::getCompany();

                // If company is null but we have company_uuid in payload, fetch it
                if (!$company && $request->has('user.company_uuid')) {
                    $companyUuid = $request->input('user.company_uuid');
                    $company = Company::where('uuid', $companyUuid)->first();

                    if ($company) {
                        Log::info("✅ Injected company from payload for user creation", [
                            'company_uuid' => $companyUuid,
                            'company_name' => $company->name,
                            'user_email' => $user->email
                        ]);
                    } else {
                        Log::error("❌ Invalid company_uuid in payload", [
                            'company_uuid' => $companyUuid
                        ]);
                        throw new \Exception("Invalid company_uuid: {$companyUuid}");
                    }
                }

                // Now proceed with user setup
                $user->setUserType('user');

                // Assign company (now $company should not be null)
                if ($company) {
                    $user->assignCompany($company, $request->input('user.role_uuid'));

                    if ($request->filled('user.role_uuid')) {
                        $user->assignSingleRole($request->input('user.role_uuid'));
                    }
                } else {
                    Log::error("❌ No company available for user creation", [
                        'user_email' => $user->email,
                        'has_session_company' => session()->has('company'),
                        'has_payload_company' => $request->has('user.company_uuid')
                    ]);
                    throw new \Exception("No company context available");
                }
            });

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
