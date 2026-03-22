<?php

/**
 * Layer to add "Share to Social Media" checkboxes on post creation pages.
 * Shows only accounts that are NOT already auto-posting for the content type.
 * Restricted to users at or above the configured minimum level.
 */
class qa_html_theme_layer extends qa_html_theme_base
{
    /**
     * Determine the content type for the current page.
     * Returns null if not a supported creation page.
     */
    private function getContentType(): ?string
    {
        if ($this->template === 'ask') {
            return 'question';
        }

        $request = qa_request();
        $parts = explode('/', $request);

        // Blog creation: blog/new
        if (isset($parts[0]) && $parts[0] === 'blog' && isset($parts[1]) && $parts[1] === 'new') {
            return 'blog';
        }
        // Also check template for blog
        if ($this->template === 'blog-new') {
            return 'blog';
        }

        // Exam creation: create-exam or create-gate
        if (isset($parts[0]) && in_array($parts[0], ['create-exam', 'create-gate'])) {
            return 'exam';
        }

        return null;
    }

    /**
     * Get accounts available for manual sharing (enabled but not auto-posting for this content type).
     */
    private function getManualShareAccounts(string $contentType): array
    {
        require_once QA_HTML_THEME_LAYER_DIRECTORY . 'SmpConstants.php';
        require_once QA_HTML_THEME_LAYER_DIRECTORY . 'SmpPoster.php';

        $poster = new SmpPoster(QA_HTML_THEME_LAYER_DIRECTORY);
        $allAccounts = $poster->getAllAccountsById();
        $autoAccountIds = $poster->getEnabledAccountIds($contentType);
        $platforms = SmpConstants::getPlatforms();

        $manualAccounts = [];
        foreach ($allAccounts as $accountId => $account) {
            if (empty($account['enabled'])) {
                continue;
            }
            // Skip accounts that are already auto-posting for this content type
            if (in_array($accountId, $autoAccountIds)) {
                continue;
            }
            $platform = $account['_platform'] ?? '';
            $platformName = $platforms[$platform]['name'] ?? ucfirst($platform);
            $accountName = $account['name'] ?? $platformName;
            $manualAccounts[$accountId] = [
                'name' => $accountName,
                'platform' => $platformName,
            ];
        }

        return $manualAccounts;
    }

    /**
     * Check if the current user meets the minimum level for manual sharing.
     */
    private function userCanManualShare(): bool
    {
        if (!qa_is_logged_in()) {
            return false;
        }

        require_once QA_HTML_THEME_LAYER_DIRECTORY . 'SmpConstants.php';
        $minLevel = (int)qa_opt(SmpConstants::OPT_MANUAL_SHARE_LEVEL);
        if ($minLevel === 0) {
            $minLevel = QA_USER_LEVEL_EDITOR;
        }

        return qa_get_logged_in_level() >= $minLevel;
    }

    /**
     * Build the HTML for the share checkboxes.
     */
    private function buildShareHtml(string $contentType): string
    {
        $accounts = $this->getManualShareAccounts($contentType);
        if (empty($accounts)) {
            return '';
        }

        $html = '<div id="smp-manual-share" style="margin:12px 0;padding:12px;background:#f8f9fa;border:1px solid #dadce0;border-radius:6px;">';
        $html .= '<div style="font-weight:bold;margin-bottom:8px;color:#333;">📢 Share to Social Media</div>';

        foreach ($accounts as $accountId => $info) {
            $escapedId = htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($info['platform'] . ': ' . $info['name'], ENT_QUOTES, 'UTF-8');
            $html .= '<label style="display:block;margin:4px 0;cursor:pointer;">';
            $html .= '<input type="checkbox" name="smp_share[]" value="' . $escapedId . '" style="margin-right:6px;">';
            $html .= $label;
            $html .= '</label>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Inject checkboxes into the form fields on creation pages.
     */
    function main()
    {
        $contentType = $this->getContentType();

        if ($contentType !== null && $this->userCanManualShare()) {
            $shareHtml = $this->buildShareHtml($contentType);
            if (!empty($shareHtml)) {
                // Try to inject into Q2A form fields (works for ask, blog-new, create-exam)
                if (isset($this->content['form']['fields'])) {
                    $this->content['form']['fields']['smp_share'] = [
                        'type' => 'custom',
                        'html' => $shareHtml,
                    ];
                }
            }
        }

        parent::main();
    }

    /**
     * Fallback: inject via JS if form field injection didn't work (custom page modules).
     */
    function body_suffix()
    {
        $contentType = $this->getContentType();

        if ($contentType !== null && $contentType !== 'question' && $this->userCanManualShare()) {
            $shareHtml = $this->buildShareHtml($contentType);
            if (!empty($shareHtml)) {
                $jsHtml = json_encode($shareHtml);
                $this->output('<script>');
                $this->output('(function(){');
                $this->output('if(document.getElementById("smp-manual-share"))return;'); // already injected via form fields
                $this->output('var html=' . $jsHtml . ';');
                $this->output('var forms=document.querySelectorAll("form");');
                $this->output('for(var i=0;i<forms.length;i++){');
                $this->output('var btns=forms[i].querySelectorAll("input[type=submit],button[type=submit],.qa-form-tall-button");');
                $this->output('if(btns.length){var last=btns[btns.length-1];var d=document.createElement("div");d.innerHTML=html;last.parentNode.insertBefore(d,last);return;}');
                $this->output('}');
                $this->output('})();');
                $this->output('</script>');
            }
        }

        parent::body_suffix();
    }
}
