(function() {
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

    var endpoint = window.RTAISearch.endpoint + '?q=' + encodeURIComponent(q);

    // Set timeout for 30 seconds
    var timeoutId = setTimeout(function() {
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
        container.classList.add('rt-ai-loaded');

        if (data && data.answer_html) {
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