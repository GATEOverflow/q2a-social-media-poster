<?php

/**
 * Page module to handle Google OAuth2 callback.
 * Google redirects here with ?code=...&state=smp_google_oauth_N
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

        if (empty($code) || empty($state) || strpos($state, 'smp_google_oauth_') !== 0) {
            $this->redirectToAdmin('oauth_error', 'Missing or invalid OAuth parameters.');
            return;
        }

        $accountIdx = (int)substr($state, strlen('smp_google_oauth_'));

        require_once $this->directory . 'SmpConstants.php';

        $accounts = $this->getAccounts();
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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            $this->redirectToAdmin('oauth_error', 'cURL error: ' . $curlError);
            return;
        }

        $data = json_decode($response, true);

        if (empty($data['refresh_token'])) {
            $errorDetail = $data['error_description'] ?? $data['error'] ?? 'No refresh token in response (HTTP ' . $httpCode . ')';
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

    private function getAccounts(): array
    {
        $json = qa_opt(SmpConstants::accountsOptionKey(SmpConstants::PLATFORM_YOUTUBE));
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
