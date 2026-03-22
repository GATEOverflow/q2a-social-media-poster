<?php

/**
 * Admin page for Social Media Poster plugin.
 * Manages platform accounts, content type settings, and image options.
 */
class SmpAdmin
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function option_default($option)
    {
        require_once $this->directory . 'SmpConstants.php';
        $defaults = [
            SmpConstants::OPT_OPENAI_CONFIG => 'Create a short, engaging social media announcement for the following content. Keep it concise.',
            SmpConstants::OPT_INSTAGRAM_AUTO_IMAGE => '0',
            SmpConstants::OPT_YOUTUBE_AUTO_VIDEO => '0',
            SmpConstants::OPT_MANUAL_SHARE_LEVEL => (string)QA_USER_LEVEL_EDITOR,
            SmpConstants::OPT_IMAGE_WIDTH => '1080',
            SmpConstants::OPT_IMAGE_HEIGHT => '1080',
            SmpConstants::OPT_IMAGE_BG_COLOR => '#FFFFFF',
            SmpConstants::OPT_IMAGE_TEXT_COLOR => '#333333',
            SmpConstants::OPT_IMAGE_FONT_SIZE => '28',
            SmpConstants::OPT_IMAGE_LOGO_URL => '',
            SmpConstants::OPT_QOTD_ENABLED => '0',
            SmpConstants::OPT_QOTD_HOUR => '9',
            SmpConstants::OPT_QOTD_EXCLUDE_TAGS => '',
            SmpConstants::OPT_QOTD_CATEGORIES => '',
            SmpConstants::OPT_QUOTE_ENABLED => '0',
            SmpConstants::OPT_QUOTE_HOUR => '8',
            SmpConstants::OPT_QUOTE_PROMPT => '',
            SmpConstants::OPT_CRON_KEY => '',
        ];

        return $defaults[$option] ?? null;
    }

    function init_queries($tableslc)
    {
        $tablename = qa_db_add_table_prefix('smp_quotes');
        if (!in_array($tablename, $tableslc)) {
            return 'CREATE TABLE IF NOT EXISTS ^smp_quotes ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`quote_date` DATE NOT NULL, '
                . '`quote_text` TEXT NOT NULL, '
                . '`status` VARCHAR(10) NOT NULL DEFAULT \'pending\', '
                . 'PRIMARY KEY (`id`), '
                . 'KEY `idx_date_status` (`quote_date`, `status`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return [];
    }

    function admin_form(&$qa_content)
    {
        require_once $this->directory . 'SmpConstants.php';

        $saved = false;
        $message = '';

        $saveAll = qa_clicked('smp_save_all');

        // Handle manual Meta token refresh
        if (qa_clicked('smp_refresh_meta_tokens')) {
            require_once $this->directory . 'SmpPoster.php';
            $poster = new SmpPoster($this->directory);
            $refreshResults = $poster->autoRefreshMetaTokens();
            $message = 'Meta token refresh: ' . $this->formatRefreshResults($refreshResults);
        }

        // Handle manual Google token refresh
        if (qa_clicked('smp_refresh_google_tokens')) {
            require_once $this->directory . 'SmpPoster.php';
            $poster = new SmpPoster($this->directory);
            $refreshResults = $poster->autoRefreshGoogleTokens();
            $message = 'Google token refresh: ' . $this->formatRefreshResults($refreshResults);
        }

        // Show Google OAuth callback result
        $oauthStatus = qa_get('smp_oauth');
        if ($oauthStatus === 'oauth_success') {
            $message = htmlspecialchars(qa_get('smp_oauth_msg') ?? 'Token updated.', ENT_QUOTES, 'UTF-8');
            $saved = true;
        } elseif ($oauthStatus === 'oauth_error') {
            $message = 'OAuth error: ' . htmlspecialchars(qa_get('smp_oauth_msg') ?? 'Unknown error.', ENT_QUOTES, 'UTF-8');
        }

        // Handle form submissions
        if (qa_clicked('smp_save_general') || $saveAll) {
            $this->saveGeneralSettings();
            $saved = true;
        }
        if (qa_clicked('smp_save_content_types') || $saveAll) {
            $this->saveContentTypeSettings();
            $saved = true;
        }
        if (qa_clicked('smp_save_image_settings') || $saveAll) {
            $this->saveImageSettings();
            $saved = true;
        }
        if (qa_clicked('smp_save_daily_settings') || $saveAll) {
            $this->saveDailySettings();
            $saved = true;
        }

        // Quote bank actions
        if (qa_clicked('smp_generate_quote_bank')) {
            require_once $this->directory . 'SmpPoster.php';
            $poster = new SmpPoster($this->directory);
            $startDate = qa_post_text('smp_quote_bank_start') ?: date('Y-m-d');
            $count = max(10, min(200, (int)(qa_post_text('smp_quote_bank_count') ?: 100)));
            $bank = $poster->generateQuoteBank($startDate, $count);
            if (!empty($bank)) {
                $poster->saveQuoteBank($bank);
                $message = count($bank) . ' quotes generated starting from ' . htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') . '.';
                $saved = true;
            } else {
                $message = 'Failed to generate quotes. Check your OpenAI API key.';
            }
        }
        if (qa_clicked('smp_replace_quote')) {
            require_once $this->directory . 'SmpPoster.php';
            $poster = new SmpPoster($this->directory);
            $replaceId = (int)qa_post_text('smp_replace_quote_id');
            if ($replaceId > 0) {
                $newQuote = $poster->generateSingleQuote();
                if ($newQuote) {
                    $poster->replaceQuoteById($replaceId, $newQuote);
                    $message = 'Quote #' . $replaceId . ' replaced.';
                    $saved = true;
                } else {
                    $message = 'Failed to generate replacement quote.';
                }
            }
        }
        if (qa_clicked('smp_edit_quote')) {
            require_once $this->directory . 'SmpPoster.php';
            $poster = new SmpPoster($this->directory);
            $editId = (int)qa_post_text('smp_edit_quote_id');
            $editText = qa_post_text('smp_edit_quote_text');
            if ($editId > 0 && !empty(trim($editText))) {
                $poster->editQuoteById($editId, trim($editText));
                $message = 'Quote #' . $editId . ' updated.';
                $saved = true;
            }
        }
        if (qa_clicked('smp_save_category_routing') || $saveAll) {
            $this->saveCategoryRouting();
            $saved = true;
        }
        if ($saveAll) {
            foreach (SmpConstants::getPlatforms() as $pid => $pinfo) {
                if (!empty($this->getAccounts($pid))) {
                    $this->saveAccountSettings($pid, $pinfo);
                }
            }
            $saved = true;
        }
        if (!$saveAll) {
            // Check for per-platform account actions
            foreach (SmpConstants::getPlatforms() as $platformId => $platformInfo) {
                if (qa_clicked('smp_add_account_' . $platformId)) {
                    $message = $this->addAccount($platformId, $platformInfo);
                    break;
                }
                if (qa_clicked('smp_save_accounts_' . $platformId)) {
                    $this->saveAccountSettings($platformId, $platformInfo);
                    $saved = true;
                    break;
                }

                // Check for delete buttons on individual accounts
                $accounts = $this->getAccounts($platformId);
                foreach ($accounts as $idx => $account) {
                    if (qa_clicked('smp_delete_account_' . $platformId . '_' . $idx)) {
                        $message = $this->deleteAccount($platformId, $idx);
                        break 2;
                    }
                }
            }
        }

        // Build the form
        $fields = [];
        $buttons = [];

        $isSuperAdmin = qa_get_logged_in_level() >= QA_USER_LEVEL_SUPER;

        // ========== SECTION: General / OpenAI Settings ==========
        $fields['section_general'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #4285f4;padding-bottom:5px;color:#4285f4;">General Settings</h2>',
        ];

        $currentKey = qa_opt(SmpConstants::OPT_OPENAI_KEY);
        if ($isSuperAdmin) {
            $fields['openai_key'] = [
                'label' => 'OpenAI API Key:',
                'type' => 'text',
                'value' => $currentKey,
                'tags' => 'NAME="smp_openai_key" SIZE="60" placeholder="sk-..."',
                'note' => 'Shared across plugins via <code>qa-openai-api-key</code> option. Changes here will affect other plugins using this key.',
            ];
        } else {
            $maskedKey = !empty($currentKey) ? substr($currentKey, 0, 5) . str_repeat('•', 20) : '<em>not set</em>';
            $fields['openai_key'] = [
                'type' => 'static',
                'label' => 'OpenAI API Key: ' . $maskedKey,
                'note' => 'Only super admins can view and edit API keys.',
            ];
        }

        $fields['openai_config'] = [
            'label' => 'OpenAI System Prompt:',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_OPENAI_CONFIG),
            'tags' => 'NAME="smp_openai_config" SIZE="80"',
            'note' => 'System instructions for generating social media posts',
        ];

        $userLevels = [
            QA_USER_LEVEL_BASIC => 'Registered Users',
            QA_USER_LEVEL_APPROVED => 'Approved Users',
            QA_USER_LEVEL_EXPERT => 'Experts',
            QA_USER_LEVEL_EDITOR => 'Editors (default)',
            QA_USER_LEVEL_MODERATOR => 'Moderators',
            QA_USER_LEVEL_ADMIN => 'Admins',
            QA_USER_LEVEL_SUPER => 'Super Admins',
        ];
        $currentLevel = (int)qa_opt(SmpConstants::OPT_MANUAL_SHARE_LEVEL);
        $levelOptions = '';
        foreach ($userLevels as $lvl => $label) {
            $sel = ($lvl === $currentLevel) ? ' selected' : '';
            $levelOptions .= '<option value="' . $lvl . '"' . $sel . '>' . $label . '</option>';
        }
        $fields['manual_share_level'] = [
            'type' => 'static',
            'label' => 'Manual share minimum level: <select name="smp_manual_share_level">' . $levelOptions . '</select>',
            'note' => 'Users at or above this level see a "Share to Social Media" section when creating posts. Only accounts not already auto-posting for that content type are shown.',
        ];

        $fields['btn_save_general'] = [
            'type' => 'static',
            'label' => '<button type="submit" name="smp_save_general" style="background:#4285f4;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">Save General Settings</button>',
        ];

        // ========== SECTION: Content Type -> Account Mapping ==========
        $fields['section_content'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #34a853;padding-bottom:5px;color:#34a853;">Content Type → Account Routing</h2>',
        ];

        $fields['content_desc'] = [
            'type' => 'static',
            'label' => 'Select which social media accounts to post to for each content type. Configure accounts in the Platform Accounts section below.',
        ];

        $allAccounts = $this->getAllAccountsWithIds();
        $contentTypes = SmpConstants::getAvailableContentTypes();
        $platforms = SmpConstants::getPlatforms();

        if (empty($allAccounts)) {
            $fields['no_accounts'] = [
                'type' => 'static',
                'label' => '<em style="color:#d93025;">No accounts configured yet. Add accounts in the Platform Accounts section below.</em>',
            ];
        } else {
            // Group accounts by platform for display
            $accountsByPlatform = [];
            foreach ($allAccounts as $accountId => $account) {
                $p = $account['_platform'];
                if (!isset($accountsByPlatform[$p])) {
                    $accountsByPlatform[$p] = [];
                }
                $accountsByPlatform[$p][$accountId] = $account;
            }

            foreach ($contentTypes as $ctId => $ctName) {
                $enabledIds = $this->getEnabledAccountIds($ctId);

                $fields['ct_header_' . $ctId] = [
                    'type' => 'static',
                    'label' => '<strong style="font-size:1.1em;color:#333;">' . htmlspecialchars($ctName, ENT_QUOTES, 'UTF-8') . '</strong>',
                ];

                foreach ($accountsByPlatform as $platformId => $platformAccounts) {
                    $platformName = $platforms[$platformId]['name'] ?? $platformId;
                    
                    foreach ($platformAccounts as $accountId => $account) {
                        $checked = in_array($accountId, $enabledIds);
                        $accountName = $account['name'] ?? 'Unnamed';
                        $enabled = !empty($account['enabled']);
                        $statusIcon = $enabled ? '✓' : '✗';
                        $statusColor = $enabled ? '#34a853' : '#d93025';
                        
                        $fields['ct_' . $ctId . '_' . $accountId] = [
                            'label' => '<span style="color:' . $statusColor . ';">' . $statusIcon . '</span> '
                                . htmlspecialchars($platformName, ENT_QUOTES, 'UTF-8') . ': '
                                . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8'),
                            'type' => 'checkbox',
                            'value' => $checked,
                            'tags' => 'NAME="smp_ct_' . $ctId . '_' . $accountId . '"' . ($enabled ? '' : ' disabled'),
                        ];
                    }
                }

                $fields['ct_spacer_' . $ctId] = [
                    'type' => 'static',
                    'label' => '<hr style="border:none;border-top:1px dashed #ccc;margin:8px 0;">',
                ];
            }
        }

        $fields['btn_save_content_types'] = [
            'type' => 'static',
            'label' => '<button type="submit" name="smp_save_content_types" style="background:#34a853;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">Save Content Type Settings</button>',
        ];

        // ========== SECTION: Category Routing (Collapsible) ==========
        $fields['section_category'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #ff9800;padding-bottom:5px;color:#ff9800;">Category-Specific Routing</h2>',
        ];

        // Get categories from Q2A
        $categories = qa_db_read_all_assoc(
            qa_db_query_sub('SELECT categoryid, title, parentid FROM ^categories ORDER BY position'),
            'categoryid'
        );

        $categoryRouting = $this->getCategoryRouting();

        // Build category tree structure
        $categoryTree = $this->buildCategoryTree($categories);

        // Build all category settings as single HTML block
        $catHtml = '<button type="button" onclick="var el=document.getElementById(\'smp-cat-settings\'); el.style.display = el.style.display === \'none\' ? \'block\' : \'none\'; this.textContent = this.textContent.includes(\'Show\') ? \'Hide category settings\' : \'Show category settings\';" style="background:#ff9800;color:#fff;border:none;padding:8px 16px;border-radius:3px;cursor:pointer;margin-bottom:10px;">Show category settings</button>';
        $catHtml .= '<div style="color:#666;font-size:13px;margin-top:5px;margin-bottom:15px;">Route questions from specific categories to additional or different accounts. (Optional)</div>';
        
        $catHtml .= '<div id="smp-cat-settings" style="display:none;border:1px solid #ffe0b2;padding:15px;border-radius:5px;background:#fff8e1;">';

        if (empty($categories)) {
            $catHtml .= '<em style="color:#888;">No categories found. Category routing is optional.</em>';
        } elseif (empty($allAccounts)) {
            $catHtml .= '<em style="color:#d93025;">Configure accounts first to enable category routing.</em>';
        } else {
            $catHtml .= $this->renderCategoryTree($categoryTree, $categories, $categoryRouting, $accountsByPlatform, $platforms, 0);
        }

        $catHtml .= '</div>';

        $fields['category_settings_block'] = [
            'type' => 'static',
            'label' => $catHtml,
        ];

        $fields['btn_save_category_routing'] = [
            'type' => 'static',
            'label' => '<button type="submit" name="smp_save_category_routing" style="background:#ff9800;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">Save Category Routing</button>',
        ];

        // ========== SECTION: Daily Posters (QOTD & Quote) ==========
        $fields['section_daily'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #9c27b0;padding-bottom:5px;color:#9c27b0;">Daily Posters</h2>',
        ];

        $fields['daily_desc'] = [
            'type' => 'static',
            'label' => 'Configure automatic daily postings. Select target accounts in the Content Type Settings above for "Question of the Day" and "Quote of the Day".',
        ];

        // Cron key
        $cronKey = qa_opt(SmpConstants::OPT_CRON_KEY);
        $cronUrl = $cronKey
            ? rtrim(qa_opt('site_url'), '/') . '/qa-plugin/social-media-poster/cron.php?key=' . urlencode($cronKey)
            : '';
        $cronCliPath = $cronKey
            ? 'php ' . __DIR__ . '/cron.php --key=' . $cronKey
            : '';
        $fields['cron_key'] = [
            'label' => 'Cron Secret Key:',
            'type' => 'text',
            'value' => $cronKey,
            'tags' => 'NAME="smp_cron_key" SIZE="40"',
            'note' => 'Set any random secret string to enable cron-based exact-time posting.',
        ];

        // Cron setup instructions
        if ($cronKey) {
            $cronHtml = '<div style="background:#f5f0ff;border:1px solid #d1c4e9;border-radius:6px;padding:14px 18px;margin:5px 0 10px;font-size:12px;">'
                . '<strong style="color:#6a1b9a;">Cron Job Setup</strong> &mdash; Add one of these to your crontab (<code>crontab -e</code>):<br><br>'
                . '<strong>Via HTTP (curl):</strong><br>'
                . '<code style="display:block;background:#fff;padding:6px 10px;border-radius:3px;margin:4px 0 10px;word-break:break-all;font-size:11px;">'
                . '0 * * * * curl -s &quot;' . htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') . '&quot; &gt; /dev/null 2&gt;&amp;1'
                . '</code>'
                . '<strong>Via PHP CLI:</strong><br>'
                . '<code style="display:block;background:#fff;padding:6px 10px;border-radius:3px;margin:4px 0 8px;word-break:break-all;font-size:11px;">'
                . '0 * * * * ' . htmlspecialchars($cronCliPath, ENT_QUOTES, 'UTF-8') . ' &gt; /dev/null 2&gt;&amp;1'
                . '</code>'
                . '<span style="color:#666;">Runs every hour. Posts only trigger at the configured hour if not already posted today.</span>'
                . '</div>';
        } else {
            $cronHtml = '<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 14px;margin:5px 0 10px;font-size:12px;color:#6d4c00;">'
                . 'Set a cron secret key above and save to see cron job setup instructions. '
                . 'Without cron, posts trigger on the first page load after the configured hour.'
                . '</div>';
        }
        $fields['cron_info'] = [
            'type' => 'static',
            'label' => $cronHtml,
        ];

        // Server & browser timezone info
        // Detect system timezone (PHP may default to UTC even if system is IST)
        $systemTz = @trim(shell_exec('cat /etc/timezone 2>/dev/null'))
            ?: @trim(shell_exec("timedatectl 2>/dev/null | grep 'Time zone' | awk '{print $3}'"))
            ?: date_default_timezone_get();
        $prevTz = date_default_timezone_get();
        date_default_timezone_set($systemTz);
        $serverTime = date('Y-m-d H:i:s');
        $serverAbbr = date('T');
        $serverUtcOffset = date('P');
        date_default_timezone_set($prevTz); // restore

        $tzNote = '<span style="font-size:12px;">'
            . 'Server: <strong>' . htmlspecialchars($serverTime) . '</strong> '
            . htmlspecialchars($systemTz) . ' (' . $serverAbbr . ', UTC' . $serverUtcOffset . ')'
            . ' &nbsp;|&nbsp; <span id="smp-user-tz"></span>'
            . '</span>'
            . '<script>'
            . '(function(){'
            . 'var d=new Date();'
            . 'var tz=Intl.DateTimeFormat().resolvedOptions().timeZone;'
            . 'var t=d.toLocaleString("sv-SE",{year:"numeric",month:"2-digit",day:"2-digit",hour:"2-digit",minute:"2-digit",second:"2-digit"});'
            . 'var o=d.getTimezoneOffset(),s=o<=0?"+":"-",h=Math.floor(Math.abs(o)/60),m=Math.abs(o)%60;'
            . 'var off="UTC"+s+("0"+h).slice(-2)+":"+("0"+m).slice(-2);'
            . 'document.getElementById("smp-user-tz").innerHTML="Your browser: <strong>"+t+"</strong> ("+tz+", "+off+")";'
            . '})();'
            . '</script>';
        $fields['timezone_info'] = [
            'type' => 'static',
            'label' => $tzNote,
        ];

        // -- QOTD --
        $fields['qotd_header'] = [
            'type' => 'static',
            'label' => '<h3 style="margin:12px 0 5px;color:#555;">📝 Question of the Day</h3>',
        ];

        $fields['qotd_enabled'] = [
            'label' => 'Enable Question of the Day:',
            'type' => 'checkbox',
            'value' => (int)qa_opt(SmpConstants::OPT_QOTD_ENABLED),
            'tags' => 'NAME="smp_qotd_enabled"',
            'note' => 'Posts a random MCQ question daily (excludes numerical-answers and multiple-selects)',
        ];

        $hourOptions = '';
        $selectedQotdHour = (int)(qa_opt(SmpConstants::OPT_QOTD_HOUR) ?: 9);
        for ($h = 0; $h < 24; $h++) {
            $sel = ($h === $selectedQotdHour) ? ' selected' : '';
            $hourOptions .= '<option value="' . $h . '"' . $sel . '>' . sprintf('%02d:00', $h) . '</option>';
        }
        $fields['qotd_hour'] = [
            'label' => 'Post at hour (server time):',
            'type' => 'static',
            'label' => 'Post at hour (server time): <select name="smp_qotd_hour">' . $hourOptions . '</select>',
            'note' => 'With cron: schedule one entry per hour. Without cron: triggers on first page load after this hour.',
        ];

        // Build category autocomplete widget
        $qotdCatIds = array_filter(array_map('trim', explode(',', qa_opt(SmpConstants::OPT_QOTD_CATEGORIES) ?: '')));
        $allCats = qa_db_read_all_assoc(
            qa_db_query_sub('SELECT categoryid, title, parentid FROM ^categories ORDER BY title'),
            'categoryid'
        );
        
        // Build category names with parent path for clarity
        $catNames = [];
        foreach ($allCats as $catId => $cat) {
            $path = $cat['title'];
            if (!empty($cat['parentid']) && isset($allCats[$cat['parentid']])) {
                $parent = $allCats[$cat['parentid']];
                $path = $parent['title'] . ' → ' . $path;
                if (!empty($parent['parentid']) && isset($allCats[$parent['parentid']])) {
                    $path = $allCats[$parent['parentid']]['title'] . ' → ' . $path;
                }
            }
            $catNames[$catId] = $path;
        }
        
        // Generate JavaScript data
        $catDataJs = json_encode($catNames, JSON_HEX_APOS | JSON_HEX_QUOT);
        
        // Build selected tags HTML
        $selectedTagsHtml = '';
        foreach ($qotdCatIds as $catId) {
            if (isset($catNames[$catId])) {
                $selectedTagsHtml .= '<span class="smp-cat-tag" data-id="' . (int)$catId . '">'
                    . htmlspecialchars($catNames[$catId], ENT_QUOTES, 'UTF-8')
                    . ' <span class="smp-cat-remove" onclick="smpRemoveCat(' . (int)$catId . ')">×</span></span>';
            }
        }
        
        $catWidgetHtml = '
<style>
.smp-cat-container { margin-top:5px; }
.smp-cat-tags { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:8px; }
.smp-cat-tag { background:#e3f2fd; border:1px solid #90caf9; border-radius:3px; padding:3px 8px; font-size:13px; display:inline-flex; align-items:center; }
.smp-cat-remove { margin-left:6px; cursor:pointer; color:#1976d2; font-weight:bold; }
.smp-cat-remove:hover { color:#d32f2f; }
.smp-cat-input-wrap { position:relative; }
.smp-cat-input { width:300px; padding:6px 10px; border:1px solid #ccc; border-radius:3px; font-size:13px; }
.smp-cat-dropdown { position:absolute; top:100%; left:0; width:300px; max-height:200px; overflow-y:auto; background:#fff; border:1px solid #ccc; border-top:none; border-radius:0 0 3px 3px; display:none; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
.smp-cat-option { padding:8px 10px; cursor:pointer; font-size:13px; }
.smp-cat-option:hover { background:#e3f2fd; }
.smp-cat-option.selected { background:#bbdefb; }
</style>
<div class="smp-cat-container">
    <div class="smp-cat-tags" id="smp-qotd-cat-tags">' . $selectedTagsHtml . '</div>
    <div class="smp-cat-input-wrap">
        <input type="text" class="smp-cat-input" id="smp-qotd-cat-input" placeholder="Type to search categories..." autocomplete="off">
        <div class="smp-cat-dropdown" id="smp-qotd-cat-dropdown"></div>
    </div>
    <input type="hidden" name="smp_qotd_categories" id="smp-qotd-cat-hidden" value="' . htmlspecialchars(implode(',', $qotdCatIds), ENT_QUOTES, 'UTF-8') . '">
    <div style="font-size:12px;color:#666;margin-top:5px;">Leave empty to include all categories</div>
</div>
<script>
(function(){
    var cats = ' . $catDataJs . ';
    var selected = ' . json_encode(array_map('intval', $qotdCatIds)) . ';
    var input = document.getElementById("smp-qotd-cat-input");
    var dropdown = document.getElementById("smp-qotd-cat-dropdown");
    var tagsDiv = document.getElementById("smp-qotd-cat-tags");
    var hidden = document.getElementById("smp-qotd-cat-hidden");
    
    function updateHidden() {
        hidden.value = selected.join(",");
    }
    
    window.smpRemoveCat = function(id) {
        selected = selected.filter(function(x) { return x !== id; });
        var tag = tagsDiv.querySelector("[data-id=\"" + id + "\"]");
        if (tag) tag.remove();
        updateHidden();
    };
    
    function addCat(id, name) {
        if (selected.indexOf(id) !== -1) return;
        selected.push(id);
        var tag = document.createElement("span");
        tag.className = "smp-cat-tag";
        tag.setAttribute("data-id", id);
        tag.innerHTML = name.replace(/</g,"&lt;") + " <span class=\"smp-cat-remove\" onclick=\"smpRemoveCat(" + id + ")\">×</span>";
        tagsDiv.appendChild(tag);
        updateHidden();
        input.value = "";
        dropdown.style.display = "none";
    }
    
    function showDropdown(filter) {
        dropdown.innerHTML = "";
        var count = 0;
        for (var id in cats) {
            if (selected.indexOf(parseInt(id)) !== -1) continue;
            if (filter && cats[id].toLowerCase().indexOf(filter.toLowerCase()) === -1) continue;
            var opt = document.createElement("div");
            opt.className = "smp-cat-option";
            opt.textContent = cats[id];
            opt.setAttribute("data-id", id);
            opt.onclick = (function(i, n) { return function() { addCat(parseInt(i), n); }; })(id, cats[id]);
            dropdown.appendChild(opt);
            count++;
            if (count >= 10) break;
        }
        dropdown.style.display = count > 0 ? "block" : "none";
    }
    
    input.addEventListener("focus", function() { showDropdown(input.value); });
    input.addEventListener("input", function() { showDropdown(input.value); });
    input.addEventListener("blur", function() { setTimeout(function() { dropdown.style.display = "none"; }, 200); });
})();
</script>';
        
        $fields['qotd_categories'] = [
            'label' => 'Restrict to categories:',
            'type' => 'static',
            'label' => 'Restrict to categories:' . $catWidgetHtml,
        ];

        $fields['qotd_exclude_tags'] = [
            'label' => 'Additional tags to exclude (comma-separated):',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_QOTD_EXCLUDE_TAGS),
            'tags' => 'NAME="smp_qotd_exclude_tags" SIZE="60"',
            'note' => 'numerical-answers and multiple-selects are always excluded',
        ];

        $lastQotdId = qa_opt(SmpConstants::OPT_QOTD_LAST_POSTID);
        $lastQotdRun = qa_opt(SmpConstants::OPT_QOTD_LAST_RUN) ?: 'Never';
        $fields['qotd_status'] = [
            'type' => 'static',
            'label' => '<em style="color:#888;">Last run: ' . htmlspecialchars($lastQotdRun, ENT_QUOTES, 'UTF-8')
                . ($lastQotdId ? ' | Last question ID: <a href="' . qa_q_path($lastQotdId, '', true) . '">' . (int)$lastQotdId . '</a>' : '')
                . '</em>',
        ];

        $fields['qotd_spacer'] = [
            'type' => 'static',
            'label' => '<hr style="border:none;border-top:1px dashed #ccc;margin:10px 0;">',
        ];

        // -- Quote of the Day --
        $fields['quote_header'] = [
            'type' => 'static',
            'label' => '<h3 style="margin:12px 0 5px;color:#555;">💬 Quote of the Day</h3>',
        ];

        $fields['quote_enabled'] = [
            'label' => 'Enable Quote of the Day:',
            'type' => 'checkbox',
            'value' => (int)qa_opt(SmpConstants::OPT_QUOTE_ENABLED),
            'tags' => 'NAME="smp_quote_enabled"',
            'note' => 'Posts an AI-generated motivational quote daily (requires OpenAI API key)',
        ];

        $quoteHourOptions = '';
        $selectedQuoteHour = (int)(qa_opt(SmpConstants::OPT_QUOTE_HOUR) ?: 8);
        for ($h = 0; $h < 24; $h++) {
            $sel = ($h === $selectedQuoteHour) ? ' selected' : '';
            $quoteHourOptions .= '<option value="' . $h . '"' . $sel . '>' . sprintf('%02d:00', $h) . '</option>';
        }
        $fields['quote_hour'] = [
            'type' => 'static',
            'label' => 'Post at hour (server time): <select name="smp_quote_hour">' . $quoteHourOptions . '</select>',
            'note' => 'With cron: schedule one entry per hour. Without cron: triggers on first page load after this hour.',
        ];

        $fields['quote_prompt'] = [
            'label' => 'Custom quote generation prompt:',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_QUOTE_PROMPT),
            'tags' => 'NAME="smp_quote_prompt" SIZE="80"',
            'note' => 'Leave blank for default prompt (motivational quotes for competitive exam students)',
        ];

        $lastQuoteRun = qa_opt(SmpConstants::OPT_QUOTE_LAST_RUN) ?: 'Never';
        $fields['quote_status'] = [
            'type' => 'static',
            'label' => '<em style="color:#888;">Last run: ' . htmlspecialchars($lastQuoteRun, ENT_QUOTES, 'UTF-8') . '</em>',
        ];

        $fields['btn_save_daily_settings'] = [
            'type' => 'static',
            'label' => '<button type="submit" name="smp_save_daily_settings" style="background:#9c27b0;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">Save Daily Poster Settings</button>',
        ];

        // -- Quote Bank --
        $fields['quote_bank_header'] = [
            'type' => 'static',
            'label' => '<h3 style="margin:16px 0 5px;color:#555;">Quote Bank</h3>',
        ];

        $quoteBankHtml = $this->buildQuoteBankUI();
        $fields['quote_bank'] = [
            'type' => 'custom',
            'html' => $quoteBankHtml,
        ];

        // ========== SECTION: Instagram & YouTube Media Settings ==========
        $fields['section_image'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #ea4335;padding-bottom:5px;color:#ea4335;">Instagram & YouTube Media Settings</h2>',
        ];

        $fields['instagram_auto_image'] = [
            'label' => 'Auto-convert text to image for Instagram:',
            'type' => 'checkbox',
            'value' => (int)qa_opt(SmpConstants::OPT_INSTAGRAM_AUTO_IMAGE),
            'tags' => 'NAME="smp_instagram_auto_image"',
            'note' => 'Generates an image from question text for Instagram posts (requires GD extension)',
        ];

        $fields['youtube_auto_video'] = [
            'label' => 'Auto-generate YouTube Shorts video from text:',
            'type' => 'checkbox',
            'value' => (int)qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO),
            'tags' => 'NAME="smp_youtube_auto_video"',
            'note' => 'Creates a short video from the text image and uploads as YouTube Shorts (requires ffmpeg)',
        ];

        $fields['image_width'] = [
            'label' => 'Image Width (px):',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_IMAGE_WIDTH) ?: '1080',
            'tags' => 'NAME="smp_image_width" SIZE="10"',
        ];

        $fields['image_height'] = [
            'label' => 'Image Height (px):',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_IMAGE_HEIGHT) ?: '1080',
            'tags' => 'NAME="smp_image_height" SIZE="10"',
        ];

        $fields['image_bg_color'] = [
            'label' => 'Background Color:',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_IMAGE_BG_COLOR) ?: '#FFFFFF',
            'tags' => 'NAME="smp_image_bg_color" SIZE="10"',
            'note' => 'Hex color (e.g. #FFFFFF)',
        ];

        $fields['image_text_color'] = [
            'label' => 'Text Color:',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_IMAGE_TEXT_COLOR) ?: '#333333',
            'tags' => 'NAME="smp_image_text_color" SIZE="10"',
            'note' => 'Hex color (e.g. #333333)',
        ];

        $fields['image_font_size'] = [
            'label' => 'Font Size:',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_IMAGE_FONT_SIZE) ?: '28',
            'tags' => 'NAME="smp_image_font_size" SIZE="10"',
        ];

        $fields['image_logo_url'] = [
            'label' => 'Logo File Path (server):',
            'type' => 'text',
            'value' => qa_opt(SmpConstants::OPT_IMAGE_LOGO_URL),
            'tags' => 'NAME="smp_image_logo_url" SIZE="80"',
            'note' => 'Absolute server path to logo image (e.g. /var/www/html/images/logo.png)',
        ];

        $fields['btn_save_image_settings'] = [
            'type' => 'static',
            'label' => '<button type="submit" name="smp_save_image_settings" style="background:#ea4335;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">Save Image Settings</button>',
        ];

        // ========== SECTION: Platform Accounts ==========
        $fields['section_accounts'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #fbbc05;padding-bottom:5px;color:#fbbc05;">Platform Accounts</h2>',
        ];

        foreach ($platforms as $platformId => $platformInfo) {
            $accounts = $this->getAccounts($platformId);

            $fields['platform_header_' . $platformId] = [
                'type' => 'static',
                'label' => '<h3 style="margin:15px 0 5px;color:#555;background:#f5f5f5;padding:8px 12px;border-radius:4px;">'
                    . htmlspecialchars($platformInfo['name'], ENT_QUOTES, 'UTF-8')
                    . ' <span style="font-size:12px;color:#888;">(' . count($accounts) . ' account' . (count($accounts) !== 1 ? 's' : '') . ')</span></h3>',
            ];

            if (empty($accounts)) {
                $fields['no_accounts_' . $platformId] = [
                    'type' => 'static',
                    'label' => '<em style="color:#999;margin-left:12px;">No accounts configured. Click "Add Account" below.</em>',
                ];
            } else {
                foreach ($accounts as $idx => $account) {
                    $acctLabel = htmlspecialchars($account['name'] ?? ('Account ' . ($idx + 1)), ENT_QUOTES, 'UTF-8');
                    $isDefault = !empty($account['is_default']);
                    $isEnabled = !empty($account['enabled']);
                    $expiryDate = $account['token_expiry_date'] ?? '';
                    $expiryBadge = '';
                    if (!empty($expiryDate)) {
                        try {
                            $expiry = new DateTime($expiryDate);
                            $today = new DateTime('today');
                            $diff = $today->diff($expiry);
                            $daysLeft = $diff->invert ? -$diff->days : $diff->days;
                            if ($daysLeft < 0) {
                                $expiryBadge = ' <span style="background:#ea4335;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">EXPIRED</span>';
                            } elseif ($daysLeft <= 2) {
                                $expiryBadge = ' <span style="background:#ea4335;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">EXPIRES IN ' . $daysLeft . 'd</span>';
                            } elseif ($daysLeft <= 7) {
                                $expiryBadge = ' <span style="background:#fbbc05;color:#333;padding:1px 6px;border-radius:3px;font-size:11px;">EXPIRES IN ' . $daysLeft . 'd</span>';
                            }
                        } catch (Exception $e) {
                            // invalid date, ignore
                        }
                    }
                    $statusBadge = $isDefault
                        ? ' <span style="background:#34a853;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">DEFAULT</span>'
                        : '';
                    $enabledBadge = $isEnabled
                        ? ' <span style="background:#4285f4;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">ENABLED</span>'
                        : ' <span style="background:#ccc;color:#666;padding:1px 6px;border-radius:3px;font-size:11px;">DISABLED</span>';

                    $fields['acct_header_' . $platformId . '_' . $idx] = [
                        'type' => 'static',
                        'label' => '<div style="margin:8px 0 4px 12px;padding:6px 10px;background:#fafafa;border-left:3px solid #4285f4;"><strong>'
                            . $acctLabel . '</strong>' . $statusBadge . $enabledBadge . $expiryBadge . '</div>',
                    ];

                    $fields['acct_name_' . $platformId . '_' . $idx] = [
                        'label' => '&nbsp;&nbsp;&nbsp;Account Name:',
                        'type' => 'text',
                        'value' => $account['name'] ?? '',
                        'tags' => 'NAME="smp_acct_name_' . $platformId . '_' . $idx . '" SIZE="40"',
                    ];

                    $fields['acct_enabled_' . $platformId . '_' . $idx] = [
                        'label' => '&nbsp;&nbsp;&nbsp;Enabled:',
                        'type' => 'checkbox',
                        'value' => $isEnabled,
                        'tags' => 'NAME="smp_acct_enabled_' . $platformId . '_' . $idx . '"',
                    ];

                    $fields['acct_default_' . $platformId . '_' . $idx] = [
                        'label' => '&nbsp;&nbsp;&nbsp;Default Account:',
                        'type' => 'checkbox',
                        'value' => $isDefault,
                        'tags' => 'NAME="smp_acct_default_' . $platformId . '_' . $idx . '"',
                    ];

                    // Credential fields
                    $creds = $account['credentials'] ?? [];
                    foreach ($platformInfo['fields'] as $fi => $fieldKey) {
                        $fieldLabel = $platformInfo['labels'][$fi] ?? $fieldKey;
                        $credVal = $creds[$fieldKey] ?? '';
                        if ($isSuperAdmin) {
                            $fields['acct_cred_' . $platformId . '_' . $idx . '_' . $fieldKey] = [
                                'label' => '&nbsp;&nbsp;&nbsp;' . htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') . ':',
                                'type' => 'text',
                                'value' => $credVal,
                                'tags' => 'NAME="smp_acct_cred_' . $platformId . '_' . $idx . '_' . $fieldKey . '" SIZE="70" style="font-family:monospace;font-size:12px;"',
                            ];
                        } else {
                            $masked = !empty($credVal) ? substr($credVal, 0, 4) . str_repeat('•', 20) : '<em>not set</em>';
                            $fields['acct_cred_' . $platformId . '_' . $idx . '_' . $fieldKey] = [
                                'type' => 'static',
                                'label' => '&nbsp;&nbsp;&nbsp;' . htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') . ': ' . $masked,
                            ];
                        }
                    }

                    // Token expiry date (auto-detected, read-only display)
                    $expiryVal = $account['token_expiry_date'] ?? '';
                    $expirySource = $account['token_expiry_source'] ?? '';
                    if (!empty($expiryVal)) {
                        $expiryDisplay = htmlspecialchars($expiryVal, ENT_QUOTES, 'UTF-8');
                    } elseif ($expirySource === 'none') {
                        $expiryDisplay = '<em style="color:#888;">Never expires</em>';
                    } else {
                        $expiryDisplay = '<em style="color:#888;">Not yet checked</em>';
                    }
                    $fields['acct_expiry_' . $platformId . '_' . $idx] = [
                        'type' => 'static',
                        'label' => '&nbsp;&nbsp;&nbsp;Token Expiry: ' . $expiryDisplay,
                        'note' => 'Auto-detected from platform API. Tokens are auto-refreshed when within 7 days of expiry.',
                    ];

                    // Show last refreshed time if available
                    $lastRefreshed = $account['token_last_refreshed'] ?? '';
                    if (!empty($lastRefreshed)) {
                        $fields['acct_refreshed_' . $platformId . '_' . $idx] = [
                            'type' => 'static',
                            'label' => '&nbsp;&nbsp;&nbsp;<em style="color:#888;font-size:12px;">Last refreshed: '
                                . htmlspecialchars($lastRefreshed, ENT_QUOTES, 'UTF-8') . '</em>',
                        ];
                    }

                    // Delete button inline for this account
                    $fields['acct_delete_btn_' . $platformId . '_' . $idx] = [
                        'type' => 'static',
                        'label' => '<button type="submit" name="smp_delete_account_' . $platformId . '_' . $idx
                            . '" onclick="return confirm(\'Are you sure you want to delete this account?\')"'
                            . ' style="background:#ea4335;color:#fff;border:none;padding:4px 12px;border-radius:3px;cursor:pointer;margin-left:12px;">Delete '
                            . $acctLabel . '</button>',
                    ];
                }
            }

            // Save + Add buttons inline for this platform
            $platformBtns = '';
            if (!empty($accounts)) {
                $platformBtns .= '<button type="submit" name="smp_save_accounts_' . $platformId
                    . '" style="background:#4285f4;color:#fff;border:none;padding:6px 16px;border-radius:3px;cursor:pointer;margin:2px;">'
                    . 'Save ' . htmlspecialchars($platformInfo['name'], ENT_QUOTES, 'UTF-8') . ' Accounts</button> ';
            }
            $platformBtns .= '<button type="submit" name="smp_add_account_' . $platformId
                . '" style="background:#34a853;color:#fff;border:none;padding:6px 16px;border-radius:3px;cursor:pointer;margin:2px;">'
                . '+ Add ' . htmlspecialchars($platformInfo['name'], ENT_QUOTES, 'UTF-8') . ' Account</button>';

            $fields['btn_platform_' . $platformId] = [
                'type' => 'static',
                'label' => $platformBtns,
            ];
        }

        // ========== SECTION: Token Management (at bottom) ==========
        $fields['section_tokens'] = [
            'type' => 'static',
            'label' => '<h2 style="margin:20px 0 10px;border-bottom:2px solid #e91e63;padding-bottom:5px;color:#e91e63;">Token Management</h2>',
        ];

        $fields['token_desc'] = [
            'type' => 'static',
            'label' => 'Tokens are checked daily and refreshed automatically when within 7 days of expiry. You can also trigger manual refresh below.',
        ];

        $lastRefresh = qa_opt(SmpConstants::OPT_LAST_TOKEN_REFRESH) ?: 'Never';
        $fields['token_status'] = [
            'type' => 'static',
            'label' => '<em style="color:#888;">Last auto-refresh: ' . htmlspecialchars($lastRefresh, ENT_QUOTES, 'UTF-8') . '</em>',
        ];

        // -- Meta Token Refresh --
        $fields['meta_token_header'] = [
            'type' => 'static',
            'label' => '<h3 style="margin:12px 0 5px;color:#555;">🔵 Meta (Facebook / Instagram / WhatsApp)</h3>',
        ];

        if ($isSuperAdmin) {
            $fields['meta_app_id'] = [
                'label' => 'Meta App ID:',
                'type' => 'text',
                'value' => qa_opt('smp_meta_app_id'),
                'tags' => 'NAME="smp_meta_app_id" SIZE="40" placeholder="Your Facebook App ID"',
                'note' => 'From <a href="https://developers.facebook.com/apps/" target="_blank">developers.facebook.com</a>.',
            ];
            $fields['meta_app_secret'] = [
                'label' => 'Meta App Secret:',
                'type' => 'text',
                'value' => qa_opt('smp_meta_app_secret'),
                'tags' => 'NAME="smp_meta_app_secret" SIZE="60" style="font-family:monospace;font-size:12px;" placeholder="Your Facebook App Secret"',
                'note' => 'Used to exchange short-lived tokens for long-lived ones.',
            ];
        } else {
            $maskedAppId = qa_opt('smp_meta_app_id');
            $maskedAppSecret = qa_opt('smp_meta_app_secret');
            $fields['meta_app_id'] = [
                'type' => 'static',
                'label' => 'Meta App ID: ' . (!empty($maskedAppId) ? substr($maskedAppId, 0, 4) . str_repeat('\u2022', 12) : '<em>not set</em>'),
            ];
            $fields['meta_app_secret'] = [
                'type' => 'static',
                'label' => 'Meta App Secret: ' . (!empty($maskedAppSecret) ? substr($maskedAppSecret, 0, 4) . str_repeat('\u2022', 20) : '<em>not set</em>'),
                'note' => 'Only super admins can view and edit secrets.',
            ];
        }

        // Show Meta account token statuses
        $metaPlatformNames = [
            SmpConstants::PLATFORM_FACEBOOK => 'Facebook',
            SmpConstants::PLATFORM_INSTAGRAM => 'Instagram',
            SmpConstants::PLATFORM_WHATSAPP => 'WhatsApp',
        ];
        foreach ($metaPlatformNames as $mpId => $mpName) {
            $mpAccounts = $this->getAccounts($mpId);
            foreach ($mpAccounts as $mi => $ma) {
                if (empty($ma['enabled'])) continue;
                $maName = $ma['name'] ?? $mpName;
                $maExpiry = $ma['token_expiry_date'] ?? '';
                $maRefreshed = $ma['token_last_refreshed'] ?? '';
                $statusHtml = $mpName . ': <strong>' . htmlspecialchars($maName, ENT_QUOTES, 'UTF-8') . '</strong>';
                if (!empty($maExpiry)) {
                    $statusHtml .= ' — Expires: ' . htmlspecialchars($maExpiry, ENT_QUOTES, 'UTF-8');
                } else {
                    $statusHtml .= ' — <em>Expiry unknown</em>';
                }
                if (!empty($maRefreshed)) {
                    $statusHtml .= ' (last refreshed: ' . htmlspecialchars($maRefreshed, ENT_QUOTES, 'UTF-8') . ')';
                }
                $fields['meta_status_' . $mpId . '_' . $mi] = [
                    'type' => 'static',
                    'label' => '&nbsp;&nbsp;&nbsp;' . $statusHtml,
                ];
            }
        }

        $fields['btn_refresh_meta'] = [
            'type' => 'static',
            'label' => '<button type="submit" name="smp_refresh_meta_tokens" style="background:#1877f2;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">🔄 Refresh Meta Tokens</button>'
                . ' <button type="submit" name="smp_save_general" style="background:#4285f4;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;margin-left:8px;">Save Meta Settings</button>',
        ];

        // -- Google/YouTube Token Refresh --
        $fields['google_token_header'] = [
            'type' => 'static',
            'label' => '<h3 style="margin:16px 0 5px;color:#555;">🔴 Google (YouTube)</h3>',
        ];

        $ytAccounts = $this->getAccounts(SmpConstants::PLATFORM_YOUTUBE);
        $needsGoogleOAuth = false;
        foreach ($ytAccounts as $yi => $ya) {
            if (empty($ya['enabled'])) continue;
            $yaName = $ya['name'] ?? ('YouTube Account ' . ($yi + 1));
            $yaExpiry = $ya['token_expiry_date'] ?? '';
            $yaRefreshed = $ya['token_last_refreshed'] ?? '';
            $yaSource = $ya['token_expiry_source'] ?? '';
            $creds = $ya['credentials'] ?? [];
            $hasRefreshToken = !empty($creds['refresh_token']);
            $hasClientId = !empty($creds['client_id']);
            $statusHtml = 'YouTube: <strong>' . htmlspecialchars($yaName, ENT_QUOTES, 'UTF-8') . '</strong>';

            if ($yaSource === 'invalid') {
                $statusHtml .= ' — <span style="color:#ea4335;font-weight:bold;">Invalid / Revoked</span>';
                $needsGoogleOAuth = true;
            } elseif ($hasClientId && !$hasRefreshToken) {
                $statusHtml .= ' — <span style="color:#e65100;font-weight:bold;">Not authenticated — click below to connect</span>';
                $needsGoogleOAuth = true;
            } elseif (!empty($yaExpiry)) {
                try {
                    $yExpObj = new DateTime($yaExpiry);
                    $yToday = new DateTime('today');
                    if ($yExpObj < $yToday) {
                        $statusHtml .= ' — <span style="color:#ea4335;font-weight:bold;">Expired: ' . htmlspecialchars($yaExpiry, ENT_QUOTES, 'UTF-8') . '</span>';
                        $needsGoogleOAuth = true;
                    } else {
                        $statusHtml .= ' — Expires: ' . htmlspecialchars($yaExpiry, ENT_QUOTES, 'UTF-8');
                    }
                } catch (Exception $e) {
                    $statusHtml .= ' — <span style="color:#ea4335;">Invalid date</span>';
                    $needsGoogleOAuth = true;
                }
            } elseif ($yaSource === 'none') {
                $statusHtml .= ' — <span style="color:#34a853;">Refresh token valid (never expires)</span>';
            } else {
                $statusHtml .= ' — <em>Not yet checked</em>';
            }
            if (!empty($yaRefreshed)) {
                $statusHtml .= ' (last checked: ' . htmlspecialchars($yaRefreshed, ENT_QUOTES, 'UTF-8') . ')';
            }
            $fields['google_status_' . $yi] = [
                'type' => 'static',
                'label' => '&nbsp;&nbsp;&nbsp;' . $statusHtml,
            ];
        }

        $googleBtns = '<button type="submit" name="smp_refresh_google_tokens" style="background:#ea4335;color:#fff;border:none;padding:6px 18px;border-radius:3px;cursor:pointer;">🔄 Validate Google Tokens</button>';

        // Show OAuth authenticate/renewal link if any YouTube account needs it
        if ($needsGoogleOAuth && $isSuperAdmin) {
            $googleBtns .= $this->buildGoogleOAuthRenewalHtml($ytAccounts);
        }

        $fields['btn_refresh_google'] = [
            'type' => 'static',
            'label' => $googleBtns,
        ];

        // ========== Save All button at bottom ==========
        $fields['section_save_all'] = [
            'type' => 'static',
            'label' => '<hr style="border:none;border-top:2px solid #333;margin:25px 0 15px;">',
        ];

        $buttons['save_all'] = [
            'label' => 'Save All Settings',
            'tags' => 'NAME="smp_save_all" style="background:#333;color:#fff;border:none;padding:8px 24px;border-radius:4px;cursor:pointer;font-size:14px;"',
        ];

        $ok = null;
        if ($saved) {
            $ok = 'Settings saved successfully.';
        } elseif (!empty($message)) {
            $ok = $message;
        }

        return [
            'ok' => $ok,
            'fields' => $fields,
            'buttons' => $buttons,
        ];
    }

    // ========== Helper Methods ==========

    /**
     * Format refresh results into a readable string.
     */
    private function formatRefreshResults(array $results): string
    {
        $lines = [];
        foreach ($results as $key => $r) {
            if (is_string($r)) {
                $lines[] = $r;
            } else {
                $line = ($r['platform'] ?? '') . ' / ' . ($r['account'] ?? '') . ': ' . ($r['status'] ?? '');
                if (!empty($r['new_expiry'])) $line .= ' (new expiry: ' . $r['new_expiry'] . ')';
                if (!empty($r['error'])) $line .= ' — ' . $r['error'];
                if (!empty($r['reason'])) $line .= ' — ' . $r['reason'];
                $lines[] = $line;
            }
        }
        return empty($lines) ? 'No accounts to refresh.' : implode(' | ', $lines);
    }

    /**
     * Build HTML for Google OAuth manual renewal flow.
     */
    private function buildGoogleOAuthRenewalHtml(array $ytAccounts): string
    {
        $html = '<div style="margin-top:12px;padding:12px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:5px;">';
        $html .= '<strong style="color:#e65100;">⚠ Google authentication required</strong><br>';
        $html .= '<p style="margin:8px 0;font-size:13px;">One or more YouTube accounts need to be authenticated with Google:</p>';

        $redirectUri = qa_path_absolute('smp-oauth-callback');
        $html .= '<div style="margin:8px 0;padding:8px;background:#e8f5e9;border:1px solid #c8e6c9;border-radius:4px;font-size:12px;">';
        $html .= '<strong>Required Redirect URI for Google Console:</strong> ';
        $html .= '<code style="background:#fff;padding:2px 6px;border:1px solid #ddd;border-radius:3px;user-select:all;">'
            . htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8') . '</code>';
        $html .= '<br><span style="color:#666;">Add this exact URI as an <em>Authorized redirect URI</em> in your '
            . '<a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> OAuth 2.0 client settings.</span>';
        $html .= '</div>';

        foreach ($ytAccounts as $yi => $ya) {
            if (empty($ya['enabled'])) continue;
            $creds = $ya['credentials'] ?? [];
            $clientId = $creds['client_id'] ?? '';
            if (empty($clientId)) continue;

            $yaName = $ya['name'] ?? ('YouTube Account ' . ($yi + 1));
            $hasToken = !empty($creds['refresh_token']);
            $btnLabel = $hasToken ? '🔑 Re-authenticate: ' : '🔑 Authenticate: ';

            $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => 'smp_google_oauth_' . $yi,
            ]);

            $html .= '<div style="margin:6px 0;">';
            $html .= '<a href="' . htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="background:#ea4335;color:#fff;padding:6px 14px;border-radius:3px;text-decoration:none;font-size:13px;">'
                . $btnLabel . htmlspecialchars($yaName, ENT_QUOTES, 'UTF-8') . '</a>';
            $html .= '</div>';
        }

        $html .= '<div style="margin-top:10px;font-size:12px;color:#666;">';
        $html .= 'Click the button above. After authenticating with Google, the token will be saved automatically and you will be redirected back here.';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }



    private function getAccounts(string $platform): array
    {
        $json = qa_opt(SmpConstants::accountsOptionKey($platform));
        if (empty($json)) {
            return [];
        }
        $accounts = json_decode($json, true);
        return is_array($accounts) ? $accounts : [];
    }

    private function saveAccounts(string $platform, array $accounts): void
    {
        qa_opt(SmpConstants::accountsOptionKey($platform), json_encode(array_values($accounts)));
    }

    /**
     * Get all accounts across all platforms with their IDs.
     * Ensures all accounts have an 'id' field.
     */
    private function getAllAccountsWithIds(): array
    {
        $result = [];
        $platforms = SmpConstants::getPlatforms();
        
        foreach ($platforms as $platformId => $platformInfo) {
            $accounts = $this->getAccounts($platformId);
            $modified = false;
            
            foreach ($accounts as $idx => &$account) {
                if (empty($account['id'])) {
                    $account['id'] = SmpConstants::generateAccountId($platformId);
                    $modified = true;
                }
                $account['_platform'] = $platformId;
                $account['_platform_name'] = $platformInfo['name'];
                $result[$account['id']] = $account;
            }
            
            if ($modified) {
                $this->saveAccounts($platformId, $accounts);
            }
        }
        
        return $result;
    }

    /**
     * Get enabled account IDs for a content type.
     */
    private function getEnabledAccountIds(string $contentType): array
    {
        $json = qa_opt(SmpConstants::contentAccountsOptionKey($contentType));
        if (empty($json)) {
            return [];
        }
        $ids = json_decode($json, true);
        return is_array($ids) ? $ids : [];
    }

    /**
     * Get category routing configuration.
     */
    private function getCategoryRouting(): array
    {
        $json = qa_opt(SmpConstants::OPT_CATEGORY_ROUTING);
        if (empty($json)) {
            return [];
        }
        $routing = json_decode($json, true);
        return is_array($routing) ? $routing : [];
    }

    /**
     * Build a tree structure from flat categories array.
     */
    private function buildCategoryTree(array $categories): array
    {
        $tree = [];
        
        // First pass: organize by parent
        foreach ($categories as $catId => $cat) {
            $parentId = $cat['parentid'] ?? null;
            if (empty($parentId)) {
                $tree[$catId] = [];
            }
        }
        
        // Second pass: add children (level 1)
        foreach ($categories as $catId => $cat) {
            $parentId = $cat['parentid'] ?? null;
            if (!empty($parentId) && isset($tree[$parentId])) {
                $tree[$parentId][$catId] = [];
            }
        }
        
        // Third pass: add grandchildren (level 2)
        foreach ($categories as $catId => $cat) {
            $parentId = $cat['parentid'] ?? null;
            if (!empty($parentId) && !isset($tree[$parentId])) {
                // Find parent's parent
                foreach ($tree as $rootId => $children) {
                    if (isset($children[$parentId])) {
                        $tree[$rootId][$parentId][$catId] = [];
                        break;
                    }
                }
            }
        }
        
        return $tree;
    }

    /**
     * Render category tree as HTML with nested indentation.
     */
    private function renderCategoryTree(array $tree, array $categories, array $routing, array $accountsByPlatform, array $platforms, int $level): string
    {
        $html = '';
        $indent = $level * 20;
        $icons = ['📁', '📂', '📄'];
        $icon = $icons[min($level, 2)];
        
        foreach ($tree as $catId => $children) {
            if (!isset($categories[$catId])) continue;
            
            $cat = $categories[$catId];
            $catName = $cat['title'];
            $catConfig = $routing[(string)$catId] ?? ['accounts' => [], 'also_default' => true];
            $catAccounts = $catConfig['accounts'] ?? [];
            $alsoDefault = $catConfig['also_default'] ?? true;
            
            $levelColor = $level === 0 ? '#333' : ($level === 1 ? '#555' : '#777');
            $bgColor = $level === 0 ? '#fff3e0' : 'transparent';
            
            $html .= '<div style="margin-left:' . $indent . 'px;padding:10px;margin-bottom:5px;border-radius:4px;background:' . $bgColor . ';">';
            $html .= '<strong style="color:' . $levelColor . ';font-size:' . (14 - $level) . 'px;">' . $icon . ' ' . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') . '</strong>';
            
            // Also default checkbox
            $checkedDefault = $alsoDefault ? ' checked' : '';
            $html .= '<div style="margin:8px 0 5px 20px;">';
            $html .= '<label style="font-size:13px;"><input type="checkbox" name="smp_cat_also_default_' . $catId . '" value="1"' . $checkedDefault . '> Also post to default Question accounts</label>';
            $html .= '</div>';
            
            // Account checkboxes
            $html .= '<div style="margin-left:20px;font-size:13px;">';
            foreach ($accountsByPlatform as $platformId => $platformAccounts) {
                $platformName = $platforms[$platformId]['name'] ?? $platformId;
                
                foreach ($platformAccounts as $accountId => $account) {
                    $enabled = !empty($account['enabled']);
                    if (!$enabled) continue;
                    
                    $checked = in_array($accountId, $catAccounts) ? ' checked' : '';
                    $accountName = $account['name'] ?? 'Unnamed';
                    
                    $html .= '<label style="display:inline-block;margin-right:15px;margin-bottom:3px;">';
                    $html .= '<input type="checkbox" name="smp_cat_' . $catId . '_' . $accountId . '" value="1"' . $checked . '> ';
                    $html .= htmlspecialchars($platformName, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8');
                    $html .= '</label>';
                }
            }
            $html .= '</div>';
            
            $html .= '</div>';
            
            // Render children recursively
            if (!empty($children)) {
                $html .= $this->renderCategoryTree($children, $categories, $routing, $accountsByPlatform, $platforms, $level + 1);
            }
        }
        
        return $html;
    }

    private function getEnabledPlatforms(string $contentType): array
    {
        $json = qa_opt(SmpConstants::contentPlatformsOptionKey($contentType));
        if (empty($json)) {
            return [];
        }
        $platforms = json_decode($json, true);
        return is_array($platforms) ? $platforms : [];
    }

    private function addAccount(string $platformId, array $platformInfo): string
    {
        $accounts = $this->getAccounts($platformId);

        $newAccount = [
            'id' => SmpConstants::generateAccountId($platformId),
            'name' => $platformInfo['name'] . ' Account ' . (count($accounts) + 1),
            'enabled' => false,
            'is_default' => empty($accounts), // First account is default
            'token_expiry_date' => '',
            'token_expiry_source' => '',
            'credentials' => [],
        ];

        foreach ($platformInfo['fields'] as $fieldKey) {
            $newAccount['credentials'][$fieldKey] = '';
        }

        $accounts[] = $newAccount;
        $this->saveAccounts($platformId, $accounts);

        return 'New ' . $platformInfo['name'] . ' account added. Fill in the credentials and save.';
    }

    private function deleteAccount(string $platformId, int $index): string
    {
        $accounts = $this->getAccounts($platformId);
        $name = $accounts[$index]['name'] ?? 'Account';
        $wasDefault = !empty($accounts[$index]['is_default']);

        unset($accounts[$index]);
        $accounts = array_values($accounts);

        // If we deleted the default and there are remaining accounts, make the first one default
        if ($wasDefault && !empty($accounts)) {
            $accounts[0]['is_default'] = true;
        }

        $this->saveAccounts($platformId, $accounts);

        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' account deleted.';
    }

    private function saveGeneralSettings(): void
    {
        $isSuperAdmin = qa_get_logged_in_level() >= QA_USER_LEVEL_SUPER;

        // Only super admins can change API keys and secrets
        if ($isSuperAdmin) {
            $newKey = qa_post_text('smp_openai_key');
            if ($newKey !== null) {
                qa_opt(SmpConstants::OPT_OPENAI_KEY, trim($newKey));
            }

            $metaAppId = qa_post_text('smp_meta_app_id');
            if ($metaAppId !== null) {
                qa_opt('smp_meta_app_id', trim($metaAppId));
            }
            $metaAppSecret = qa_post_text('smp_meta_app_secret');
            if ($metaAppSecret !== null) {
                qa_opt('smp_meta_app_secret', trim($metaAppSecret));
            }
        }

        qa_opt(SmpConstants::OPT_OPENAI_CONFIG, qa_post_text('smp_openai_config'));

        $manualLevel = qa_post_text('smp_manual_share_level');
        if ($manualLevel !== null) {
            qa_opt(SmpConstants::OPT_MANUAL_SHARE_LEVEL, (int)$manualLevel);
        }
    }

    private function saveContentTypeSettings(): void
    {
        $allAccounts = $this->getAllAccountsWithIds();
        $contentTypes = SmpConstants::getAvailableContentTypes();

        foreach ($contentTypes as $ctId => $ctName) {
            $enabledIds = [];
            foreach ($allAccounts as $accountId => $account) {
                if (qa_post_text('smp_ct_' . $ctId . '_' . $accountId)) {
                    $enabledIds[] = $accountId;
                }
            }
            qa_opt(SmpConstants::contentAccountsOptionKey($ctId), json_encode($enabledIds));
        }
    }

    private function saveImageSettings(): void
    {
        qa_opt(SmpConstants::OPT_INSTAGRAM_AUTO_IMAGE, (int)qa_post_text('smp_instagram_auto_image'));
        qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO, (int)qa_post_text('smp_youtube_auto_video'));

        $width = (int)qa_post_text('smp_image_width');
        $height = (int)qa_post_text('smp_image_height');
        qa_opt(SmpConstants::OPT_IMAGE_WIDTH, $width > 0 ? $width : 1080);
        qa_opt(SmpConstants::OPT_IMAGE_HEIGHT, $height > 0 ? $height : 1080);

        $bgColor = qa_post_text('smp_image_bg_color');
        if (preg_match('/^#[0-9A-Fa-f]{3,6}$/', $bgColor)) {
            qa_opt(SmpConstants::OPT_IMAGE_BG_COLOR, $bgColor);
        }

        $textColor = qa_post_text('smp_image_text_color');
        if (preg_match('/^#[0-9A-Fa-f]{3,6}$/', $textColor)) {
            qa_opt(SmpConstants::OPT_IMAGE_TEXT_COLOR, $textColor);
        }

        $fontSize = (int)qa_post_text('smp_image_font_size');
        qa_opt(SmpConstants::OPT_IMAGE_FONT_SIZE, $fontSize > 0 ? $fontSize : 28);

        qa_opt(SmpConstants::OPT_IMAGE_LOGO_URL, qa_post_text('smp_image_logo_url'));
    }

    private function saveDailySettings(): void
    {
        qa_opt(SmpConstants::OPT_QOTD_ENABLED, (int)qa_post_text('smp_qotd_enabled'));
        $qotdHour = (int)qa_post_text('smp_qotd_hour');
        qa_opt(SmpConstants::OPT_QOTD_HOUR, max(0, min(23, $qotdHour)));
        qa_opt(SmpConstants::OPT_QOTD_CATEGORIES, qa_post_text('smp_qotd_categories'));
        qa_opt(SmpConstants::OPT_QOTD_EXCLUDE_TAGS, qa_post_text('smp_qotd_exclude_tags'));

        qa_opt(SmpConstants::OPT_QUOTE_ENABLED, (int)qa_post_text('smp_quote_enabled'));
        $quoteHour = (int)qa_post_text('smp_quote_hour');
        qa_opt(SmpConstants::OPT_QUOTE_HOUR, max(0, min(23, $quoteHour)));
        qa_opt(SmpConstants::OPT_QUOTE_PROMPT, qa_post_text('smp_quote_prompt'));

        $cronKey = trim(qa_post_text('smp_cron_key') ?? '');
        qa_opt(SmpConstants::OPT_CRON_KEY, $cronKey);
    }

    private function saveCategoryRouting(): void
    {
        $allAccounts = $this->getAllAccountsWithIds();
        $categories = qa_db_read_all_assoc(
            qa_db_query_sub('SELECT categoryid FROM ^categories'),
            'categoryid'
        );

        $routing = [];
        foreach ($categories as $catId => $cat) {
            $catAccounts = [];
            foreach ($allAccounts as $accountId => $account) {
                if (qa_post_text('smp_cat_' . $catId . '_' . $accountId)) {
                    $catAccounts[] = $accountId;
                }
            }
            
            $alsoDefault = (bool)qa_post_text('smp_cat_also_default_' . $catId);
            
            // Only save config if there are category-specific accounts or also_default is false
            if (!empty($catAccounts) || !$alsoDefault) {
                $routing[(string)$catId] = [
                    'accounts' => $catAccounts,
                    'also_default' => $alsoDefault,
                ];
            }
        }

        qa_opt(SmpConstants::OPT_CATEGORY_ROUTING, json_encode($routing));
    }

    private function saveAccountSettings(string $platformId, array $platformInfo): void
    {
        $isSuperAdmin = qa_get_logged_in_level() >= QA_USER_LEVEL_SUPER;
        $accounts = $this->getAccounts($platformId);
        $newDefaultIdx = null;

        foreach ($accounts as $idx => &$account) {
            $account['name'] = qa_post_text('smp_acct_name_' . $platformId . '_' . $idx) ?: $account['name'];
            $account['enabled'] = (bool)qa_post_text('smp_acct_enabled_' . $platformId . '_' . $idx);

            $wantsDefault = (bool)qa_post_text('smp_acct_default_' . $platformId . '_' . $idx);
            if ($wantsDefault) {
                $newDefaultIdx = $idx;
            }

            // Only super admins can change credentials
            if ($isSuperAdmin) {
                foreach ($platformInfo['fields'] as $fieldKey) {
                    $val = qa_post_text('smp_acct_cred_' . $platformId . '_' . $idx . '_' . $fieldKey);
                    if ($val !== null) {
                        $account['credentials'][$fieldKey] = $val;
                    }
                }
            }

            // token_expiry_date is auto-detected; preserve existing value
        }
        unset($account);

        // Ensure only one default
        foreach ($accounts as $idx => &$acct) {
            $acct['is_default'] = ($idx === $newDefaultIdx);
        }
        unset($acct);

        // If no default was selected, make first enabled one default
        $hasDefault = false;
        foreach ($accounts as $acct) {
            if ($acct['is_default']) {
                $hasDefault = true;
                break;
            }
        }
        if (!$hasDefault && !empty($accounts)) {
            $accounts[0]['is_default'] = true;
        }

        $this->saveAccounts($platformId, $accounts);
    }

    // ==================== Quote Bank UI ====================

    private function buildQuoteBankUI(): string
    {
        require_once $this->directory . 'SmpPoster.php';
        $poster = new SmpPoster($this->directory);
        $bank = $poster->getQuoteBank();

        $today = date('Y-m-d');
        $defaultStart = empty($bank) ? $today : $today;
        $totalQuotes = count($bank);
        $pendingCount = 0;
        $postedCount = 0;
        foreach ($bank as $entry) {
            if ($entry['status'] === 'posted') $postedCount++;
            else $pendingCount++;
        }

        $html = '<style>
            .sqb-wrap { margin:10px 0; }
            .sqb-gen-row { display:flex; gap:10px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
            .sqb-gen-row label { font-weight:500; font-size:13px; color:#555; }
            .sqb-gen-row input { padding:5px 8px; border:1px solid #ccc; border-radius:3px; font-size:13px; }
            .sqb-gen-btn { background:#9c27b0; color:#fff; border:none; padding:8px 20px; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; }
            .sqb-gen-btn:hover { background:#7b1fa2; }
            .sqb-stats { background:#f5f5f5; padding:8px 14px; border-radius:4px; font-size:13px; color:#555; margin-bottom:10px; }
            .sqb-table { width:100%; border-collapse:collapse; font-size:12px; }
            .sqb-table th { background:#f1f3f4; padding:6px 8px; text-align:left; border-bottom:2px solid #ddd; font-size:11px; position:sticky; top:0; }
            .sqb-table td { padding:6px 8px; border-bottom:1px solid #eee; vertical-align:top; }
            .sqb-table tr:hover { background:#f8f9fa; }
            .sqb-table tr.sqb-posted td { color:#999; }
            .sqb-table tr.sqb-today { background:#e8f5e9 !important; }
            .sqb-table tr.sqb-past td { color:#bbb; }
            .sqb-badge { display:inline-block; padding:1px 6px; border-radius:8px; font-size:10px; font-weight:500; }
            .sqb-badge-pending { background:#fff3cd; color:#856404; }
            .sqb-badge-posted { background:#d4edda; color:#155724; }
            .sqb-badge-today { background:#e8f5e9; color:#2e7d32; }
            .sqb-btn { padding:2px 8px; border:1px solid #ccc; border-radius:3px; background:#fff; cursor:pointer; font-size:11px; }
            .sqb-btn:hover { background:#f0f0f0; }
            .sqb-btn-replace { color:#9c27b0; border-color:#9c27b0; }
            .sqb-btn-replace:hover { background:#f3e5f5; }
            .sqb-btn-edit { color:#1976d2; border-color:#1976d2; }
            .sqb-btn-edit:hover { background:#e3f2fd; }
            .sqb-quote-text { max-width:500px; line-height:1.4; }
            .sqb-edit-area { width:100%; min-height:60px; padding:4px 6px; border:1px solid #ccc; border-radius:3px; font-size:12px; font-family:inherit; resize:vertical; margin-top:4px; display:none; }
        </style>';

        $html .= '<div class="sqb-wrap">';

        // Generate controls
        $html .= '<div class="sqb-gen-row">';
        $html .= '<label>Start date:</label>';
        $html .= '<input type="date" name="smp_quote_bank_start" value="' . htmlspecialchars($defaultStart, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<label>Count:</label>';
        $html .= '<input type="number" name="smp_quote_bank_count" value="100" min="10" max="200" style="width:70px">';
        $html .= '<button type="submit" name="smp_generate_quote_bank" class="sqb-gen-btn" ';
        $html .= 'onclick="return confirm(\'This will replace ALL existing quotes in the bank. Continue?\')">';
        $html .= 'Generate Quote Bank</button>';
        $html .= '</div>';

        if ($totalQuotes > 0) {
            // Stats
            $html .= '<div class="sqb-stats">';
            $html .= 'Total: <strong>' . $totalQuotes . '</strong> quotes';
            $html .= ' &nbsp;|&nbsp; Pending: <strong>' . $pendingCount . '</strong>';
            $html .= ' &nbsp;|&nbsp; Posted: <strong>' . $postedCount . '</strong>';
            $firstDate = $bank[0]['date'] ?? '?';
            $lastDate = $bank[$totalQuotes - 1]['date'] ?? '?';
            $html .= ' &nbsp;|&nbsp; Range: ' . $firstDate . ' to ' . $lastDate;
            $html .= '</div>';

            // Hidden fields for replace/edit actions
            $html .= '<input type="hidden" name="smp_replace_quote_id" id="sqb_replace_id" value="">';
            $html .= '<input type="hidden" name="smp_edit_quote_id" id="sqb_edit_id" value="">';
            $html .= '<input type="hidden" name="smp_edit_quote_text" id="sqb_edit_text" value="">';

            // Quote table
            $html .= '<div style="max-height:600px;overflow-y:auto;border:1px solid #e0e0e0;border-radius:4px;">';
            $html .= '<table class="sqb-table">';
            $html .= '<thead><tr>';
            $html .= '<th style="width:30px">#</th>';
            $html .= '<th style="width:90px">Date</th>';
            $html .= '<th>Quote</th>';
            $html .= '<th style="width:60px">Status</th>';
            $html .= '<th style="width:120px">Actions</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($bank as $idx => $entry) {
                $entryId = (int)$entry['id'];
                $date = $entry['date'];
                $isToday = ($date === $today);
                $isPast = ($date < $today);
                $isPosted = ($entry['status'] === 'posted');

                $rowClass = '';
                if ($isToday) $rowClass = 'sqb-today';
                elseif ($isPosted) $rowClass = 'sqb-posted';
                elseif ($isPast) $rowClass = 'sqb-past';

                $html .= '<tr class="' . $rowClass . '" id="sqb-row-' . $entryId . '">';
                $html .= '<td>' . ($idx + 1) . '</td>';

                $dayName = date('D', strtotime($date));
                $html .= '<td>' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '<br><small style="color:#888">' . $dayName . '</small></td>';

                $quoteHtml = htmlspecialchars(mb_strimwidth($entry['quote'], 0, 200, '...'), ENT_QUOTES, 'UTF-8');
                $quoteFull = htmlspecialchars($entry['quote'], ENT_QUOTES, 'UTF-8');
                $html .= '<td class="sqb-quote-text">';
                $html .= '<span class="sqb-quote-display-' . $entryId . '" title="' . $quoteFull . '">' . $quoteHtml . '</span>';
                $html .= '<textarea class="sqb-edit-area" id="sqb-edit-area-' . $entryId . '">' . $quoteFull . '</textarea>';
                $html .= '</td>';

                // Status badge
                $html .= '<td>';
                if ($isToday) {
                    $html .= '<span class="sqb-badge sqb-badge-today">Today</span>';
                } elseif ($isPosted) {
                    $html .= '<span class="sqb-badge sqb-badge-posted">Posted</span>';
                } else {
                    $html .= '<span class="sqb-badge sqb-badge-pending">Pending</span>';
                }
                $html .= '</td>';

                // Actions
                $html .= '<td>';
                $html .= '<button type="submit" name="smp_replace_quote" value="1" class="sqb-btn sqb-btn-replace" ';
                $html .= 'onclick="document.getElementById(\'sqb_replace_id\').value=' . $entryId . ';return confirm(\'Replace this quote with a new AI-generated one?\')" ';
                $html .= 'title="Replace with new AI quote">Replace</button> ';
                $html .= '<button type="button" class="sqb-btn sqb-btn-edit" onclick="sqbToggleEdit(' . $entryId . ')" title="Edit manually">Edit</button>';
                $html .= '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div>';

            // JavaScript
            $html .= '<script>
            function sqbToggleEdit(id) {
                var area = document.getElementById("sqb-edit-area-" + id);
                var display = document.querySelector(".sqb-quote-display-" + id);
                if (area.style.display === "none" || area.style.display === "") {
                    area.style.display = "block";
                    display.style.display = "none";
                    area.focus();
                    // Add save on blur
                    area.onblur = function() {
                        var newText = area.value.trim();
                        if (newText && newText !== display.textContent) {
                            document.getElementById("sqb_edit_id").value = id;
                            document.getElementById("sqb_edit_text").value = newText;
                            // Submit the form
                            var btn = document.createElement("input");
                            btn.type = "hidden";
                            btn.name = "smp_edit_quote";
                            btn.value = "1";
                            area.closest("form").appendChild(btn);
                            area.closest("form").submit();
                        } else {
                            area.style.display = "none";
                            display.style.display = "";
                        }
                    };
                } else {
                    area.style.display = "none";
                    display.style.display = "";
                }
            }
            </script>';
        } else {
            $html .= '<p style="color:#999;font-size:13px;">No quotes in the bank. Click "Generate Quote Bank" to create quotes scheduled for the next 100 days.</p>';
        }

        $html .= '</div>';
        return $html;
    }
}
