// ============================================================
// C Tech Fix CRM — Global JavaScript
// ============================================================

// Mobile sidebar toggle
function toggleSidebar() {
  const sidebar  = document.querySelector('.sidebar');
  const overlay  = document.querySelector('.sidebar-overlay');
  sidebar.classList.toggle('open');
  overlay.classList.toggle('open');
}

// Close sidebar when overlay is clicked
document.addEventListener('DOMContentLoaded', function () {
  const overlay = document.querySelector('.sidebar-overlay');
  if (overlay) {
    overlay.addEventListener('click', function () {
      document.querySelector('.sidebar').classList.remove('open');
      overlay.classList.remove('open');
    });
  }
});

// Auto-refresh (for TV/dashboard mode)
// Usage: add data-refresh="30" to any element to reload every 30 seconds
document.addEventListener('DOMContentLoaded', function () {
  const el = document.querySelector('[data-refresh]');
  if (el) {
    const seconds = parseInt(el.getAttribute('data-refresh'), 10);
    if (seconds > 0) {
      setTimeout(function () { location.reload(); }, seconds * 1000);
    }
  }
});

// Drill-down: metric cards navigate to their detail page
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-drill]').forEach(function (card) {
    card.addEventListener('click', function () {
      const url = card.getAttribute('data-drill');
      if (url) window.location.href = url;
    });
    card.style.cursor = 'pointer';
  });
});

// Confirm before destructive actions
document.querySelectorAll('[data-confirm]').forEach(function (el) {
  el.addEventListener('click', function (e) {
    const msg = el.getAttribute('data-confirm') || 'Are you sure?';
    if (!confirm(msg)) e.preventDefault();
  });
});

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.alert.auto-dismiss').forEach(function (alert) {
    setTimeout(function () {
      alert.style.opacity = '0';
      alert.style.transition = 'opacity 0.5s';
      setTimeout(function () { alert.remove(); }, 500);
    }, 5000);
  });
});

// Format numbers with commas for display
function formatNumber(n) {
  return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Update clock on Owner TV dashboard
function updateClock() {
  const el = document.getElementById('live-clock');
  if (!el) return;
  const now = new Date();
  el.textContent = now.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
setInterval(updateClock, 1000);
updateClock();
