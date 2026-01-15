# RivianTrackr AI Search

[![WordPress Plugin Version](https://img.shields.io/badge/version-3.3.0-blue.svg)](https://github.com/RivianTrackr/RivianTrackr-AI-Search)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org)

Add an AI-powered search summary to WordPress search results on [RivianTrackr.com](https://riviantrackr.com), powered by OpenAI, with intelligent caching, analytics, and rate limiting.

## ✨ Features

- 🤖 **AI-Powered Summaries** - Generate intelligent summaries of search results using OpenAI's GPT models
- ⚡ **Non-Blocking UI** - Search results load immediately; AI summary appears progressively
- 💾 **Smart Caching** - Configurable cache (60s - 24h) reduces API calls and costs
- 📊 **Analytics Dashboard** - Track search queries, success rates, errors, and usage patterns
- 🛡️ **Rate Limiting** - Prevent API abuse with configurable per-minute limits
- 🔍 **Source Attribution** - Displays up to 5 relevant sources used in the AI summary
- 📱 **Responsive Design** - Works beautifully on mobile, tablet, and desktop
- 🎯 **WordPress Native** - Follows WordPress coding standards and best practices
- 🔒 **Security First** - Nonce verification, capability checks, SQL injection prevention

## 📸 Screenshots

### AI Search Summary
![AI Search Summary]
*AI-generated summary appears above search results with collapsible sources*

### Settings Page
![Settings Page]
*Configure API key, model, cache settings, and rate limits*

### Analytics Dashboard
![Analytics]
*Track search queries, success rates, and identify trends*

## 🚀 Installation

### From GitHub

1. Download the latest release from [Releases](https://github.com/RivianTrackr/RivianTrackr-AI-Search/releases)
2. Upload to `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to **AI Search → Settings** in the admin menu
5. Add your OpenAI API key
6. Enable AI search summary
7. Configure settings as needed

### Manual Installation

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/RivianTrackr/RivianTrackr-AI-Search.git
```

Then activate via WordPress admin.

## ⚙️ Configuration

### Required Settings

1. **OpenAI API Key** - Get one from [OpenAI Platform](https://platform.openai.com/api-keys)
2. **Enable AI Search** - Toggle to activate the feature

### Recommended Settings

- **Model**: `gpt-4.1-mini` (default) - Good balance of speed and quality
- **Max Posts**: `10` (default) - Number of posts sent to AI as context
- **Cache TTL**: `3600` seconds (1 hour) - How long to cache summaries
- **Max Calls Per Minute**: `30` - Rate limit to prevent abuse

### Advanced Settings

Navigate to **AI Search → Settings** in WordPress admin:

| Setting | Description | Default | Range |
|---------|-------------|---------|-------|
| API Key | Your OpenAI API key | - | Required |
| Model | GPT model to use | gpt-4.1-mini | Any GPT model |
| Max Posts | Posts sent as context | 10 | 1-20 |
| Enable | Activate AI summaries | Off | On/Off |
| Max Calls/Min | Rate limit | 30 | 0+ (0=unlimited) |
| Cache TTL | Cache lifetime | 3600s | 60-86400s |

## 📊 Analytics

Access **AI Search → Analytics** to view:

- **Overview**: Total searches, success rate, 24-hour activity, error count
- **Daily Stats**: 14-day trend of searches and success rates
- **Top Queries**: Most searched terms with success rates
- **Top Errors**: Most common error messages
- **Recent Events**: Latest 50 search events with details

Analytics data is also available in the WordPress dashboard widget for quick access.

## 🎨 How It Works

1. **User searches** - WordPress displays normal search results immediately
2. **AI placeholder appears** - Shows loading spinner above results
3. **Background API call** - Sends top posts to OpenAI (cached if available)
4. **Summary renders** - AI-generated summary appears with sources
5. **Sources collapsible** - Users can expand/collapse source citations

### Search Query Optimization

- Prioritizes recent content (newer posts ranked higher)
- Sends top 10 most relevant posts (configurable)
- Includes title, URL, excerpt, and content snippet (400 chars)
- AI uses only provided context (no external knowledge)

### Caching Strategy

- Cache key includes: model + max_posts + search query
- Namespace-based invalidation (bump namespace to clear all)
- Configurable TTL (default 1 hour)
- Cache hits don't count against rate limits

## 🔒 Security

### Best Practices

✅ **API Key Storage** - Stored in WordPress options (plain text)
- Use restricted API keys with usage limits in OpenAI dashboard
- Regularly rotate keys
- Never commit keys to version control

✅ **Rate Limiting** - Prevents abuse and controls costs

✅ **Input Sanitization** - All inputs sanitized and validated

✅ **Output Escaping** - XSS prevention with `wp_kses()`

✅ **SQL Injection Prevention** - Uses `$wpdb->prepare()`

✅ **Nonce Verification** - All admin actions protected

✅ **Capability Checks** - Requires `manage_options` for settings

See [SECURITY.md](SECURITY.md) for detailed security information.

## 🛠️ Development

### Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+
- OpenAI API account

### Local Setup

```bash
# Clone the repository
git clone https://github.com/RivianTrackr/RivianTrackr-AI-Search.git

# Copy to WordPress plugins directory
cp -r RivianTrackr-AI-Search /path/to/wordpress/wp-content/plugins/

# Activate in WordPress admin
```

### File Structure

```
RivianTrackr-AI-Search/
├── riviantrackr-ai-search.php   # Main plugin file
├── assets/
│   ├── rt-ai-search.css         # Frontend styles
│   └── rt-ai-search.js          # Frontend JavaScript
├── README.md                    # This file
├── CONTRIBUTING.md              # Contribution guidelines
├── SECURITY.md                  # Security policy
└── index.php                    # Directory protection
```

### Key Constants

```php
RT_AI_SEARCH_VERSION              // Plugin version
RT_AI_SEARCH_MIN_CACHE_TTL        // Min cache: 60s
RT_AI_SEARCH_MAX_CACHE_TTL        // Max cache: 86400s
RT_AI_SEARCH_CONTENT_LENGTH       // Content limit: 400 chars
RT_AI_SEARCH_EXCERPT_LENGTH       // Excerpt limit: 200 chars
RT_AI_SEARCH_MAX_SOURCES_DISPLAY  // Max sources: 5
RT_AI_SEARCH_API_TIMEOUT          // API timeout: 60s
```

### Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📝 Changelog

### Version 3.3.0 (Current)
- ✨ Fixed analytics page table creation bug
- ✨ Fixed cache key collision when max_posts changes
- ✨ Increased default max_posts from 6 to 10
- ✨ Added database error handling with logging
- ✨ Added 30-second timeout for AI requests
- ✨ Optimized search queries (3 queries → 1)
- ✨ Replaced magic numbers with named constants
- ✨ Added success rate helper method (DRY)
- ✨ Improved error messages with specific, actionable feedback

### Version 3.2.4
- Initial public release
- Core AI search functionality
- Analytics dashboard
- Cache management
- Rate limiting

See full changelog in [releases](https://github.com/RivianTrackr/RivianTrackr-AI-Search/releases).

## 🐛 Known Issues

- API keys stored in plain text (WordPress limitation)
- Requires active internet connection for OpenAI API
- AI responses depend on OpenAI service availability

## ❓ FAQ

### How much does this cost?

API costs depend on:
- Model used (gpt-4.1-mini is cheapest)
- Search volume
- Cache hit rate

Typical cost: $0.001-0.003 per search with caching.

### Can I use a different AI provider?

Currently only OpenAI is supported. Other providers could be added via contributions.

### Does this work with other search plugins?

Yes, it hooks into standard WordPress search via `is_search()`.

### How do I clear the cache?

Go to **AI Search → Settings** and click "Clear AI cache".

### What models are supported?

All OpenAI chat completion models:
- GPT-5 series (when available)
- GPT-4.1 series (recommended)
- GPT-4o series
- GPT-3.5-turbo

### Does this slow down search?

No! Search results load immediately. The AI summary loads progressively in the background.

### Is this GDPR compliant?

Search queries are logged locally (no user identification). Data sent to OpenAI follows their [privacy policy](https://openai.com/policies/privacy-policy).

## 📄 License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Built for [RivianTrackr.com](https://riviantrackr.com)
- Powered by [OpenAI](https://openai.com)
- Built with ❤️ for the Rivian community

## 📧 Support

- **Issues**: [GitHub Issues](https://github.com/RivianTrackr/RivianTrackr-AI-Search/issues)
- **Security**: See [SECURITY.md](SECURITY.md)
- **Email**: [your-email@riviantrackr.com]

---

Made with ⚡ for Rivian enthusiasts