# Social Media Poster for Question2Answer

Automatically post your Q2A content to multiple social media platforms. Supports questions, blog posts, exams, jobs, and scheduled daily posts.

## Features

- **Multi-platform support**: Telegram, Facebook, X (Twitter), LinkedIn, WhatsApp, Instagram, YouTube Shorts
- **Multiple accounts per platform**: Add as many Telegram channels or Facebook pages as you need
- **Content type routing**: Control which accounts receive which types of posts
- **Category-specific routing**: Send questions from specific categories to dedicated channels
- **Auto-generated images**: Creates images for Instagram and YouTube Shorts from post content
- **AI-powered messages**: Uses OpenAI to generate engaging social media posts
- **Daily automated posts**: Question of the Day and Quote of the Day features
- **Token expiry warnings**: Get email alerts before your API tokens expire
- **Developer API**: Other plugins can use the posting functionality

## Requirements

- Question2Answer 1.8.0+
- PHP 7.4+
- cURL extension
- GD extension (for image generation)
- Node.js 14+ (for server-side LaTeX/math rendering in QOTD images)
- wkhtmltoimage 0.12.6+ with patched Qt (for HTML-to-image conversion)
- ffmpeg (optional, for YouTube Shorts video generation)

## Installation

1. Download and extract to your `qa-plugin` folder
2. The folder should be named `social-media-poster`
3. Create the image upload directory and set permissions:
   ```bash
   mkdir -p /path/to/qa/qa-uploads/smp-images
   chown www-data:www-data /path/to/qa/qa-uploads /path/to/qa/qa-uploads/smp-images
   ```
4. If your Q2A installation uses a separate document root (e.g., symlinked `qa-plugin`, `qa-include`, etc.), ensure `qa-uploads` is accessible from the public document root. For example:
   ```bash
   ln -s /path/to/qa/qa-uploads /path/to/public-docroot/qa-uploads
   ```
5. Go to Admin → Plugins and you should see "Social Media Poster"
6. Configure your accounts in the plugin settings

### KaTeX (LaTeX Math Rendering)

The QOTD image generator uses KaTeX to render LaTeX math expressions. Install it via npm from the plugin directory:

```bash
cd /path/to/qa-plugin/social-media-poster
npm install katex
```

This creates a `node_modules/katex/` folder used by `katex-render.js` for server-side math rendering. The `node_modules/` directory is in `.gitignore`, so **you must run this after every fresh clone or deployment**.

Verify it works:

```bash
echo 'Test $\frac{1}{2}$' | node katex-render.js
```

You should see HTML output with `<span class="katex">` tags and a summary like `1 katex span found`.

### wkhtmltoimage

