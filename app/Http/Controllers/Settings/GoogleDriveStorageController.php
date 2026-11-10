<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Storage\GoogleDriveTokenProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleDriveStorageController extends Controller
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function redirect(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $clientId = trim((string) config('services.google_drive.client_id', ''));
        $clientSecret = trim((string) config('services.google_drive.client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Set GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET in .env before connecting Google Drive.');
        }

        $state = Str::random(40);
        $request->session()->put('google_drive_oauth_state', $state);

        $redirectUri = $this->redirectUri();
        $scope = (string) config('services.google_drive.scope', 'https://www.googleapis.com/auth/drive.file');

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope.' openid email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return redirect()->away(self::AUTH_URL.'?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        $expectedState = (string) $request->session()->pull('google_drive_oauth_state', '');
        $state = $request->string('state')->toString();

        if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Google Drive authorization state did not match. Please try again.');
        }

        if ($request->filled('error')) {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Google Drive authorization was cancelled.');
        }

        $code = $request->string('code')->trim()->toString();
        if ($code === '') {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Missing authorization code from Google.');
        }

        $clientId = trim((string) config('services.google_drive.client_id', ''));
        $clientSecret = trim((string) config('services.google_drive.client_secret', ''));

        try {
            $tokenResponse = Http::asForm()
                ->timeout(15)
                ->post(self::TOKEN_URL, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirectUri(),
                ]);
        } catch (Throwable) {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Could not exchange Google Drive authorization code.');
        }

        if (! $tokenResponse->successful()) {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Google Drive token exchange failed.');
        }

        $refreshToken = trim((string) $tokenResponse->json('refresh_token', ''));
        $accessToken = trim((string) $tokenResponse->json('access_token', ''));

        if ($refreshToken === '') {
            return redirect()
                ->route('instance-settings.platforms')
                ->with('error', 'Google did not return a refresh token. Reconnect and approve offline access.');
        }

        $email = null;
        if ($accessToken !== '') {
            try {
                $userResponse = Http::withToken($accessToken)->timeout(10)->get(self::USERINFO_URL);
                if ($userResponse->successful()) {
                    $email = $userResponse->json('email');
                }
            } catch (Throwable) {
                $email = null;
            }
        }

        GoogleDriveTokenProvider::storeConnectedAccount(
            refreshToken: $refreshToken,
            email: is_string($email) ? $email : null,
            rootFolderId: (string) config('services.google_drive.root_folder_id', 'root'),
        );

        return redirect()
            ->route('instance-settings.platforms')
            ->with('success', 'Google Drive storage connected.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isInstanceOwner(), 403);

        GoogleDriveTokenProvider::disconnect();

        return redirect()
            ->route('instance-settings.platforms')
            ->with('success', 'Google Drive storage disconnected.');
    }

    private function redirectUri(): string
    {
        $configured = trim((string) config('services.google_drive.redirect', ''));
        if ($configured !== '') {
            return $configured;
        }

        return route('instance-settings.storage.google.callback');
    }
}
