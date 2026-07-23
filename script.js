/* =====================================================
   Maxwell Miya — Portfolio interactions
   ===================================================== */
(() => {
  'use strict';

  // ---------- year ----------
  const yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());

  // ---------- mobile nav ----------
  const toggle = document.getElementById('navToggle');
  const links  = document.getElementById('navLinks');

  if (toggle && links) {
    const close = () => {
      toggle.classList.remove('open');
      links.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    };
    toggle.addEventListener('click', () => {
      const open = toggle.classList.toggle('open');
      links.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
    });
    links.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
    document.addEventListener('click', (e) => {
      if (!links.contains(e.target) && !toggle.contains(e.target)) close();
    });
    window.addEventListener('resize', () => { if (window.innerWidth > 960) close(); });
  }

  // ---------- scroll reveal ----------
  const revealTargets = document.querySelectorAll(
    '.project, .skill-group, .cert, .tl-item, .stat-card, .about-body, .contact-form'
  );
  revealTargets.forEach(el => el.classList.add('reveal'));

  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('on');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
    revealTargets.forEach(el => io.observe(el));
  } else {
    // fallback: reveal immediately
    revealTargets.forEach(el => el.classList.add('on'));
  }

  // ---------- contact form ----------
  const form   = document.getElementById('contactForm');
  const status = document.getElementById('formStatus');
  const loadedAtField = document.getElementById('loadedAt');

  if (loadedAtField) loadedAtField.value = String(Date.now());

  if (form && status) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const submitBtn = form.querySelector('button[type="submit"]');
      const originalText = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending…';
      status.textContent = '';
      status.className = 'form-status';

      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { Accept: 'application/json' }
        });

        const data = await res.json().catch(() => ({}));

        if (res.ok && data.ok) {
          form.reset();
          if (loadedAtField) loadedAtField.value = String(Date.now());
          status.textContent = data.message || 'Message sent.';
          status.className = 'form-status ok';
        } else {
          status.textContent = data.message || 'Something went wrong. Please email maxwell.miya@gmail.com instead.';
          status.className = 'form-status err';
        }
      } catch (err) {
        status.textContent = 'Network error. Please email maxwell.miya@gmail.com instead.';
        status.className = 'form-status err';
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
    });
  }

  // ---------- safety: remove <a> link behaviour where data-nolink="true" ----------
  // Used for the placeholder Dell Networking cert until you paste the real Credly link.
  document.querySelectorAll('a[data-nolink="true"]').forEach(a => {
    a.addEventListener('click', (e) => e.preventDefault());
    a.style.cursor = 'default';
  });

})();
