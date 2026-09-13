<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppEmbeddedSignupService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{account:\App\Models\WhatsAppAccount,phone_numbers:array<int,\App\Models\WhatsAppPhoneNumber>}
     */
    public function connectWorkspace(Workspace $workspace, array $payload): array
    {
        $code = trim((string) ($payload['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('Meta code is required.');
        }

        $sessionInfo = is_array($payload['session_info'] ?? null) ? $payload['session_info'] : [];
        $accessToken = $this->exchangeCodeForAccessToken($code);
        $businessAccount = $this->resolveBusinessAccount($accessToken, $sessionInfo);
        $phoneNumbers = $this->resolvePhoneNumbers($accessToken, $businessAccount['id'] ?? null, $sessionInfo);

        if (($businessAccount['id'] ?? null) === null) {
            throw new RuntimeException('Unable to resolve WhatsApp business account id from Meta response.');
        }

        /** @var array{account:\App\Models\WhatsAppAccount,phone_numbers:array<int,\App\Models\WhatsAppPhoneNumber>} $result */
        $result = DB::transaction(function () use ($workspace, $businessAccount, $phoneNumbers, $sessionInfo): array {
            $existingAccount = WhatsAppAccount::withoutGlobalScopes()
                ->where('business_account_id', (string) $businessAccount['id'])
                ->first();

            if ($existingAccount && (int) $existingAccount->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('This WhatsApp Business Account is already connected to another workspace.');
            }

            $accountMetadata = is_array($existingAccount?->metadata) ? $existingAccount->metadata : [];
            $accountMetadata = array_merge($accountMetadata, [
                'channel_source' => 'whatsapp',
                'connected_via' => 'embedded_signup',
                'connected_at' => now()->toISOString(),
                'graph_api_version' => (string) config('whatsapp.api_version'),
                'signup_session' => Arr::only($sessionInfo, ['phone_number_id', 'waba_id', 'business_id']),
            ]);

            if ($existingAccount) {
                $existingAccount->update([
                    'workspace_id' => $workspace->id,
                    'app_id' => (string) config('whatsapp.meta_app_id'),
                    'display_name' => (string) ($businessAccount['name'] ?? ('WABA '.$businessAccount['id'])),
                    'status' => 'connected',
                    'metadata' => $accountMetadata,
                ]);
                $account = $existingAccount;
            } else {
                $account = WhatsAppAccount::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'business_account_id' => (string) $businessAccount['id'],
                    'app_id' => (string) config('whatsapp.meta_app_id'),
                    'display_name' => (string) ($businessAccount['name'] ?? ('WABA '.$businessAccount['id'])),
                    'status' => 'connected',
                    'metadata' => $accountMetadata,
                ]);
            }

            $persistedPhoneNumbers = [];
            foreach ($phoneNumbers as $phone) {
                $phoneId = trim((string) ($phone['id'] ?? ''));
                if ($phoneId === '') {
                    continue;
                }

                $existingPhone = WhatsAppPhoneNumber::withoutGlobalScopes()
                    ->where('phone_number_id', $phoneId)
                    ->first();
                if ($existingPhone && (int) $existingPhone->workspace_id !== (int) $workspace->id) {
                    throw new RuntimeException('One of the WhatsApp phone numbers is already connected to another workspace.');
                }

                if ($existingPhone) {
                    $existingPhone->update([
                        'workspace_id' => $workspace->id,
                        'whats_app_account_id' => $account->id,
                        'display_phone_number' => (string) ($phone['display_phone_number'] ?? $phoneId),
                        'verified_name' => (string) ($phone['verified_name'] ?? ''),
                        'status' => 'connected',
                    ]);
                    $persistedPhoneNumbers[] = $existingPhone;
                } else {
                    $persistedPhoneNumbers[] = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
                        'workspace_id' => $workspace->id,
                        'whats_app_account_id' => $account->id,
                        'phone_number_id' => $phoneId,
                        'display_phone_number' => (string) ($phone['display_phone_number'] ?? $phoneId),
                        'verified_name' => (string) ($phone['verified_name'] ?? ''),
                        'status' => 'connected',
                    ]);
                }
            }

            if ($persistedPhoneNumbers !== []) {
                $persistedIds = collect($persistedPhoneNumbers)->pluck('id')->all();
                WhatsAppPhoneNumber::withoutGlobalScopes()
                    ->where('whats_app_account_id', $account->id)
                    ->whereNotIn('id', $persistedIds)
                    ->update(['status' => 'disconnected']);
            }

            return [
                'account' => $account->fresh('phoneNumbers'),
                'phone_numbers' => $persistedPhoneNumbers,
            ];
        });

        return $result;
    }

    private function exchangeCodeForAccessToken(string $code): string
    {
        $appId = (string) config('whatsapp.meta_app_id');
        $appSecret = (string) config('whatsapp.meta_app_secret');

        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException('Missing META_APP_ID or META_APP_SECRET.');
        }

        $query = [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $code,
        ];

        $redirectUri = trim((string) config('whatsapp.embedded_signup_redirect_uri'));
        if ($redirectUri !== '') {
            $query['redirect_uri'] = $redirectUri;
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(20)
            ->get('https://graph.facebook.com/'.config('whatsapp.api_version').'/oauth/access_token', $query);

        if ($response->failed()) {
            throw new RuntimeException('Meta token exchange failed with HTTP '.$response->status().'.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException('Meta token exchange did not return an access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $sessionInfo
     * @return array{id:string,name:?string}
     */
    private function resolveBusinessAccount(string $accessToken, array $sessionInfo): array
    {
        $sessionWabaId = trim((string) ($sessionInfo['waba_id'] ?? ''));
        if ($sessionWabaId !== '') {
            return [
                'id' => $sessionWabaId,
                'name' => $this->fetchBusinessAccountName($accessToken, $sessionWabaId),
            ];
        }

        $meResponse = $this->graphGet('me', $accessToken, [
            'fields' => 'id,name,whatsapp_business_accounts{id,name}',
        ]);

        $accounts = $meResponse['whatsapp_business_accounts']['data'] ?? [];
        if (! is_array($accounts) || $accounts === []) {
            throw new RuntimeException('No WhatsApp business accounts returned from Meta.');
        }

        $firstAccount = $accounts[0] ?? [];
        $wabaId = trim((string) ($firstAccount['id'] ?? ''));
        if ($wabaId === '') {
            throw new RuntimeException('Meta response did not include a WhatsApp business account id.');
        }

        return [
            'id' => $wabaId,
            'name' => $firstAccount['name'] ?? null,
        ];
    }

    private function fetchBusinessAccountName(string $accessToken, string $wabaId): ?string
    {
        $response = $this->graphGet($wabaId, $accessToken, ['fields' => 'id,name']);

        $name = $response['name'] ?? null;

        return $name !== null ? (string) $name : null;
    }

    /**
     * @param  array<string, mixed>  $sessionInfo
     * @return array<int, array<string, mixed>>
     */
    private function resolvePhoneNumbers(string $accessToken, ?string $wabaId, array $sessionInfo): array
    {
        if (! $wabaId) {
            return [];
        }

        $response = $this->graphGet($wabaId.'/phone_numbers', $accessToken, [
            'fields' => 'id,display_phone_number,verified_name',
            'limit' => 200,
        ]);

        $numbers = $response['data'] ?? [];
        if (! is_array($numbers)) {
            $numbers = [];
        }

        $sessionPhoneId = trim((string) ($sessionInfo['phone_number_id'] ?? ''));
        if ($sessionPhoneId === '') {
            return $numbers;
        }

        $filtered = collect($numbers)
            ->filter(fn ($entry) => trim((string) ($entry['id'] ?? '')) === $sessionPhoneId)
            ->values()
            ->all();

        if ($filtered === []) {
            $filtered[] = [
                'id' => $sessionPhoneId,
                'display_phone_number' => $sessionPhoneId,
                'verified_name' => null,
            ];
        }

        return $filtered;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function graphGet(string $path, string $accessToken, array $query = []): array
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(20)
            ->get('https://graph.facebook.com/'.config('whatsapp.api_version').'/'.ltrim($path, '/'), $query);

        if ($response->failed()) {
            throw new RuntimeException('Meta Graph API request failed for path "'.$path.'" with HTTP '.$response->status().'.');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
