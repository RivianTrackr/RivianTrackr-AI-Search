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

    // Set timeout for 30 seconds - if request takes longer, show timeout message
    var timeoutId = setTimeout(function() {
      container.classList.add('rt-ai-loaded');
      container.innerHTML = '<p style="margin:0; opacity:0.8;">Request timed out. Please refresh the page to try again.</p>';
    }, 30000);

    fetch(endpoint, { credentials: 'same-origin' })
      .then(function(response) {
        clearTimeout(timeoutId);
        if (!response.ok) throw new Error('Network response was not ok');
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
          container.innerHTML = '<p style="margin:0; opacity:0.8;">' + String(data.error) + '</p>';
          return;
        }

        container.innerHTML = '<p style="margin:0; opacity:0.8;">AI summary is not available right now.</p>';
      })
      .catch(function(error) {
        clearTimeout(timeoutId);
        container.classList.add('rt-ai-loaded');
        container.innerHTML = '<p style="margin:0; opacity:0.8;">AI summary is not available right now.</p>';
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
      } else {
        list.setAttribute('hidden', 'hidden');
        btn.textContent = showLabel;
      }
    });
  });
})();