# Security Policy

## Supported Versions

We release patches for security vulnerabilities for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 4.0.x   | :white_check_mark: |
| 3.3.x   | :white_check_mark: |
| < 3.3   | :x:                |

## Reporting a Vulnerability

We take the security of RivianTrackr AI Search seriously. If you discover a security vulnerability, please follow these steps:

### How to Report

**DO NOT** create a public GitHub issue for security vulnerabilities.

Instead, please email security reports to: **[jose@riviantrackr.com]**

Include the following information:
- Description of the vulnerability
- Steps to reproduce the issue
- Potential impact
- Suggested fix (if any)

### What to Expect

- **Acknowledgment**: We will acknowledge receipt of your vulnerability report within 48 hours
- **Updates**: We will provide regular updates (at least every 5 business days) on our progress
- **Timeline**: We aim to release a security patch within 30 days for critical issues
- **Credit**: We will credit you in the release notes (unless you prefer to remain anonymous)

## Security Considerations

### API Key Storage

By default, OpenAI API keys are stored in the WordPress options table in plain text. For better security, we strongly recommend using a PHP constant instead.

#### Recommended: Define API Key in wp-config.php

Add this line to your `wp-config.php` file (before "That's all, stop editing!"):

```php
define( 'RT_AI_SEARCH_API_KEY', 'sk-proj-your-api-key-here' );
```

**Benefits of using a constant:**
- API key is not stored in the database (protected from SQL injection/database leaks)
- Key is not visible in WordPress admin (protected from admin account compromise)
- Easier to manage across environments (staging/production)
- Can be set via server environment variables in hosting panels

**Example using environment variables (advanced):**

```php
// In wp-config.php
define( 'RT_AI_SEARCH_API_KEY', getenv('OPENAI_API_KEY') );
```

Then set `OPENAI_API_KEY` in your server's environment configuration.

#### Additional Recommendations

- Use a restricted API key with minimal permissions in OpenAI dashboard
- Set usage limits in your OpenAI account
- Regularly rotate API keys
- Never commit API keys to version control

### Best Practices for Site Administrators

1. **Access Control**
   - Only grant admin access to trusted users
   - The plugin requires `manage_options` capability
   - API keys are only visible to administrators

2. **Rate Limiting**
   - Configure the "Max AI calls per minute" setting to prevent abuse
   - Default is 30 calls per minute
   - Set to 0 for unlimited (not recommended for production)

3. **Caching**
   - Increase cache TTL to reduce API calls and costs
   - Default is 1 hour (3600 seconds)
   - Maximum is 24 hours (86400 seconds)

4. **Monitoring**
   - Regularly check the Analytics page for unusual activity
   - Monitor API usage in your OpenAI dashboard
   - Review error logs for suspicious patterns

5. **Updates**
   - Keep the plugin updated to the latest version
   - Subscribe to security notifications on GitHub

## Known Security Considerations

### WordPress REST API Endpoint

The plugin exposes a public REST API endpoint at `/wp-json/rt-ai-search/v1/summary` with `permission_callback => '__return_true'`. This is intentional for search functionality but means:

- Anyone can trigger AI searches on your site
- Rate limiting is implemented to prevent abuse
- Caching reduces redundant API calls
- Monitor usage via the Analytics dashboard

### Database Logging

Search queries and AI responses are logged to the database for analytics. Consider:

- Logs contain search terms (may include sensitive info)
- No user identification is stored (GDPR-friendly by default)
- Logs are only accessible to site administrators
- Consider implementing log retention policies for privacy

### External API Calls

The plugin makes requests to:
- `api.openai.com` - For AI chat completions
- User's data is sent to OpenAI according to their [data usage policies](https://openai.com/policies/usage-policies)

## Security Features

✅ **Nonce verification** for all admin actions  
✅ **Capability checks** (`manage_options`) for sensitive operations  
✅ **SQL injection prevention** using `$wpdb->prepare()`  
✅ **XSS prevention** using `wp_kses()` and output escaping  
✅ **Rate limiting** to prevent API abuse  
✅ **Input sanitization** on all user inputs  
✅ **Error logging** without exposing sensitive data to users  

## Disclosure Policy

When we receive a security bug report, we will:

1. Confirm the problem and determine affected versions
2. Audit code to find similar problems
3. Prepare fixes for all supported versions
4. Release patches as soon as possible

## Security Updates

Security updates will be released as patch versions (e.g., 3.3.1) and will be clearly marked in the changelog with a `[SECURITY]` tag.

## Questions?

If you have questions about this security policy, please contact us at **[jose@riviantrackr.com]**.
