<?php

/**
 * Layer that adds a "Social Sharing" tab to the user profile page.
 * Only visible to users viewing their own profile.
 * When on the social-sharing tab, replaces the default 404 content
 * with a form to manage per-user social media accounts.
 */
class qa_html_theme_layer extends qa_html_theme_base
{
    private ?int $profileUserId = null;
    private ?int $loginUserId = null;
    private bool $isOwnProfile = false;
    private bool $isSocialTab = false;
    private string $profileHandle = '';

    function doctype()
    {
        // Override the 404 status that user.php sets for unknown sub-pages
        if ($this->isSocialTab) {
            http_response_code(200);
        }
        parent::doctype();
    }

    function initialize()
    {
        parent::initialize();

        if (qa_request_part(0) !== 'user') {
            return;
        }

        $this->profileHandle = qa_request_part(1);
        if (empty($this->profileHandle)) {
            return;
        }

        $this->loginUserId = qa_get_logged_in_userid();
        if ($this->loginUserId === null) {
            return;
        }

        $this->profileUserId = qa_handle_to_userid($this->profileHandle);
        if ($this->profileUserId === null) {
            return;
        }

        $this->isOwnProfile = ((int)$this->profileUserId === (int)$this->loginUserId);
        if (!$this->isOwnProfile) {
            return;
        }

        $isOnSocialTab = (qa_request_part(2) === 'social-sharing');

        // Rebuild user sub-navigation with our custom tab appended
        $userNav = qa_user_sub_navigation($this->profileHandle, $isOnSocialTab ? 'social-sharing' : null, true);
        $userNav['social-sharing'] = [
            'label' => 'Social Sharing',
            'url' => qa_path_html('user/' . $this->profileHandle . '/social-sharing'),
        ];
        if ($isOnSocialTab) {
            $userNav['social-sharing']['selected'] = true;
        }
        $this->content['navigation']['sub'] = $userNav;

        if ($isOnSocialTab) {
            $this->isSocialTab = true;
            $this->processSocialSharingPage();
        }
    }

    /**
     * Replace the 404 content with our social sharing page.
     */
    private function processSocialSharingPage(): void
    {
        $pluginDir = QA_HTML_THEME_LAYER_DIRECTORY;
        require_once $pluginDir . 'SmpConstants.php';
        require_once $pluginDir . 'SmpPoster.php';

        $poster = new SmpPoster($pluginDir);
        $userId = (int)$this->loginUserId;
        $message = '';
        $saved = false;

        // Handle form submissions
        if (qa_is_http_post()) {
            if (!qa_check_form_security_code('smp-user-social', qa_post_text('code'))) {
                $message = 'Security verification failed. Please try again.';
            } else {
                $platforms = SmpConstants::getPlatforms();

                if (isset($_POST['smp_user_save'])) {
                    $this->saveUserSettings($poster, $userId, $platforms);
                    $saved = true;
                    $message = 'Settings saved.';
                }

                if (isset($_POST['smp_user_add_account'])) {
                    $platform = qa_post_text('smp_user_add_platform');
                    if (isset($platforms[$platform])) {
                        $this->addUserAccount($poster, $userId, $platform, $platforms[$platform]);
                        $saved = true;
                        $message = 'New ' . htmlspecialchars($platforms[$platform]['name'], ENT_QUOTES, 'UTF-8') . ' account added.';
                    }
                }

                if (isset($_POST['smp_user_delete_account'])) {
                    $delPlatform = qa_post_text('smp_user_del_platform');
                    $delIdx = (int)qa_post_text('smp_user_del_idx');
                    if (isset($platforms[$delPlatform])) {
                        $this->deleteUserAccount($poster, $userId, $delPlatform, $delIdx);
                        $saved = true;
                        $message = 'Account deleted.';
                    }
                }
            }
        }

        // Replace the 404 page content entirely
        $this->content['title'] = 'Social Sharing Settings';
        $this->content['error'] = '';
        unset($this->content['suggest_next']);
        $this->content['custom'] = $this->buildSocialSharingPage($poster, $userId, $message, $saved);
    }

    // ==================== Form Rendering ====================

