<?php

/**
 * Token expiry checker process module.
 * Runs once per day on page load to check for upcoming token expirations
 * and send warning emails to Q2A admin.
 */
class SmpTokenChecker
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    /**
     * Called on every page load. Throttled to run check once per day.
     */
    function init_queries($tableslc)
    {
        require_once $this->directory . 'SmpConstants.php';

        $lastCheck = qa_opt(SmpConstants::OPT_LAST_EXPIRY_CHECK);
        $today = date('Y-m-d');

        // Only check once per day
        if ($lastCheck === $today) {
            return [];
        }

        qa_opt(SmpConstants::OPT_LAST_EXPIRY_CHECK, $today);

        require_once $this->directory . 'SmpPoster.php';
        require_once QA_INCLUDE_DIR . 'app/emails.php';

        $poster = new SmpPoster($this->directory);
        $poster->probeAndCheckTokenExpiry();

        return [];
    }
}
