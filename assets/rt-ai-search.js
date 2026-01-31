(function() {
  // Session cache helpers
  var CACHE_PREFIX = 'rt_ai_search_';
  var CACHE_TTL = 30 * 60 * 1000; // 30 minutes in milliseconds

  function getCacheKey(query) {
    return CACHE_PREFIX + btoa(encodeURIComponent(query)).replace(/[^a-zA-Z0-9]/g, '');
  }

  function getFromCache(query) {
    try {
      var key = getCacheKey(query);
      var cached = sessionStorage.getItem(key);
      if (!cached) return null;

      var data = JSON.parse(cached);
      if (Date.now() > data.expires) {
        sessionStorage.removeItem(key);
        return null;
      }
      return data.response;
    } catch (e) {
      return null;
    }
  }

  function saveToCache(query, response) {
    try {
      var key = getCacheKey(query);
      var data = {
        response: response,
        expires: Date.now() + CACHE_TTL
      };
      sessionStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
      // Storage full or unavailable - fail silently
    }
  }

  function logSessionCacheHit(query, resultsCount) {
    // Fire and forget - log session cache hit to analytics
    if (!window.RTAISearch || !window.RTAISearch.endpoint) return;
    var logEndpoint = window.RTAISearch.endpoint.replace('/summary', '/log-session-hit');
    try {
      fetch(logEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'q=' + encodeURIComponent(query) + '&results_count=' + (resultsCount || 0)
      });
    } catch (e) {
      // Fail silently - analytics logging is not critical
    }
  }

  function showSkeleton(container) {
    container.classList.add('rt-ai-loading');
    container.innerHTML =
      '<div class="rt-ai-skeleton" aria-hidden="true">' +
        '<div class="rt-ai-skeleton-line rt-ai-skeleton-line-full"></div>' +
        '<div class="rt-ai-skeleton-line rt-ai-skeleton-line-full"></div>' +
        '<div class="rt-ai-skeleton-line rt-ai-skeleton-line-medium"></div>' +
        '<div class="rt-ai-skeleton-line rt-ai-skeleton-line-short"></div>' +
      '</div>';
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function() {
    if (!window.RTAISearch) return;

    var container = document.getElementById('rt-ai-search-summary-content');
    if (!container) return;

    var q = (window.RTAISearch.query || '').trim();
    if (!q) return;

    // Show skeleton loading immediately
    showSkeleton(container);

    // Check session cache first
    var cached = getFromCache(q);
    if (cached) {
      container.classList.remove('rt-ai-loading');
      container.classList.add('rt-ai-loaded');
      if (cached.answer_html) {
        container.innerHTML = cached.answer_html;
      } else if (cached.error) {
        container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">' +
          document.createTextNode(cached.error).textContent + '</p>';
      }
      // Log session cache hit to analytics (fire and forget)
      logSessionCacheHit(q, cached.results_count);
      return;
    }

    var endpoint = window.RTAISearch.endpoint + '?q=' + encodeURIComponent(q);

    // Set timeout for 30 seconds
    var timeoutId = setTimeout(function() {
      container.classList.remove('rt-ai-loading');
      container.classList.add('rt-ai-loaded');
      container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">Request timed out. Please refresh the page to try again.</p>';
    }, 30000);

    fetch(endpoint, { credentials: 'same-origin' })
      .then(function(response) {
        clearTimeout(timeoutId);

        // Handle specific HTTP error codes
        if (response.status === 429) {
          return {
            error: 'Too many requests. Please wait a moment and try again.'
          };
        }

        if (response.status === 403) {
          return {
            error: 'Access denied. AI search is not available for this request.'
          };
        }

        if (!response.ok) {
          throw new Error('Network response was not ok');
        }

        return response.json();
      })
      .then(function(data) {
        clearTimeout(timeoutId);
        container.classList.remove('rt-ai-loading');
        container.classList.add('rt-ai-loaded');

        if (data && data.answer_html) {
          // Cache successful responses
          saveToCache(q, data);
          container.innerHTML = data.answer_html;
          return;
        }

        if (data && data.error) {
          var errorP = document.createElement('p');
          errorP.setAttribute('role', 'alert');
          errorP.style.cssText = 'margin:0; opacity:0.8;';
          errorP.textContent = String(data.error);
          container.innerHTML = '';
          container.appendChild(errorP);
          return;
        }

        container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">AI summary is not available right now.</p>';
      })
      .catch(function(error) {
        clearTimeout(timeoutId);
        container.classList.remove('rt-ai-loading');
        container.classList.add('rt-ai-loaded');
        container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">AI summary is not available right now.</p>';
      });

    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.rt-ai-sources-toggle');
      if (!btn) return;

      var wrapper = btn.closest('.rt-ai-sources');
      if (!wrapper) return;

      var list = wrapper.querySelector('.rt-ai-sources-list');
      if (!list) return;

      var isHidden = list.hasAttribute('hidden');
      var showLabel = btn.getAttribute('data-label-show') || 'Show sources';
      var hideLabel = btn.getAttribute('data-label-hide') || 'Hide sources';

      if (isHidden) {
        list.removeAttribute('hidden');
        btn.textContent = hideLabel;
        btn.setAttribute('aria-expanded', 'true');
      } else {
        list.setAttribute('hidden', 'hidden');
        btn.textContent = showLabel;
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  });
})();
