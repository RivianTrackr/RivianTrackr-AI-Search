# Contributing to AI Search Summary

Thank you for your interest in contributing to AI Search Summary! We welcome contributions from the community.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [How to Contribute](#how-to-contribute)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Pull Request Process](#pull-request-process)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Enhancements](#suggesting-enhancements)

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please be respectful and constructive in all interactions.

## Getting Started

1. **Fork the repository** on GitHub
2. **Clone your fork** locally
3. **Create a branch** for your changes
4. **Make your changes** following our coding standards
5. **Test your changes** thoroughly
6. **Submit a pull request**

## How to Contribute

### Types of Contributions We Welcome

- 🐛 **Bug fixes** - Fix issues or improve error handling
- ✨ **New features** - Add functionality that benefits AI Search Summary users
- 📝 **Documentation** - Improve README, code comments, or inline docs
- 🎨 **UI/UX improvements** - Enhance the admin interface or frontend display
- ⚡ **Performance optimizations** - Make the plugin faster or more efficient
- 🔒 **Security improvements** - Enhance security measures
- 🧪 **Tests** - Add or improve test coverage
- 🌐 **Translations** - Help translate the plugin (future feature)

## Development Setup

### Prerequisites

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- OpenAI API account (for testing AI features)
- Local WordPress development environment (Local by Flywheel, MAMP, or similar)

### Local Installation

1. Clone your fork:
   ```bash
   git clone https://github.com/YOUR-USERNAME/AI-Search-Summary.git
   ```

2. Copy the plugin to your WordPress plugins directory:
   ```bash
   cp -r AI-Search-Summary /path/to/wordpress/wp-content/plugins/
   ```

3. Activate the plugin in WordPress admin

4. Configure settings:
   - Add your OpenAI API key (use a test key with rate limits)
   - Enable AI search
   - Adjust settings as needed

### Development Environment

We recommend:
- **Local by Flywheel** or **MAMP** for local WordPress
- **VS Code** or **PHPStorm** for development
- **PHP_CodeSniffer** for code quality
- Browser dev tools for frontend debugging

## Coding Standards

### PHP

We follow **WordPress Coding Standards**:

- Use tabs for indentation
- Single quotes for strings (unless interpolation needed)
- Yoda conditions: `if ( 'foo' === $bar )`
- Space after control structures: `if ( condition ) {`
- Proper PHPDoc comments for functions and methods
- Sanitize inputs, escape outputs, validate and prepare SQL queries

Example:
```php
/**
 * Calculate success rate percentage.
 *
 * @param int $success_count Number of successful operations.
 * @param int $total Total number of operations.
 * @return int Success rate as a percentage (0-100).
 */
private function calculate_success_rate( $success_count, $total ) {
    if ( $total <= 0 ) {
        return 0;
    }
    
    return (int) round( ( $success_count / $total ) * 100 );
}
```

### JavaScript

- Use ES5 syntax for maximum compatibility
- Wrap code in IIFE: `(function() { ... })()`
- Use `var` instead of `let`/`const` for browser compatibility
- Semicolons required
- Single quotes for strings

### CSS

- Mobile-first responsive design
- Use CSS custom properties where appropriate
- Prefix classes with `aiss-` to avoid conflicts
- Use flexbox for layouts
- Keep specificity low

## Testing

### Manual Testing Checklist

Before submitting a PR, test:

- ✅ Plugin activation/deactivation
- ✅ Settings page (save settings, refresh models, clear cache)
- ✅ Search functionality on frontend
- ✅ AI summary generation
- ✅ Analytics page displays correctly
- ✅ Dashboard widget shows stats
- ✅ Error handling (invalid API key, rate limits, etc.)
- ✅ Cache behavior (check transients)
- ✅ Mobile responsiveness
- ✅ Browser compatibility (Chrome, Firefox, Safari, Edge)

### Testing AI Features

When testing AI functionality:
- Use a test API key with usage limits
- Test with various search queries (short, long, with/without results)
- Test cache hits and misses
- Test rate limiting
- Verify error messages are user-friendly

### Test Edge Cases

- Empty search queries
- Very long search queries
- Special characters in searches
- No search results found
- OpenAI API errors (simulate with invalid key)
- Network timeouts
- Database errors

## Pull Request Process

### Before Submitting

1. **Update your fork** with the latest from `main`:
   ```bash
   git fetch upstream
   git checkout main
   git merge upstream/main
   ```

2. **Create a feature branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```

3. **Make your changes** and commit with clear messages:
   ```bash
   git commit -m "Add feature: brief description"
   ```

### Commit Message Guidelines

Use clear, descriptive commit messages:

**Good:**
- `Fix cache key collision when max_posts changes`
- `Add timeout handling for AI requests`
- `Improve error messages for API failures`

**Not so good:**
- `Fix bug`
- `Update code`
- `Changes`

### PR Description Template

```markdown
## Description
Brief description of what this PR does

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Testing
Describe how you tested these changes

## Checklist
- [ ] Code follows WordPress coding standards
- [ ] Self-review completed
- [ ] Comments added to complex code
- [ ] Documentation updated (if needed)
- [ ] No new warnings or errors introduced
- [ ] Tested on WordPress 5.0+
- [ ] Tested on PHP 7.4+
```

### Review Process

1. A maintainer will review your PR within 5 business days
2. Address any requested changes
3. Once approved, a maintainer will merge your PR
4. Your contribution will be included in the next release

## Reporting Bugs

### Before Reporting

1. Check if the bug has already been reported in [Issues](https://github.com/YOUR-ORG/AI-Search-Summary/issues)
2. Test with the latest version of the plugin
3. Test with default WordPress theme (Twenty Twenty-Four)
4. Disable other plugins to check for conflicts

### Bug Report Template

```markdown
**Describe the bug**
A clear description of what the bug is

**To Reproduce**
Steps to reproduce the behavior:
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What you expected to happen

**Screenshots**
If applicable, add screenshots

**Environment:**
- WordPress version:
- PHP version:
- Plugin version:
- Browser (if frontend issue):
- Theme:
- Other active plugins:

**Additional context**
Any other context about the problem
```

## Suggesting Enhancements

We welcome enhancement suggestions! Please:

1. **Check existing issues** to avoid duplicates
2. **Explain the use case** - why would this benefit users?
3. **Provide examples** if possible
4. **Consider scope** - does this fit the plugin's purpose?

### Enhancement Request Template

```markdown
**Is your feature request related to a problem?**
A clear description of the problem

**Describe the solution you'd like**
What you want to happen

**Describe alternatives you've considered**
Other solutions you've thought about

**Additional context**
Mockups, examples, or other context
```

## Development Tips

### Useful Constants

The plugin defines several constants you can use:
- `AISS_VERSION` - Current plugin version
- `AISS_MIN_CACHE_TTL` - Minimum cache time (60s)
- `AISS_MAX_CACHE_TTL` - Maximum cache time (86400s)
- `AISS_CONTENT_LENGTH` - Post content length (400 chars)
- `AISS_EXCERPT_LENGTH` - Excerpt length (200 chars)

### Database Tables

Analytics data is stored in `wp_aiss_logs` table:
- `search_query` - The search term
- `results_count` - Number of posts found
- `ai_success` - Whether AI summary succeeded (0/1)
- `ai_error` - Error message if failed
- `created_at` - Timestamp

### Cache Management

- Cache uses WordPress transients API
- Cache keys are namespaced and versioned
- Clear cache by bumping namespace: `bump_cache_namespace()`
- Cache TTL is configurable (60s - 86400s)

### Debugging

Enable WordPress debugging in `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check logs in `wp-content/debug.log`

## Questions?

If you have questions about contributing, please:
- Open a [Discussion](https://github.com/YOUR-ORG/AI-Search-Summary/discussions)
- Open an issue on GitHub

## License

By contributing, you agree that your contributions will be licensed under the same GPL v2 or later license that covers this project.

---

Thank you for contributing to AI Search Summary!