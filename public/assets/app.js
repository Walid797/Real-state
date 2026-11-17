/* Plat — page behaviour. No framework; every block is independent and exits early when its markup is absent. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var readJson = function (id) {
    var el = document.getElementById(id);
    if (!el) return null;
    try { return JSON.parse(el.textContent); } catch (e) { return null; }
  };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var usd = function (n) { return n == null ? '—' : '$' + Math.round(n).toLocaleString('en-US'); };

  /* Dropdown panels (<details class="drop">): one open at a time, close on outside click or Esc. */
  document.addEventListener('click', function (e) {
    $$('details.drop[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    $$('details.drop[open]').forEach(function (d) {
      d.removeAttribute('open');
      var s = $('summary', d);
      if (s) s.focus();
    });
  });

  /* Main menu on small screens: the button opens a panel with every page. */
  var menuBtn = $('[data-menu]');
  var top = $('.top');
  if (menuBtn && top) {
    var setMenu = function (open, refocus) {
      top.classList.toggle('is-open', open);
      document.body.classList.toggle('nav-locked', open);
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        var first = $('#main-nav a');
        if (first) first.focus({ preventScroll: true });
      } else if (refocus) {
        menuBtn.focus();
      }
    };
    menuBtn.addEventListener('click', function () { setMenu(!top.classList.contains('is-open'), false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && top.classList.contains('is-open')) setMenu(false, true);
    });
    document.addEventListener('click', function (e) {
      if (top.classList.contains('is-open') && !top.contains(e.target)) setMenu(false, false);
    });
    $$('#main-nav a').forEach(function (a) { a.addEventListener('click', function () { setMenu(false, false); }); });
    // Back to a wide window with the panel open: drop the open state so the page scrolls again.
    window.addEventListener('resize', function () {
      if (window.innerWidth > 880 && top.classList.contains('is-open')) setMenu(false, false);
    });
  }

  /* Confirm before destructive or quota-spending posts. */
  document.addEventListener('submit', function (e) {
    var msg = (e.submitter && e.submitter.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  /* Selects that submit their form on change (sort order, comp radius, stage). */
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]') && e.target.form) e.target.form.submit();
  });

  /* Print / save as PDF. */
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-print]')) window.print();
  });

  /* Copy-to-clipboard buttons. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b || !navigator.clipboard) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
      var old = b.textContent;
      b.textContent = 'Copied';
      setTimeout(function () { b.textContent = old; }, 1400);
    });
  });

  /* Show the alert fields that belong to the chosen kind. */
  var kind = $('[data-kind-switch]');
  if (kind) {
    var syncKind = function () {
      $$('[data-kind]').forEach(function (el) {
        el.hidden = el.getAttribute('data-kind').split(' ').indexOf(kind.value) === -1;
      });
    };
    kind.addEventListener('change', syncKind);
    syncKind();
  }

  /* ── Map ─────────────────────────────────────────────── */
  var mapEl = document.getElementById('map');
  var mapData = readJson('map-data');
  if (mapEl && mapData && window.L && mapData.points && mapData.points.length) {
    var map = L.map(mapEl, { scrollWheelZoom: !!mapData.wheel, zoomControl: true, attributionControl: true });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a>'
    }).addTo(map);

    var bounds = [];
    var pins = {};
    mapData.points.forEach(function (p) {
      var cls = 'pin' + (p.sold ? ' is-sold' : '') + (p.subject ? ' is-subject' : '') + (p.band && p.band !== 'none' ? ' band-' + p.band : '');
      var marker = L.marker([p.lat, p.lng], {
        icon: L.divIcon({ className: 'pin-wrap', html: '<span class="' + cls + '">' + esc(p.label) + '</span>', iconSize: [0, 0] }),
        zIndexOffset: p.subject ? 900 : 0,
        keyboard: false
      }).addTo(map);
      if (p.href) {
        marker.bindPopup('<b>' + esc(p.price) + '</b>' + esc(p.title) + '<br><span>' + esc(p.sub || '') + '</span><br><a href="' + esc(p.href) + '">Open</a>', { closeButton: false, offset: [0, -22] });
      }
      marker.on('mouseover', function () { hot(p.id, true, false); });
      marker.on('mouseout', function () { hot(p.id, false, false); });
      pins[p.id] = marker;
      bounds.push([p.lat, p.lng]);
    });
    if (mapData.circle) {
      L.circle([mapData.circle.lat, mapData.circle.lng], { radius: mapData.circle.miles * 1609.34, color: '#0f766e', weight: 1, fillOpacity: 0.04 }).addTo(map);
    }
    if (bounds.length === 1) map.setView(bounds[0], mapData.zoom || 15);
    else map.fitBounds(bounds, { padding: [36, 36], maxZoom: 16 });

    // Price labels only when there is room for them: few pins, or zoomed in to street level.
    var syncLabels = function () {
      mapEl.classList.toggle('show-labels', !!mapData.labels || mapData.points.length <= 14 || map.getZoom() >= 15);
    };
    map.on('zoomend', syncLabels);
    syncLabels();

    var hot = function (id, on, fromRow) {
      var m = pins[id];
      if (m && m.getElement()) {
        var el = m.getElement().firstChild;
        if (el) el.classList.toggle('is-hot', on);
        m.setZIndexOffset(on ? 1000 : 0);
      }
      if (!fromRow) {
        var row = document.querySelector('[data-pid="' + id + '"]');
        if (row) row.classList.toggle('is-hot', on);
      }
    };
    $$('[data-pid]').forEach(function (row) {
      var id = row.getAttribute('data-pid');
      row.addEventListener('mouseenter', function () { hot(id, true, true); });
      row.addEventListener('mouseleave', function () { hot(id, false, true); });
    });
  }

  /* ── Charts ──────────────────────────────────────────── */
  $$('canvas[data-chart]').forEach(function (cv) {
    var cfg = readJson(cv.getAttribute('data-chart'));
    if (!cfg || !window.Chart) return;
    var palette = ['#0f766e', '#0369a1', '#8f5206', '#4a3f77'];
    var money = cfg.format === 'usd';
    new Chart(cv, {
      type: cfg.type || 'line',
      data: {
        labels: cfg.labels,
        datasets: cfg.series.map(function (s, i) {
          return {
            label: s.name, data: s.data, borderColor: palette[i % palette.length], backgroundColor: palette[i % palette.length],
            borderWidth: 2, pointRadius: s.points ? 3 : 0, pointHoverRadius: 4, tension: 0.25, spanGaps: true, stepped: !!s.stepped
          };
        })
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: cfg.series.length > 1, position: 'bottom', align: 'start', labels: { boxWidth: 10, boxHeight: 2, font: { family: 'Public Sans', size: 12 }, color: '#47524d' } },
          tooltip: {
            backgroundColor: '#18211f', padding: 10, titleFont: { family: 'Public Sans', size: 12 }, bodyFont: { family: 'Public Sans', size: 13 },
            callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + (money ? usd(c.parsed.y) : Number(c.parsed.y).toLocaleString('en-US')); } }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { color: '#cbc6b6' }, ticks: { color: '#66706b', maxTicksLimit: 8, maxRotation: 0, font: { family: 'Public Sans', size: 11.5 } } },
          y: {
            border: { display: false }, grid: { color: '#e0dcd0' },
            ticks: {
              color: '#66706b', maxTicksLimit: 6, font: { family: 'Public Sans', size: 11.5 },
              callback: function (v) { return money ? (v >= 1e6 ? '$' + (v / 1e6).toFixed(1) + 'M' : '$' + Math.round(v / 1000) + 'K') : Number(v).toLocaleString('en-US'); }
            }
          }
        }
      }
    });
  });

  /* ── Places nearby (OpenStreetMap) ───────────────────── */
  var near = $('[data-nearby-url]');
  if (near) {
    fetch(near.getAttribute('data-nearby-url'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        if (!d.places) { near.innerHTML = '<p class="small muted">OpenStreetMap didn’t answer. Reload to try again.</p>'; return; }
        near.innerHTML = d.places.map(function (pl) {
          var v = pl.count === 0 ? '<span class="muted">none within ' + pl.radius + ' mi</span>'
            : pl.count + ' · nearest ' + Number(pl.nearest).toFixed(1) + ' mi';
          return '<div class="ctx-row"><span>' + esc(pl.label) + '</span><span class="num" title="' + esc(pl.name || '') + '">' + v + '</span></div>';
        }).join('') + '<p class="small muted">From OpenStreetMap. Distance is a straight line; it says nothing about quality.</p>';
      })
      .catch(function () { near.innerHTML = '<p class="small muted">Couldn’t load this. Reload to try again.</p>'; });
  }

  /* ── Flood zone + neighbourhood (free lookups, loaded after the page) ── */
  var ctx = $('[data-context-url]');
  if (ctx) {
    var row = function (k, v) { return '<div class="ctx-row"><span>' + esc(k) + '</span><span class="num">' + v + '</span></div>'; };
    fetch(ctx.getAttribute('data-context-url'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        var flood = $('[data-flood]');
        var hood = $('[data-hood]');
        if (flood) {
          if (!d.flood) {
            flood.innerHTML = '<p class="small muted">FEMA didn’t answer. Reload to try again.</p>';
          } else {
            var tag = { high: 'tag-bad', moderate: 'tag-warn', low: 'tag-live', unknown: '' }[d.flood.risk] || '';
            flood.innerHTML = row('FEMA zone', d.flood.zone ? '<span class="tag ' + tag + '">Zone ' + esc(d.flood.zone) + '</span>' : '—')
              + '<p class="small soft">' + esc(d.flood.label) + (d.flood.risk === 'high' ? '. Lenders usually require flood insurance here.' : '.') + '</p>';
          }
        }
        if (hood) {
          var c = d.census;
          if (!c && d.census_key === false) {
            hood.innerHTML = '<p class="small soft">Income, home values and rents for this neighbourhood need a free Census key. <a href="/settings#sources">Add it in Settings</a> — it takes a minute.</p>';
          } else if (!c) {
            hood.innerHTML = '<p class="small muted">No census figures for this spot.</p>';
          } else {
            var n = function (v, pre, post) { return v == null ? '—' : (pre || '') + Math.round(v).toLocaleString('en-US') + (post || ''); };
            hood.innerHTML = row('Median household income', n(c.median_income, '$'))
              + row('Median home value', n(c.median_home_value, '$'))
              + row('Median rent', n(c.median_rent, '$', '/mo'))
              + row('Owner-occupied', c.owner_share == null ? '—' : c.owner_share + '%')
              + row('Vacant homes', c.vacancy == null ? '—' : c.vacancy + '%')
              + row('Typical year built', c.median_year_built ? Math.round(c.median_year_built) : '—')
              + row('Residents', n(c.population))
              + '<p class="small muted">Census tract ' + esc(c.tract_name || '') + ', ACS ' + esc(c.year) + ' five-year estimates.</p>';
          }
        }
      })
      .catch(function () {
        $$('[data-flood], [data-hood]').forEach(function (el) { el.innerHTML = '<p class="small muted">Couldn’t load this. Reload to try again.</p>'; });
      });
  }
})();
