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
     * The service which this controller belongs to.
     * CRITICAL: Required by FleetbaseController - getService() must return a string.
     * Missing this property causes TypeError when authorization checks call getService().
     *
     * @var string
     */
    public $service = 'iam';

    /**
     * Disable automatic CreateUserRequest validation.
     * We use our own relaxed validation in the create() method.
     *
     * @var string|null
     */
    public $createRequest = null;

    /**
     * Constructor - disable automatic FormRequest resolution.
     * FleetbaseController auto-resolves CreateUserRequest for 'user' resource,
     * but we need our own relaxed validation for programmatic access.
     */
    public function __construct()
    {
        // Don't call parent constructor to avoid auto-resolving UserModel and CreateUserRequest
        // We'll manually handle the User model in our methods
        $this->resource = 'user';
        $this->service = 'iam';  // CRITICAL: Required for getService() - prevents TypeError
        $this->createRequest = null;
    }

    /**
     * Create a new user with relaxed validation for programmatic access.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        try {
            // DEBUG: Log raw request to diagnose JSON parsing issues
            Log::info("🔍 [REEUP] Request debug", [
                'content_type' => $request->header('Content-Type'),
                'method' => $request->method(),
                'raw_content_length' => strlen($request->getContent()),
                'raw_content_preview' => substr($request->getContent(), 0, 500),
                'all_input' => array_merge($request->all(), [
                    'password' => isset($request->all()['password']) ? '***MASKED***' : null,
                    'password_confirmation' => isset($request->all()['password_confirmation']) ? '***MASKED***' : null,
                ]),
                'user_input_exists' => $request->has('user'),
            ]);

            // Extract user data using Fleetbase pattern: nested first, fallback to all, then raw JSON
            $userData = $request->input('user', []);

            if (empty($userData)) {
                // Fallback 1: Try all input (flat payload)
                $allInput = $request->all();
                if (!empty($allInput) && isset($allInput['name'])) {
                    $userData = $allInput;
                    Log::info("✅ [REEUP] Using flat payload structure");
                }
            }

            if (empty($userData) && $request->getContent()) {
                // Fallback 2: Try manual JSON parsing (handles Content-Type issues)
                Log::warning("⚠️  [REEUP] user input empty, trying raw JSON parse");
                $rawJson = json_decode($request->getContent(), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $userData = $rawJson['user'] ?? $rawJson;
                    Log::info("✅ [REEUP] Successfully parsed user data from raw JSON");
                } else {
                    Log::error("❌ [REEUP] JSON parse error: " . json_last_error_msg());
                }
            }

            // Relaxed validation rules (no ExcludedWords, flexible name format)
            // NOTE: Unique constraints removed for email/phone - we handle duplicates gracefully below
            $validator = Validator::make($userData, [
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => [
                    'required',
                    'email'
                ],
                'phone' => [
                    'sometimes',
                    'nullable',
                    'regex:/^\+[0-9]+$/'
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
                    'user_uuid' => $existingUser->uuid,
                    'email_verified_at' => $existingUser->email_verified_at
                ]);

                // CRITICAL FIX: ALWAYS ensure email_verified_at is set for existing users
                // Fleetbase's /int/v1/auth/login rejects non-admin users without email_verified_at
                // This must be done BEFORE any auth attempts to ensure the user can login via Fleetbase
                $needsSave = false;
                if (empty($existingUser->email_verified_at)) {
                    Log::info("✅ [REEUP] Setting email_verified_at for existing non-admin user (SELF-HEALING)");
                    $existingUser->email_verified_at = now();
                    $needsSave = true;
                }

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
                            // Save email_verified_at if it was updated
                            if ($needsSave) {
                                $existingUser->save();
                                $existingUser->refresh();
                                Log::info("✅ [REEUP] Saved email_verified_at for existing user");
                            }
                            $token = auth()->user()->createToken('api-token')->plainTextToken;
                            Log::info("✅ [REEUP] Existing user authenticated successfully");
                        } else {
                            // Password mismatch - reset the user's password
                            Log::warning("⚠️  [REEUP] Password mismatch for existing user, resetting password");
                            // Don't call Hash::make() - the User model mutator will hash it
                            $existingUser->password = $userData['password'];
                            // email_verified_at already set above
                            $existingUser->save();
                            $existingUser->refresh();

                            Log::info("✅ [REEUP] Password reset complete, attempting authentication");

                            // Try authentication again with new password
                            if (auth()->attempt($credentials)) {
                                $token = auth()->user()->createToken('api-token')->plainTextToken;
                                Log::info("✅ [REEUP] Authentication successful with new password");
                            } else {
                                Log::error("❌ [REEUP] Authentication still failed after password reset");
                            }
                        }
                    } else if ($needsSave) {
                        // No password provided but we still need to save email_verified_at
                        $existingUser->save();
                        $existingUser->refresh();
                        Log::info("✅ [REEUP] Saved email_verified_at (no password update needed)");
                    }
                } catch (\Exception $e) {
                    Log::warning("⚠️  [REEUP] Could not generate token for existing user", [
                        'error' => $e->getMessage()
                    ]);
                    // Still try to save email_verified_at even if token generation failed
                    if ($needsSave) {
                        try {
                            $existingUser->save();
                            Log::info("✅ [REEUP] Saved email_verified_at despite token error");
                        } catch (\Exception $saveError) {
                            Log::error("❌ [REEUP] Failed to save email_verified_at", ['error' => $saveError->getMessage()]);
                        }
                    }
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

            // Create user (WITHOUT password - will set it after all other operations)
            $user = User::create([
                'company_uuid' => $company->uuid,
                'name' => $userData['name'],
                'username' => $username,
                'email' => $userData['email'],
                'phone' => $userData['phone'] ?? null,
                'timezone' => $userData['timezone'] ?? date_default_timezone_get(),
                'ip_address' => $request->ip(),
                'status' => 'active'
                // NOTE: 'type' is guarded, set via setUserType() below
                // NOTE: 'password' is guarded, set after all operations to prevent being overwritten
            ]);

            // Set user type (calls save() internally)
            $user->setUserType($userData['type'] ?? 'user');

            // Assign to company (calls save() internally)
            $roleUuid = $userData['role_uuid'] ?? null;
            $user->assignCompany($company, $roleUuid);

            // Assign role if provided
            if ($roleUuid) {
                $user->assignSingleRole($roleUuid);
            }

            // Auto-verify REEUP users since they are created programmatically from our backend
            // This prevents the "not_verified" error for non-admin users
            $user->email_verified_at = now();

            // CRITICAL: Set password AFTER all other operations to prevent it being overwritten
            // Multiple save() calls from setUserType() and assignCompany() can cause password to be lost
            // Don't call Hash::make() - the User model mutator will hash it automatically
            $user->password = $userData['password'];
            $user->save();

            // Verify password was saved by reloading from database
            $user->refresh();
            if (empty($user->password)) {
                Log::error("❌ [REEUP] Password was not saved to database!", [
                    'user_uuid' => $user->uuid,
                    'email' => $user->email
                ]);
                throw new \Exception('Failed to save user password');
            }

            Log::info("✅ [REEUP] User created successfully with password", [
                'user_uuid' => $user->uuid,
                'email' => $user->email,
                'company_uuid' => $company->uuid,
                'password_saved' => !empty($user->password)
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
