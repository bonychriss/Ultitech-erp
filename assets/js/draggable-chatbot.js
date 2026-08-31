/**
 * Draggable Chatbot Launcher
 * Move #chatbotLauncher freely across the screen (mouse + touch).
 * Position persists in localStorage.
 */
(function () {
  var DRAG_THRESHOLD = 6;
  var STORAGE_KEY = 'chatbot_pos';

  function clamp(n, min, max) {
    return Math.max(min, Math.min(n, max));
  }

  function applyPos(el, x, y) {
    var maxX = window.innerWidth - el.offsetWidth;
    var maxY = window.innerHeight - el.offsetHeight;
    x = clamp(x, 0, Math.max(0, maxX));
    y = clamp(y, 0, Math.max(0, maxY));
    el.style.setProperty('left', x + 'px', 'important');
    el.style.setProperty('top', y + 'px', 'important');
    el.style.setProperty('right', 'auto', 'important');
    el.style.setProperty('bottom', 'auto', 'important');
    return { left: x + 'px', top: y + 'px' };
  }

  function restorePos(el) {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      var pos = JSON.parse(raw);
      if (!pos || pos.left == null || pos.top == null) return;
      var x = parseFloat(pos.left);
      var y = parseFloat(pos.top);
      if (Number.isNaN(x) || Number.isNaN(y)) return;
      applyPos(el, x, y);
    } catch (e) {
      /* ignore */
    }
  }

  function bind(launcher) {
    if (!launcher || launcher.dataset.draggableBound === '1') return;
    launcher.dataset.draggableBound = '1';
    launcher.classList.add('chatbot-launcher--draggable');
    launcher.title = (launcher.getAttribute('title') || 'Help') + ' — drag to move';

    var dragging = false;
    var moved = false;
    var startX = 0;
    var startY = 0;
    var offsetX = 0;
    var offsetY = 0;
    var pointerId = null;

    function onPointerDown(e) {
      if (e.button != null && e.button !== 0) return;
      dragging = true;
      moved = false;
      pointerId = e.pointerId;
      startX = e.clientX;
      startY = e.clientY;
      var rect = launcher.getBoundingClientRect();
      offsetX = e.clientX - rect.left;
      offsetY = e.clientY - rect.top;
      launcher.classList.add('is-dragging');
      launcher.style.setProperty('transition', 'none', 'important');
      launcher.style.setProperty('cursor', 'grabbing', 'important');
      try {
        launcher.setPointerCapture(e.pointerId);
      } catch (err) {
        /* ignore */
      }
      e.preventDefault();
    }

    function onPointerMove(e) {
      if (!dragging) return;
      if (pointerId != null && e.pointerId !== pointerId) return;

      var dx = e.clientX - startX;
      var dy = e.clientY - startY;
      if (!moved && Math.hypot(dx, dy) > DRAG_THRESHOLD) {
        moved = true;
      }
      if (!moved) return;

      applyPos(launcher, e.clientX - offsetX, e.clientY - offsetY);
      e.preventDefault();
    }

    function onPointerUp(e) {
      if (!dragging) return;
      if (pointerId != null && e.pointerId !== pointerId) return;
      dragging = false;
      pointerId = null;
      launcher.classList.remove('is-dragging');
      launcher.style.removeProperty('transition');
      launcher.style.setProperty('cursor', 'grab', 'important');

      if (moved) {
        try {
          localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
              left: launcher.style.left,
              top: launcher.style.top,
            })
          );
        } catch (err) {
          /* ignore */
        }
        var blockClick = function (ev) {
          ev.preventDefault();
          ev.stopImmediatePropagation();
          launcher.removeEventListener('click', blockClick, true);
        };
        launcher.addEventListener('click', blockClick, true);
      }

      try {
        launcher.releasePointerCapture(e.pointerId);
      } catch (err) {
        /* ignore */
      }
    }

    launcher.addEventListener('pointerdown', onPointerDown);
    launcher.addEventListener('pointermove', onPointerMove);
    launcher.addEventListener('pointerup', onPointerUp);
    launcher.addEventListener('pointercancel', onPointerUp);

    restorePos(launcher);

    window.addEventListener('resize', function () {
      var rect = launcher.getBoundingClientRect();
      if (launcher.style.left && launcher.style.left !== 'auto') {
        applyPos(launcher, rect.left, rect.top);
      }
    });
  }

  function boot() {
    var el = document.getElementById('chatbotLauncher');
    if (el) {
      bind(el);
      return;
    }
    var tries = 0;
    var timer = setInterval(function () {
      tries += 1;
      var btn = document.getElementById('chatbotLauncher');
      if (btn) {
        clearInterval(timer);
        bind(btn);
      } else if (tries > 40) {
        clearInterval(timer);
      }
    }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
