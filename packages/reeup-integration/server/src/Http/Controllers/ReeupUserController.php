<?php

namespace Reeup\Integration\Http\Controllers;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * REEUP User Controller
 *
 * Custom endpoint for programmatic user creation from backend API.
 * Uses relaxed validation rules compared to the standard Fleetbase user creation.
 *
 * Endpoint: POST /int/v1/reeup/users
 */
class ReeupUserController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'user';

    /**
     * Create a new user with relaxed validation for programmatic access.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        try {
            // Extract user data from nested payload
            $userData = $request->input('user', []);

            // Relaxed validation rules (no ExcludedWords, flexible name format)
            $validator = Validator::make($userData, [
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users', 'email')->whereNull('deleted_at')
                ],
                'phone' => [
                    'sometimes',
                    'nullable',
                    'regex:/^\+[0-9]+$/',
                    Rule::unique('users', 'phone')->whereNull('deleted_at')
                ],
                'password' => [
                    'required',
                    'confirmed',
                    'string',
                    Password::min(8)->mixedCase()->letters()->numbers()->symbols()
                ],
                'password_confirmation' => ['sometimes', 'string'],
                'company_uuid' => ['sometimes', 'string'],
                'role_uuid' => ['sometimes', 'string'],
                'timezone' => ['sometimes', 'string'],
                'type' => ['sometimes', 'string']
            ]);

            if ($validator->fails()) {
                Log::error("❌ [REEUP] User creation validation failed", [
                    'errors' => $validator->errors()->toArray(),
                    'payload' => array_merge($userData, [
                        'password' => '***MASKED***',
                        'password_confirmation' => '***MASKED***'
                    ])
                ]);

                return response()->json([
                    'errors' => $validator->errors()->toArray()
                ], 400);
            }

            // Check if user already exists (handle duplicates gracefully)
            $existingUser = User::where('email', $userData['email'])->first();
            if ($existingUser) {
                Log::info("⚠️  [REEUP] User already exists, returning existing user", [
                    'email' => $userData['email'],
                    'user_uuid' => $existingUser->uuid
                ]);

                // Try to create a token for the existing user
                $token = null;
                try {
                    if (isset($userData['password'])) {
                        // Attempt login with provided password
                        $credentials = [
                            'email' => $userData['email'],
                            'password' => $userData['password']
                        ];

                        if (auth()->attempt($credentials)) {
                            $token = auth()->user()->createToken('api-token')->plainTextToken;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("⚠️  [REEUP] Could not generate token for existing user", [
                        'error' => $e->getMessage()
                    ]);
                }

                return response()->json([
                    'user' => $existingUser,
                    'token' => $token,
                    'message' => 'User already exists'
                ], 200);
            }

            // Get company context
            $companyUuid = $userData['company_uuid'] ?? session('company');
            $company = Company::where('uuid', $companyUuid)->first();

            if (!$company) {
                Log::error("❌ [REEUP] Invalid company_uuid", [
                    'company_uuid' => $companyUuid
                ]);
                return response()->json([
                    'errors' => ['company' => ['Invalid company context']]
                ], 400);
            }

            // Generate username
            $username = Str::slug($userData['name'] . '_' . Str::random(4), '_');

            // Create user
            $user = User::create([
                'company_uuid' => $company->uuid,
                'name' => $userData['name'],
                'username' => $username,
                'email' => $userData['email'],
                'phone' => $userData['phone'] ?? null,
                'timezone' => $userData['timezone'] ?? date_default_timezone_get(),
                'ip_address' => $request->ip(),
                'status' => 'active',
                'type' => $userData['type'] ?? 'user'
            ]);

            // Set password (it's guarded from mass assignment)
            $user->password = Hash::make($userData['password']);
            $user->save();

            // Set user type
            $user->setUserType($userData['type'] ?? 'user');

            // Assign to company
            $roleUuid = $userData['role_uuid'] ?? null;
            $user->assignCompany($company, $roleUuid);

            // Assign role if provided
            if ($roleUuid) {
                $user->assignSingleRole($roleUuid);
            }

            Log::info("✅ [REEUP] User created successfully", [
                'user_uuid' => $user->uuid,
                'email' => $user->email,
                'company_uuid' => $company->uuid
            ]);

            // Create API token
            $token = $user->createToken('api-token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token
            ], 201);

        } catch (\Exception $e) {
            Log::error("❌ [REEUP] Failed to create user", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'errors' => ['server' => [$e->getMessage()]]
            ], 500);
        }
    }

    /**
     * Query users (standard Fleetbase query).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function query(Request $request)
    {
        $data = User::queryFromRequest($request);

        return response()->json($data);
    }

    /**
     * Find a specific user.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function find(Request $request, string $id)
    {
        $user = User::where('uuid', $id)->first();

        if (!$user) {
            return response()->json([
                'errors' => ['user' => ['User not found']]
            ], 404);
        }

        return response()->json(['user' => $user]);
    }
}