Install the patched Qt version of wkhtmltoimage (the standard version doesn't support local file access for CSS/fonts):

```bash
# Ubuntu/Debian
sudo apt-get install wkhtmltopdf
```

Or download the patched binary from [wkhtmltopdf.org/downloads.html](https://wkhtmltopdf.org/downloads.html).

Verify:

```bash
wkhtmltoimage --version
# Should show 0.12.6 (with patched qt)
```

## Configuration

### General Settings

- **OpenAI API Key**: Enter your OpenAI API key for AI-generated messages. This key is shared with other Q2A plugins that use the `qa-openai-api-key` option.
- **System Prompt**: Customize how OpenAI generates your social media posts.

### Platform Accounts

Add accounts for each platform you want to post to:

| Platform | Required Credentials |
|----------|---------------------|
| Telegram | Bot Token, Chat ID |
| Facebook | Page Access Token, Page ID |
| X (Twitter) | API Key, API Secret, Access Token, Access Token Secret |
| LinkedIn | Access Token, Author URN |
| WhatsApp | Access Token, Phone Number ID, Recipient Phone |
| Instagram | Access Token, Account ID |
| YouTube | Client ID, Client Secret, Refresh Token |

You can add multiple accounts per platform. Mark one as "Default" for each platform.

### Token Auto-Refresh

Meta platform tokens (Facebook, Instagram, WhatsApp) can be auto-refreshed before they expire:

1. Go to the **Token Management** section in plugin settings
2. Enter your **Meta App ID** and **Meta App Secret** (from [developers.facebook.com](https://developers.facebook.com/apps/))
3. Save the settings

The plugin will automatically exchange tokens for long-lived versions (~60 days) when they are within 7 days of expiry. You can also click **"Refresh Tokens Now"** to trigger a manual refresh at any time.

**Note:** YouTube tokens use a refresh_token that doesn't expire (handled automatically). Telegram and X tokens never expire.

### Content Type Routing

For each content type (Question, Exam, Blog, Job, QOTD, Quote), select which accounts should receive posts. You can send to multiple accounts simultaneously.

### Category Routing (Optional)

If you have many categories and want certain categories to post to specific channels:

1. Click "Show category settings"
2. For each category, select additional accounts
3. Check "Also post to default Question accounts" to post to both category-specific AND default accounts

### Daily Posters

- **Question of the Day**: Posts a random MCQ question from your database daily
- **Quote of the Day**: Posts an AI-generated motivational quote daily

Configure the hour when each should post (server time). The admin panel shows both the server time and your browser's local time for easy reference.

#### Cron Job Setup (Recommended)

By default, daily posts are triggered on the first page load after the configured hour. For exact-time posting, set up a cron job:

1. Go to Admin → Plugins → Social Media Poster → Daily Poster Settings
2. Set a **Cron Secret Key** (any random string, e.g. `my-secret-key-123`)
3. Save settings — the full cron URL will be displayed
4. Add a cron entry that runs every hour:

**Using curl (HTTP):**
```bash
# Run every hour at minute 0
0 * * * * curl -s "https://yoursite.com/qa-plugin/social-media-poster/cron.php?key=YOUR_SECRET_KEY" > /dev/null 2>&1
```

**Using PHP CLI (no web server needed):**
```bash
0 * * * * php /path/to/qa-plugin/social-media-poster/cron.php --key=YOUR_SECRET_KEY > /dev/null 2>&1
```

The script runs every hour but only posts when the configured hour is reached and the post hasn't been made today. This ensures posts happen within the first minute of the configured hour.

**Without cron**, the plugin falls back to page-load triggering — posts happen on the first visitor page load after the configured hour.

## Custom Tables

The plugin auto-detects custom Q2A tables:
- `^exams` - Shows Exam content type
- `^blogs` - Shows Blog content type  
- `^jobs` - Shows Job content type

If these tables don't exist, the corresponding content types won't appear in settings.

## Developer API

Other Q2A plugins can use the Social Media Poster functionality. Include the API file:

```php
require_once QA_PLUGIN_DIR . 'social-media-poster/SmpApi.php';
```

### Basic Posting

```php
// Post to all accounts configured for questions
$results = smp_post('question', 'Check out this question!', 'https://yoursite.com/q/123');

// Post to specific platforms
$results = smp_post_to_platforms(['telegram', 'facebook'], 'Hello from my plugin!');

// Post to a specific account
$result = smp_post_to_account('telegram_abc12345', 'Direct to this channel');
```

### Generate Content

```php
// Generate an image for Instagram
$imageUrl = smp_generate_image('Your text content here', 'Optional Title');

// Generate a social media message using OpenAI
$message = smp_generate_message('Your long article content...');
```

### Query Accounts

```php
// Check if plugin is configured
if (smp_is_configured()) {
    // Get all accounts
    $accounts = smp_get_accounts();
    
    // Get accounts for a content type
    $questionAccounts = smp_get_accounts_for_content('question');
    
    // With category routing
    $physicsAccounts = smp_get_accounts_for_content('question', $categoryId);
}
```

### Constants

```php
// Platform constants
SMP_PLATFORM_TELEGRAM
SMP_PLATFORM_FACEBOOK
SMP_PLATFORM_X
SMP_PLATFORM_LINKEDIN
SMP_PLATFORM_WHATSAPP
SMP_PLATFORM_INSTAGRAM
SMP_PLATFORM_YOUTUBE

// Content type constants
SMP_CONTENT_QUESTION
SMP_CONTENT_EXAM
SMP_CONTENT_BLOG
SMP_CONTENT_JOB
SMP_CONTENT_QOTD
SMP_CONTENT_QUOTE
```

### Return Format

All posting functions return an array with results:

```php
[
    'account_id' => [
        'success' => true,
        'platform' => 'telegram',
        'account_name' => 'Physics Channel',
        'response' => [...] // Platform-specific response
    ],
    'another_account' => [
        'success' => false,
        'error' => 'Rate limit exceeded',
        'platform' => 'facebook',
        'account_name' => 'Main Page'
    ]
]
```

## Events Handled

The plugin listens for these Q2A events:
- `q_post` - New question posted
- `qa_exam_post_` - New exam created
- `qas_blog_b_post` - New blog post
- `qa_job_post` - New job listing

## Troubleshooting

**Posts not appearing?**
- Check that the account is enabled (checkbox in account settings)
- Verify credentials are correct
- Check the content type is mapped to your account
- Look for errors in PHP error log

**Token expiry warnings?**
- The plugin probes API tokens daily and sends email warnings at 7 and 2 days before expiry
- Meta tokens (Facebook/Instagram/WhatsApp) are auto-refreshed when near expiry if App ID/Secret are configured
- Use the "Refresh Tokens Now" button in admin to manually trigger a refresh
- For LinkedIn, you need to manually re-authenticate when tokens expire

**Images not generating?**
- Ensure GD extension is installed and enabled
- Check that `qa-uploads/smp-images/` exists and is writable by the web server (`www-data`)
- If using a separate document root with symlinks, ensure `qa-uploads` is symlinked into the public document root

**LaTeX/math not rendering in QOTD images?**
- Ensure Node.js is installed and accessible to the web server user: `sudo -u www-data node --version`
- Ensure KaTeX is installed: `ls node_modules/katex/dist/katex.min.css` (from the plugin directory)
- If missing, run `npm install katex` from the plugin directory
- Test rendering: `echo 'Test $\frac{1}{2}$' | node katex-render.js`
- Ensure wkhtmltoimage is installed: `sudo -u www-data wkhtmltoimage --version`
- After updating PHP files, reload Apache to clear opcache: `sudo service apache2 reload`

**Instagram "media couldn't be fetched" error?**
- The image URL must be publicly accessible to Facebook's servers
- If using Cloudflare, add a WAF rule to allow the `facebookexternalhit` user-agent:
  ```
  (http.user_agent contains "facebookexternalhit") or (http.user_agent contains "Facebot")
  ```
  Action: Allow or Skip security checks
- Verify the image URL returns HTTP 200 with `Content-Type: image/png`

**YouTube videos not uploading?**
- Install ffmpeg on your server
- Ensure the refresh token has YouTube upload scope

## License

MIT License

## Support

For issues and feature requests, please open an issue on GitHub.