    private function buildSocialSharingPage(SmpPoster $poster, int $userId, string $message, bool $saved): string
    {
        $platforms = SmpConstants::getPlatforms();
        $isEnabled = (bool)$poster->getUserSharingEnabled($userId);
        $securityCode = qa_get_form_security_code('smp-user-social');

        $html = '<style>
            .smp-user-wrap { max-width:800px; }
            .smp-user-section { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:20px; margin-bottom:16px; }
            .smp-user-section h3 { margin:0 0 12px; font-size:16px; color:#333; border-bottom:1px solid #eee; padding-bottom:8px; }
            .smp-field { margin:10px 0; display:flex; align-items:center; gap:10px; }
            .smp-field label { min-width:140px; font-weight:500; color:#555; font-size:13px; }
            .smp-field input[type=text], .smp-field input[type=url] {
                flex:1; padding:6px 10px; border:1px solid #ddd; border-radius:4px; font-size:13px; font-family:monospace;
            }
            .smp-field input[type=checkbox] { width:18px; height:18px; }
            .smp-platform-card { border:1px solid #e8e8e8; border-radius:6px; padding:14px; margin:10px 0; background:#fafafa; }
            .smp-platform-card h4 { margin:0 0 10px; color:#333; font-size:14px; }
            .smp-btn { padding:6px 16px; border:none; border-radius:4px; cursor:pointer; font-size:13px; }
            .smp-btn-primary { background:#4285f4; color:#fff; }
            .smp-btn-primary:hover { background:#3367d6; }
            .smp-btn-success { background:#34a853; color:#fff; }
            .smp-btn-success:hover { background:#2d8f47; }
            .smp-btn-danger { background:#ea4335; color:#fff; }
            .smp-btn-danger:hover { background:#cc3327; }
            .smp-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:500; }
            .smp-msg { padding:10px 14px; border-radius:4px; margin-bottom:14px; }
            .smp-msg-ok { background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; }
            .smp-msg-err { background:#fce4ec; color:#c62828; border:1px solid #f8bbd0; }
            .smp-info { background:#e3f2fd; border:1px solid #bbdefb; border-radius:4px; padding:10px 14px; margin-bottom:14px; color:#1565c0; font-size:13px; }
            .smp-add-row { display:flex; gap:8px; align-items:center; margin-top:12px; }
        </style>';

        $html .= '<div class="smp-user-wrap">';

        // Status message
        if (!empty($message)) {
            $cls = $saved ? 'smp-msg-ok' : 'smp-msg-err';
            $html .= '<div class="smp-msg ' . $cls . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $html .= '<div class="smp-info">';
        $html .= 'Configure your own social media accounts below. When enabled, your posts (questions, blogs) ';
        $html .= 'will automatically be shared to your configured accounts.';
        $html .= '</div>';

        $formAction = qa_path_html('user/' . $this->profileHandle . '/social-sharing');
        $html .= '<form method="post" action="' . $formAction . '">';
        $html .= '<input type="hidden" name="code" value="' . qa_html($securityCode) . '">';

        // Master toggle
        $html .= '<div class="smp-user-section">';
        $html .= '<h3>Sharing Settings</h3>';
        $html .= '<div class="smp-field">';
        $html .= '<label>Enable auto-sharing:</label>';
        $html .= '<input type="checkbox" name="smp_user_enabled" value="1"' . ($isEnabled ? ' checked' : '') . '>';
        $html .= '<span style="color:#666;font-size:12px;">When enabled, your new posts will be shared to your configured accounts below.</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Per-platform account sections
        foreach ($platforms as $platformId => $platformInfo) {
            $accounts = $poster->getUserAccounts($userId, $platformId);
            $platformName = htmlspecialchars($platformInfo['name'], ENT_QUOTES, 'UTF-8');

            $html .= '<div class="smp-user-section">';
            $html .= '<h3>' . $platformName;
            $html .= ' <span style="font-size:12px;color:#888;">(' . count($accounts) . ' account' . (count($accounts) !== 1 ? 's' : '') . ')</span>';
            $html .= '</h3>';

            if (!empty($accounts)) {
                foreach ($accounts as $idx => $account) {
                    $acctName = htmlspecialchars($account['name'] ?? ($platformName . ' ' . ($idx + 1)), ENT_QUOTES, 'UTF-8');
                    $isAcctEnabled = !empty($account['enabled']);

                    $html .= '<div class="smp-platform-card">';
                    $html .= '<h4>' . $acctName;
                    if ($isAcctEnabled) {
                        $html .= ' <span class="smp-badge" style="background:#e8f5e9;color:#2e7d32;">Active</span>';
                    } else {
                        $html .= ' <span class="smp-badge" style="background:#f5f5f5;color:#999;">Disabled</span>';
                    }
                    $html .= '</h4>';

                    // Account name
                    $html .= '<div class="smp-field">';
                    $html .= '<label>Name:</label>';
                    $html .= '<input type="text" name="smp_uacct_name_' . $platformId . '_' . $idx . '" value="' . $acctName . '">';
                    $html .= '</div>';

                    // Enabled checkbox
                    $html .= '<div class="smp-field">';
                    $html .= '<label>Enabled:</label>';
                    $html .= '<input type="checkbox" name="smp_uacct_enabled_' . $platformId . '_' . $idx . '" value="1"' . ($isAcctEnabled ? ' checked' : '') . '>';
                    $html .= '</div>';

                    // Credential fields
                    $creds = $account['credentials'] ?? [];
                    foreach ($platformInfo['fields'] as $fi => $fieldKey) {
                        $fieldLabel = htmlspecialchars($platformInfo['labels'][$fi] ?? $fieldKey, ENT_QUOTES, 'UTF-8');
                        $credVal = $creds[$fieldKey] ?? '';
                        $html .= '<div class="smp-field">';
                        $html .= '<label>' . $fieldLabel . ':</label>';
                        $html .= '<input type="text" name="smp_uacct_cred_' . $platformId . '_' . $idx . '_' . $fieldKey . '" value="' . htmlspecialchars($credVal, ENT_QUOTES, 'UTF-8') . '">';
                        $html .= '</div>';
                    }

                    // Delete button
                    $html .= '<div style="margin-top:8px;text-align:right;">';
                    $html .= '<input type="hidden" class="smp-del-platform" value="' . htmlspecialchars($platformId, ENT_QUOTES, 'UTF-8') . '">';
                    $html .= '<input type="hidden" class="smp-del-idx" value="' . $idx . '">';
                    $html .= '<button type="submit" name="smp_user_delete_account" value="1" class="smp-btn smp-btn-danger smp-del-btn" ';
                    $html .= 'onclick="return smpConfirmDelete(this)">';
                    $html .= 'Delete</button>';
                    $html .= '</div>';

                    $html .= '</div>'; // card
                }
            } else {
                $html .= '<p style="color:#999;font-size:13px;">No ' . $platformName . ' accounts configured.</p>';
            }

            // Add account button
            $html .= '<div class="smp-add-row">';
            $html .= '<button type="submit" name="smp_user_add_account" value="1" class="smp-btn smp-btn-success smp-add-btn" ';
            $html .= 'data-platform="' . htmlspecialchars($platformId, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '+ Add ' . $platformName . ' Account</button>';
            $html .= '</div>';

            $html .= '</div>'; // section
        }

        // Hidden fields for actions
        $html .= '<input type="hidden" name="smp_user_add_platform" value="">';
        $html .= '<input type="hidden" name="smp_user_del_platform" value="">';
        $html .= '<input type="hidden" name="smp_user_del_idx" value="">';

        // Save button
        $html .= '<div style="margin:20px 0; text-align:center;">';
        $html .= '<button type="submit" name="smp_user_save" value="1" class="smp-btn smp-btn-primary" style="padding:10px 40px;font-size:15px;">Save All Settings</button>';
        $html .= '</div>';

        $html .= '</form>';
        $html .= '</div>'; // wrap

        // JavaScript for button actions
        $html .= '<script>
        document.querySelectorAll(".smp-add-btn").forEach(function(btn) {
            btn.addEventListener("click", function() {
                document.querySelector("[name=smp_user_add_platform]").value = this.dataset.platform;
            });
        });
        function smpConfirmDelete(btn) {
            if (!confirm("Delete this account?")) return false;
            var card = btn.closest(".smp-platform-card");
            var p = card.querySelector(".smp-del-platform").value;
            var i = card.querySelector(".smp-del-idx").value;
            document.querySelector("[name=smp_user_del_platform]").value = p;
            document.querySelector("[name=smp_user_del_idx]").value = i;
            return true;
        }
        </script>';

        return $html;
    }

    // ==================== Data Operations ====================

    private function saveUserSettings(SmpPoster $poster, int $userId, array $platforms): void
    {
        $enabled = (bool)qa_post_text('smp_user_enabled');
        $poster->setUserSharingEnabled($userId, $enabled);

        foreach ($platforms as $platformId => $platformInfo) {
            $existing = $poster->getUserAccounts($userId, $platformId);

            foreach ($existing as $idx => &$account) {
                $nameVal = qa_post_text('smp_uacct_name_' . $platformId . '_' . $idx);
                if ($nameVal !== null) {
                    $account['name'] = $nameVal;
                }
                $account['enabled'] = (bool)qa_post_text('smp_uacct_enabled_' . $platformId . '_' . $idx);

                foreach ($platformInfo['fields'] as $fieldKey) {
                    $credVal = qa_post_text('smp_uacct_cred_' . $platformId . '_' . $idx . '_' . $fieldKey);
                    if ($credVal !== null) {
                        $account['credentials'][$fieldKey] = $credVal;
                    }
                }
            }
            unset($account);

            $poster->saveUserAccounts($userId, $platformId, $existing);
        }
    }

    private function addUserAccount(SmpPoster $poster, int $userId, string $platformId, array $platformInfo): void
    {
        $accounts = $poster->getUserAccounts($userId, $platformId);

        $newAccount = [
            'id' => SmpConstants::generateAccountId($platformId),
            'name' => $platformInfo['name'] . ' Account ' . (count($accounts) + 1),
            'enabled' => false,
            'credentials' => [],
        ];

        foreach ($platformInfo['fields'] as $fieldKey) {
            $newAccount['credentials'][$fieldKey] = '';
        }

        $accounts[] = $newAccount;
        $poster->saveUserAccounts($userId, $platformId, $accounts);
    }

    private function deleteUserAccount(SmpPoster $poster, int $userId, string $platformId, int $index): void
    {
        $accounts = $poster->getUserAccounts($userId, $platformId);
        if (isset($accounts[$index])) {
            unset($accounts[$index]);
            $accounts = array_values($accounts);
        }
        $poster->saveUserAccounts($userId, $platformId, $accounts);
    }
}
