# AI Search Summary

WordPress plugin that adds an AI summary panel to search results, powered by OpenAI, with caching and analytics.

## Features

- AI-powered search summaries using OpenAI's GPT models
- Multi-tier caching (server-side + browser session)
- Analytics dashboard with search trends
- Rate limiting to control API costs
- Customizable appearance with CSS
- Secure API key storage via wp-config.php constant

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Go to **AI Search → Settings** and add your OpenAI API key
4. Enable AI Search

## Configuration

### API Key (Recommended: Secure Method)

Add to your `wp-config.php`:

```php
define( 'AISS_API_KEY', 'sk-proj-your-api-key-here' );
```

### Settings

Configure in **WP Admin → AI Search → Settings**:
- AI Model selection
- Cache duration
- Rate limits
- Custom CSS styling

## Documentation

- [Security Policy](SECURITY.md)
- [Contributing Guide](CONTRIBUTING.md)
