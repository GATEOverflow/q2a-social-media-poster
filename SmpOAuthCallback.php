<?php

/**
 * Page module to handle OAuth2 callbacks for Google and LinkedIn.
 * Google redirects here with ?code=...&state=smp_google_oauth_N
 * LinkedIn redirects here with ?code=...&state=smp_linkedin_oauth_N
 * We exchange the code for tokens and redirect to the admin page.
 */
class SmpOAuthCallback
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function suggest_requests()
    {
        return [
            [
                'title' => 'SMP OAuth Callback',
                'request' => 'smp-oauth-callback',
                'nav' => null,
            ],
        ];
    }

    function match_request($request)
    {
        return $request === 'smp-oauth-callback';
    }

    function process_request($request)
    {
        // Only super admins may use this
        if (qa_get_logged_in_level() < QA_USER_LEVEL_SUPER) {
            header('HTTP/1.1 403 Forbidden');
            echo 'Access denied.';
            return;
        }

        $code = qa_get('code');

        // Q2A consumes $_GET['state'] in qa_load_state() and stores it in $qa_state global
        global $qa_state;
        $state = $qa_state;

        if (empty($code) || empty($state)) {
            $this->redirectToAdmin('oauth_error', 'Missing OAuth parameters.');
            return;
        }

        require_once $this->directory . 'SmpConstants.php';

        if (strpos($state, 'smp_google_oauth_') === 0) {
            $this->handleGoogleOAuth($code, $state);
        } elseif (strpos($state, 'smp_linkedin_oauth_') === 0) {
            $this->handleLinkedInOAuth($code, $state);
        } else {
            $this->redirectToAdmin('oauth_error', 'Invalid OAuth state parameter.');
        }
    }

    private function handleGoogleOAuth(string $code, string $state): void
    {
        $accountIdx = (int)substr($state, strlen('smp_google_oauth_'));

        $accounts = $this->getAccountsForPlatform(SmpConstants::PLATFORM_YOUTUBE);
        if (!isset($accounts[$accountIdx])) {
            $this->redirectToAdmin('oauth_error', 'Invalid account index.');
            return;
        }

        $creds = $accounts[$accountIdx]['credentials'] ?? [];
        $clientId = $creds['client_id'] ?? '';
        $clientSecret = $creds['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            $this->redirectToAdmin('oauth_error', 'YouTube account missing client ID or secret.');
            return;
        }

        $redirectUri = qa_path_absolute('smp-oauth-callback');

        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $data = $this->exchangeToken('https://oauth2.googleapis.com/token', $postData);
        if ($data === null) {
            return; // redirectToAdmin already called
        }

        if (empty($data['refresh_token'])) {
            $errorDetail = $data['error_description'] ?? $data['error'] ?? 'No refresh token in response';
            $this->redirectToAdmin('oauth_error', $errorDetail);
            return;
        }

        // Save the new refresh token
        $accounts[$accountIdx]['credentials']['refresh_token'] = $data['refresh_token'];
        $accounts[$accountIdx]['token_expiry_date'] = null;
        $accounts[$accountIdx]['token_expiry_source'] = 'none';
        $accounts[$accountIdx]['token_last_refreshed'] = date('Y-m-d H:i:s');
        qa_opt(
            SmpConstants::accountsOptionKey(SmpConstants::PLATFORM_YOUTUBE),
            json_encode(array_values($accounts))
        );

        $accountName = $accounts[$accountIdx]['name'] ?? ('YouTube Account ' . ($accountIdx + 1));
        $this->redirectToAdmin('oauth_success', 'Token refreshed for ' . $accountName);
    }

    private function handleLinkedInOAuth(string $code, string $state): void
    {
        $accountIdx = (int)substr($state, strlen('smp_linkedin_oauth_'));

        $accounts = $this->getAccountsForPlatform(SmpConstants::PLATFORM_LINKEDIN);
        if (!isset($accounts[$accountIdx])) {
            $this->redirectToAdmin('oauth_error', 'Invalid LinkedIn account index.');
            return;
        }

        $creds = $accounts[$accountIdx]['credentials'] ?? [];
        $clientId = $creds['client_id'] ?? '';
        $clientSecret = $creds['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            $this->redirectToAdmin('oauth_error', 'LinkedIn account missing client ID or secret.');
            return;
        }

        $redirectUri = qa_path_absolute('smp-oauth-callback');

        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $data = $this->exchangeToken('https://www.linkedin.com/oauth/v2/accessToken', $postData);
        if ($data === null) {
            return; // redirectToAdmin already called
        }

        if (empty($data['access_token'])) {
            $errorDetail = $data['error_description'] ?? $data['error'] ?? 'No access token in response';
            $this->redirectToAdmin('oauth_error', $errorDetail);
            return;
        }

        // Save both access_token and refresh_token
        $accounts[$accountIdx]['credentials']['refresh_token'] = $data['refresh_token'] ?? '';
        $expiresIn = $data['expires_in'] ?? 5184000; // default ~60 days
        $accounts[$accountIdx]['token_expiry_date'] = date('Y-m-d', time() + (int)$expiresIn);
        $accounts[$accountIdx]['token_expiry_source'] = 'oauth';
        $accounts[$accountIdx]['token_last_refreshed'] = date('Y-m-d H:i:s');

        // Also store a runtime access_token for immediate use
        $accounts[$accountIdx]['credentials']['access_token'] = $data['access_token'];

        // Try to auto-detect author URN if not set
        if (empty($creds['author_urn'])) {
            $authorUrn = $this->fetchLinkedInAuthorUrn($data['access_token']);
            if ($authorUrn) {
                $accounts[$accountIdx]['credentials']['author_urn'] = $authorUrn;
            }
        }

        qa_opt(
            SmpConstants::accountsOptionKey(SmpConstants::PLATFORM_LINKEDIN),
            json_encode(array_values($accounts))
        );

        $accountName = $accounts[$accountIdx]['name'] ?? ('LinkedIn Account ' . ($accountIdx + 1));
        $this->redirectToAdmin('oauth_success', 'LinkedIn authenticated for ' . $accountName);
    }

    /**
     * Exchange an authorization code for tokens at the given endpoint.
     * Returns decoded response data, or null on failure (redirects to admin with error).
     */
    private function exchangeToken(string $tokenUrl, array $postData): ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            $this->redirectToAdmin('oauth_error', 'cURL error: ' . $curlError);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->redirectToAdmin('oauth_error', 'Invalid response from token endpoint.');
            return null;
        }

        return $data;
    }

    /**
     * Fetch the authenticated user's LinkedIn person URN.
     */
    private function fetchLinkedInAuthorUrn(string $accessToken): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.linkedin.com/v2/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!empty($data['sub'])) {
            return 'urn:li:person:' . $data['sub'];
        }
        return null;
    }

    private function getAccountsForPlatform(string $platform): array
    {
        $json = qa_opt(SmpConstants::accountsOptionKey($platform));
        return !empty($json) ? (json_decode($json, true) ?: []) : [];
    }

    private function redirectToAdmin(string $status, string $message): void
    {
        $url = qa_path_absolute('admin/plugins', [
            'smp_oauth' => $status,
            'smp_oauth_msg' => $message,
        ]);
        qa_redirect_raw($url);
    }
}
