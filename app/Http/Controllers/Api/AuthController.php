<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\User;
use App\Rules\SyrianPhoneNumber;
use App\Services\OtpService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone_number' => ['required', 'string', 'max:30', 'unique:users,phone_number', new SyrianPhoneNumber()],
            'password' => ['required', 'string', 'min:10', 'max:255', 'same:confirm_password'],
            'confirm_password' => ['required', 'string'],
        ]);
        unset($data['role']);
        $data['role'] = 'user';
        $data['language'] = $this->requestedLanguage($request) ?? 'ar';
        $data['id'] = (string) Str::uuid();
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password'], $data['confirm_password']);
        $user = User::create($data);

        $code = app(OtpService::class)->issue($user->email, $user->id, 'register');
        $this->sendOtp($user, $code);

        return ApiResponse::success('Registration successful. Please verify your email.', [
            'requires_verification' => true,
            'email' => $user->email,
            'otp_expires_minutes' => OtpService::TTL_MINUTES,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['sometimes', 'required_without:phone_number', 'email', 'max:255'],
            'phone_number' => ['sometimes', 'required_without:email', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $identifier = $request->input('email') ?? $request->input('phone_number');

        $user = User::where('email', $identifier)
            ->orWhere('phone_number', $identifier)
            ->first();

        $passwordValid = false;

        if ($user) {
            try {
                $passwordValid = Hash::check($data['password'], (string) $user->password_hash);
            } catch (\RuntimeException $e) {
                $passwordValid = false;
            }
        }

        if (! $user || ! $passwordValid) {
            return ApiResponse::error('Invalid credentials', null, 401);
        }

        $language = $this->requestedLanguage($request);
        if ($language && $user->language !== $language) {
            $user->update(['language' => $language]);
        }

        if ($user->email_verified_at === null && ! in_array($user->role, ['admin', 'dealer'], true)) {
            try {
                $code = app(OtpService::class)->issue($user->email, $user->id, 'register');
                $this->sendOtp($user, $code);
            } catch (\Throwable $e) {
                Log::warning('Failed to resend OTP on login', ['email' => $user->email]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Email not verified.',
                'errors' => [[
                    'location' => 'body',
                    'path' => 'email',
                    'msg' => 'Please verify your email with the OTP we sent you.',
                ]],
                'data' => [
                    'requires_verification' => true,
                    'email' => $user->email,
                ],
            ], 403);
        }

        return $this->authPayload($user, 'Login successful');
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return ApiResponse::error('Invalid or expired verification code', null, 400);
        }

        if ($user->email_verified_at !== null) {
            return $this->authPayload($user, 'Email already verified');
        }

        $otp = app(OtpService::class)->verify($user->email, $data['otp'], 'register');

        if (! $otp) {
            return ApiResponse::error('Invalid or expired verification code', null, 400);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return $this->authPayload($user, 'Email verified successfully');
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return ApiResponse::success('If the email exists, a new code has been sent.');
        }

        if ($user->email_verified_at !== null) {
            return ApiResponse::success('Email already verified');
        }

        $code = app(OtpService::class)->issue($user->email, $user->id, 'register');
        $this->sendOtp($user, $code);

        return ApiResponse::success('A new verification code has been sent', ['email' => $user->email]);
    }

    private function authPayload(User $user, string $message)
    {
        $access = $user->createToken('mobile', ['api'], now()->addDays((int) config('app.token_expiration_days', 30)))->plainTextToken;
        $refresh = $user->createToken('refresh', ['refresh'], now()->addDays((int) config('app.refresh_token_expiration_days', 30)))->plainTextToken;

        return ApiResponse::success($message, [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'role' => $user->role,
            'user' => $user,
        ]);
    }

    private function sendOtp(User $user, string $code): void
    {
        try {
            Mail::to($user->email)->send(new OtpMail($code, $user->first_name, OtpService::TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('Failed to send OTP email', ['email' => $user->email, 'error' => $e->getMessage()]);
        }
    }

    public function forgotPassword(Request $request) { $data=$request->validate(['email'=>['nullable','email','required_without:phone_number'],'phone_number'=>['nullable','string','max:30','required_without:email']]); $user=User::when($data['email']??null,fn($q,$v)=>$q->where('email',$v))->when(!($data['email']??null),fn($q)=>$q->where('phone_number',$data['phone_number']??''))->first(); if (!$user) return ApiResponse::success('Password reset token generated'); $token=Str::random(64); DB::table('password_resets')->where('user_id',$user->id)->update(['used'=>true]); DB::table('password_resets')->insert(['id'=>(string)Str::uuid(),'user_id'=>$user->id,'token'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]); return ApiResponse::success('Password reset token generated', app()->environment('testing') ? ['token'=>$token] : null); }
    public function resetPassword(Request $request) { $data=$request->validate(['token'=>['required','string'],'new_password'=>['required','string','min:10','max:255','same:confirm_password'],'confirm_password'=>['required','string','max:255']]); $reset=DB::table('password_resets')->where('token',hash('sha256',$data['token']))->where('used',false)->where('expires_at','>',now())->first(); if (!$reset) return ApiResponse::error('Invalid or expired reset token',null,400); $user=User::findOrFail($reset->user_id); DB::transaction(function () use ($user, $reset, $data) { $user->update(['password_hash'=>Hash::make($data['new_password'])]); $user->tokens()->delete(); DB::table('password_resets')->where('id',$reset->id)->update(['used'=>true,'updated_at'=>now()]); }); return ApiResponse::success('Password successfully reset'); }
    public function refreshToken(Request $request) { $data=$request->validate(['refresh_token'=>['required','string']]); $access=\Laravel\Sanctum\PersonalAccessToken::findToken($data['refresh_token']); if (!$access || !$access->can('refresh') || $access->expires_at?->isPast()) return ApiResponse::error('Invalid refresh token',null,401); $user=$access->tokenable; $access->delete(); return ApiResponse::success('Token refreshed',['access_token'=>$user->createToken('mobile',['api'],now()->addDays((int) config('app.token_expiration_days', 30)))->plainTextToken,'refresh_token'=>$user->createToken('refresh',['refresh'],now()->addDays((int) config('app.refresh_token_expiration_days', 30)))->plainTextToken,'token_type'=>'Bearer','role'=>$user->role]); }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return ApiResponse::success('Logout successful');
    }

    public function profile(Request $request)
    {
        return ApiResponse::success('Profile retrieved successfully', $request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone_number' => ['sometimes', 'string', 'max:30', 'unique:users,phone_number,'.$user->id, new SyrianPhoneNumber()],
            'password' => ['sometimes', 'string', 'min:10', 'max:255', 'same:confirm_password'],
            'confirm_password' => ['required_with:password', 'string'],
            'profile_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'language' => ['sometimes', 'string', 'in:ar,en'],
        ]);

        if ($request->hasFile('profile_image')) {
            $path = 'uploads/'.$request->file('profile_image')->hashName();
            $request->file('profile_image')->storeAs('uploads', basename($path), 'public');
            $data['profile_image'] = Storage::disk('public')->url($path);
        }

        $passwordChanged = isset($data['password']);
        if ($passwordChanged) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password'], $data['confirm_password']);

        $user->update($data);
        if ($passwordChanged) {
            $user->tokens()->delete();
        }

        return ApiResponse::success('Profile updated successfully', $user->fresh());
    }

    /**
     * Language sent by the client as body field, query param, or header.
     */
    private function requestedLanguage(Request $request): ?string
    {
        $language = $request->input('language')
            ?? $request->query('lang')
            ?? $request->header('X-Locale');

        return is_string($language) && in_array($language, ['ar', 'en'], true) ? $language : null;
    }
}