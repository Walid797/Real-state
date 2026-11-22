/* Plat — page behaviour. No framework; every block is independent and exits early when its markup is absent. */
(function () {
  'use strict';

  var labels = JSON.parse(document.documentElement.getAttribute('data-ui-labels') || '{}');
  var ui = function (text) {
    return Object.prototype.hasOwnProperty.call(labels, text) ? labels[text] : text;
  };

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

  /* Preserve address-line boundaries when a multiline postal address is pasted into a search input. */
  $$('input[name="q"]').forEach(function (input) {
    input.addEventListener('paste', function (event) {
      var text = event.clipboardData && event.clipboardData.getData('text');
      if (!text || !/[\r\n]/.test(text)) return;
      event.preventDefault();
      text = text.split(/[\r\n]+/).map(function (line) { return line.trim().replace(/,+$/, ''); }).filter(Boolean).join(', ');
      var start = input.selectionStart == null ? input.value.length : input.selectionStart;
      var end = input.selectionEnd == null ? start : input.selectionEnd;
      var next = input.value.slice(0, start) + text + input.value.slice(end);
      input.value = input.maxLength > 0 ? next.slice(0, input.maxLength) : next;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

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

  /* Welcome page: scrolling plays a drone flight around a real house, frame by frame.
     Frames load in a spread-out order, so any scroll position soon has one close by. */
  var track = $('.wl-scroll');
  var media = $('.wl-media');
  var stage = $('.welcome');
  if (track && media && stage && window.matchMedia && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.body.classList.add('wl-anim');
    var canvas = $('canvas', media);
    var ctx2d = canvas.getContext('2d');
    var count = parseInt(media.getAttribute('data-count'), 10) || 0;
    var small = Math.min(window.innerWidth, window.innerHeight * 16 / 9) * Math.min(window.devicePixelRatio || 1, 2) < 1100;
    var base = media.getAttribute('data-frames') + (small ? '/sm/' : '/lg/');
    var frames = new Array(count);
    var want = 0;
    var drawn = -1;
    var pad = function (n) { return ('00' + n).slice(-3); };

    var size = function () {
      var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      canvas.width = Math.round(media.clientWidth * dpr);
      canvas.height = Math.round(media.clientHeight * dpr);
      drawn = -1;
    };
    var nearest = function (i) {
      for (var d = 0; d < count; d++) {
        if (frames[i - d] && frames[i - d].ok) return i - d;
        if (frames[i + d] && frames[i + d].ok) return i + d;
      }
      return -1;
    };
    var draw = function () {
      var i = nearest(want);
      if (i < 0 || i === drawn) return;
      var img = frames[i].img;
      var cw = canvas.width, ch = canvas.height;
      var s = Math.max(cw / img.naturalWidth, ch / img.naturalHeight);   // cover, like object-fit
      var w = img.naturalWidth * s, h = img.naturalHeight * s;
      ctx2d.drawImage(img, (cw - w) / 2, (ch - h) / 2, w, h);
      drawn = i;
      media.classList.remove('no-frames');
    };
    var load = function (i) {
      if (frames[i]) return;
      var img = new Image();
      frames[i] = { img: img, ok: false };
      img.decoding = 'async';
      img.onload = function () { frames[i].ok = true; if (Math.abs(i - want) <= Math.abs(drawn - want)) draw(); };
      img.src = base + pad(i + 1) + '.webp';
    };
    // Every 16th frame first, then every 8th, 4th, 2nd, then the rest.
    var order = [];
    [16, 8, 4, 2, 1].forEach(function (step) {
      for (var i = 0; i < count; i += step) if (order.indexOf(i) === -1) order.push(i);
    });
    var next = 0;
    var pump = function () {
      var batch = 6;
      while (batch-- > 0 && next < order.length) load(order[next++]);
      if (next < order.length) setTimeout(pump, 60);
    };
    media.classList.add('no-frames');
    size();
    load(0);
    pump();

    var cards = [['--c1', 0.47, 0.52], ['--c2', 0.50, 0.55], ['--c3', 0.53, 0.58], ['--c4', 0.56, 0.61]];
    var chapterAt = [0, 0.12, 0.28, 0.44, 0.63, 0.80];
    var chapters = $$('.wl-ch');
    var dots = $$('[data-dot]');
    var paint = function () {
      var r = track.getBoundingClientRect();
      var p = Math.min(1, Math.max(0, -r.top / Math.max(1, r.height - window.innerHeight)));
      want = Math.round(Math.min(1, p / 0.94) * (count - 1));
      draw();
      stage.style.setProperty('--p', p.toFixed(4));
      cards.forEach(function (c) {
        var t = Math.min(1, Math.max(0, (p - c[1]) / (c[2] - c[1])));
        if (p > 0.62) t = Math.max(0, 1 - (p - 0.62) / 0.03);              // gone again for the next chapter
        stage.style.setProperty(c[0], t.toFixed(3));
      });
      var on = 0;
      chapterAt.forEach(function (at, i) { if (p >= at) on = i; });
      chapters.forEach(function (c, i) { c.classList.toggle('is-on', i === on); });
      dots.forEach(function (d, i) { d.classList.toggle('is-on', i === on); });
    };
    var queued = false;
    var onScroll = function () {
      if (queued) return;
      queued = true;
      window.requestAnimationFrame(function () { queued = false; paint(); });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', function () { size(); onScroll(); });
    paint();
  }

  /* Confirm before destructive or quota-spending posts. */
  document.addEventListener('submit', function (e) {
    var msg = (e.submitter && e.submitter.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  /* Delete bars: count the ticked rows, "select all", and a disabled "delete selected" until one is ticked. */
  document.querySelectorAll('form[data-bulk]').forEach(function (bar) {
    var id = bar.id;
    var picks = function () { return document.querySelectorAll('input.bulk-pick[form="' + id + '"]'); };
    var all = bar.querySelector('[data-bulk-all]');
    var btn = bar.querySelector('[data-bulk-selected]');
    var out = bar.querySelector('[data-bulk-count]');
    var update = function () {
      var list = picks(), n = 0;
      list.forEach(function (c) { if (c.checked) n++; });
      out.textContent = String(n);
      btn.disabled = n === 0;
      all.checked = n > 0 && n === list.length;
      all.indeterminate = n > 0 && n < list.length;
    };
    all.addEventListener('change', function () { picks().forEach(function (c) { c.checked = all.checked; }); update(); });
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('input.bulk-pick[form="' + id + '"]')) update(); });
    if (!picks().length) bar.hidden = true;
    update();
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
      b.textContent = ui('Copied');
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
    var map = L.map(mapEl, { scrollWheelZoom: !!mapData.wheel, zoomControl: false, attributionControl: true });
    L.control.zoom({ zoomInTitle: ui('Zoom in'), zoomOutTitle: ui('Zoom out') }).addTo(map);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      // OpenStreetMap refuses tiles without a Referer (its tile usage policy); the site sends
      // none cross-origin, so the tiles send just the site's address.
      referrerPolicy: 'strict-origin-when-cross-origin',
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

  /* ── Places nearby (OpenStreetMap) ─────────────────────
     The server keeps a tally once one exists. If not, this browser asks OpenStreetMap
     directly (the public servers rarely answer shared hosting), counts what is near,
     shows it, and hands the tally back so the next visit is instant. */
  var near = $('[data-nearby-url]');
  if (near) {
    var url = near.getAttribute('data-nearby-url');
    var show = function (places) {
      near.innerHTML = places.map(function (pl) {
        var v = pl.count === 0 ? '<span class="muted">none within ' + pl.radius + ' mi</span>'
          : pl.count + ' · nearest ' + Number(pl.nearest).toFixed(1) + ' mi';
        return '<div class="ctx-row"><span>' + esc(pl.label) + '</span><span class="num" title="' + esc(pl.name || '') + '">' + v + '</span></div>';
      }).join('') + '<p class="small muted">' + esc(ui('From OpenStreetMap. Distance is a straight line; it says nothing about quality.')) + '</p>';
    };
    var fail = function () { near.innerHTML = '<p class="small muted">' + esc(ui('OpenStreetMap didn’t answer. Reload to try again.')) + '</p>'; };
    var miles = function (a1, o1, a2, o2) {
      var r = Math.PI / 180, dA = (a2 - a1) * r, dO = (o2 - o1) * r;
      var h = Math.sin(dA / 2) * Math.sin(dA / 2) + Math.cos(a1 * r) * Math.cos(a2 * r) * Math.sin(dO / 2) * Math.sin(dO / 2);
      return 3958.8 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    };
    var kindOf = function (t) {
      if (/^(school|kindergarten)$/.test(t.amenity || '')) return 'school';
      if (/^(hospital|clinic|doctors|urgent_care)$/.test(t.amenity || '')) return 'health';
      if (t.shop) return 'grocery';
      if (t.highway === 'bus_stop' || t.railway) return 'transit';
      if (t.highway === 'motorway_junction') return 'highway';
      if (t.leisure === 'park') return 'park';
      return null;
    };
    var tally = function (lk, elements) {
      var out = {};
      Object.keys(lk.kinds).forEach(function (k) { out[k] = { count: 0, nearest: null, name: null }; });
      elements.forEach(function (el) {
        var t = el.tags || {}, k = kindOf(t);
        var la = el.lat != null ? el.lat : el.center && el.center.lat, lo = el.lon != null ? el.lon : el.center && el.center.lon;
        if (!k || la == null || lo == null) return;
        var d = miles(lk.lat, lk.lng, la, lo);
        out[k].count++;
        if (out[k].nearest === null || d < out[k].nearest) {
          out[k].nearest = Math.round(d * 100) / 100;
          out[k].name = t.name ? String(t.name).slice(0, 80) : (k === 'highway' && t.ref ? 'Exit ' + t.ref : null);
        }
      });
      return out;
    };
    var ask = function (servers, query, i) {
      if (i >= servers.length) return Promise.reject(new Error('no server answered'));
      var ctl = window.AbortController ? new AbortController() : null;
      var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 14000);
      return fetch(servers[i], { method: 'POST', body: new URLSearchParams({ data: query }), signal: ctl ? ctl.signal : undefined })
        .then(function (r) { clearTimeout(timer); if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (j) { if (!j || !j.elements) throw new Error('bad answer'); return j.elements; })
        .catch(function () { clearTimeout(timer); return ask(servers, query, i + 1); });
    };
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        if (d.places) { show(d.places); return; }
        if (!d.lookup) { fail(); return; }
        var lk = d.lookup;
        return ask(lk.servers, lk.query, 0).then(function (elements) {
          var t = tally(lk, elements);
          show(Object.keys(lk.kinds).map(function (k) {
            return { label: lk.kinds[k], radius: lk.radius[k] || 1, count: t[k].count, nearest: t[k].nearest, name: t[k].name };
          }));
          fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': near.getAttribute('data-csrf') || '' },
            body: JSON.stringify({ places: t })
          }).catch(function () {});
        });
      })
      .catch(fail);
  }

  /* ── Full listing details: fetched once by the site, right after a home is first opened ── */
  var auto = $('[data-auto-details]');
  if (auto) {
    var autoMsg = $('.auto-details-msg', auto);
    fetch(auto.getAttribute('data-auto-details'), {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': auto.getAttribute('data-csrf') || '' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.status === 'fetched') {
        // Everything on the page (tax, HOA, condition, score) changes, so show it fresh.
        if (autoMsg) autoMsg.textContent = ui('Listing details found — updating the page…');
        setTimeout(function () { window.location.reload(); }, 400);
      } else if (autoMsg) {
        autoMsg.textContent = d.message || ui('No further details from the listing.');
      }
    }).catch(function () { if (autoMsg) autoMsg.textContent = ui('Couldn’t fetch the listing details. Reload to try again.'); });
  }

  /* ── Listing text in the site's language: translated once on the server, then kept ── */
  var tr = $('.desc-tr');
  if (tr) {
    var trOut = $('.desc-translated', tr), trOrig = $('.desc-original', tr), trNote = $('.desc-tr-note', tr), trBtn = $('.desc-toggle', tr);
    var showOrig = function (on) {
      trOrig.hidden = !on;
      trBtn.textContent = ui(on ? 'Hide original' : 'Show original');
    };
    trBtn.addEventListener('click', function () { showOrig(trOrig.hidden); });
    if (tr.hasAttribute('data-translate')) {
      fetch(tr.getAttribute('data-translate'), {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': tr.getAttribute('data-csrf') || '' }
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok && d.text) {
          trOut.textContent = d.text;
          trNote.textContent = ui('Machine translation of the listing text.');
          trBtn.hidden = false;
          showOrig(false);
        } else {
          trOut.hidden = true;
          trNote.textContent = d.error || ui('Couldn’t translate this.');
        }
      }).catch(function () { trOut.hidden = true; trNote.textContent = ui('Couldn’t translate this.'); });
    }
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
            flood.innerHTML = '<p class="small muted">' + esc(ui('FEMA didn’t answer. Reload to try again.')) + '</p>';
          } else {
            var tag = { high: 'tag-bad', moderate: 'tag-warn', low: 'tag-live', unknown: '' }[d.flood.risk] || '';
            flood.innerHTML = row(ui('FEMA zone'), d.flood.zone ? '<span class="tag ' + tag + '">' + esc(ui('Zone')) + ' ' + esc(d.flood.zone) + '</span>' : '—')
              + '<p class="small soft">' + esc(d.flood.label) + (d.flood.risk === 'high' ? '. Lenders usually require flood insurance here.' : '.') + '</p>';
          }
        }
        if (hood) {
          var c = d.census;
          if (!c && d.census_key === false) {
            hood.innerHTML = '<p class="small soft">' + esc(ui('Income, home values and rents for this neighbourhood need a free Census key.')) + ' <a href="/settings#sources">' + esc(ui('Add it in Settings')) + '</a>.</p>';
          } else if (!c) {
            hood.innerHTML = '<p class="small muted">' + esc(ui('No census figures for this spot.')) + '</p>';
          } else {
            var n = function (v, pre, post) { return v == null ? '—' : (pre || '') + Math.round(v).toLocaleString('en-US') + (post || ''); };
            hood.innerHTML = row(ui('Median household income'), n(c.median_income, '$'))
              + row(ui('Median home value'), n(c.median_home_value, '$'))
              + row(ui('Median rent'), n(c.median_rent, '$', '/mo'))
              + row(ui('Owner-occupied'), c.owner_share == null ? '—' : c.owner_share + '%')
              + row(ui('Vacant homes'), c.vacancy == null ? '—' : c.vacancy + '%')
              + row(ui('Typical year built'), c.median_year_built ? Math.round(c.median_year_built) : '—')
              + row(ui('Residents'), n(c.population))
              + '<p class="small muted">Census tract ' + esc(c.tract_name || '') + ', ACS ' + esc(c.year) + ' five-year estimates.</p>';
          }
        }
      })
      .catch(function () {
        $$('[data-flood], [data-hood]').forEach(function (el) { el.innerHTML = '<p class="small muted">' + esc(ui('Couldn’t load this. Reload to try again.')) + '</p>'; });
      });
  }
})();

/* Opening a property starts only that home's report; polling never selects another home. */
(function () {
  var host = document.querySelector('[data-intelligence-url]');
  if (!host) return;
  var running = false;
  var polls = 0;
  var timer = null;
  function schedule(delay) {
    clearTimeout(timer);
    if (polls < 60) timer = setTimeout(poll, delay);
  }
  function start(data) {
    var due = !data.next_attempt_at || Date.parse(data.next_attempt_at.replace(' ', 'T') + 'Z') <= Date.now();
    var needed = data.status === 'pending' || (data.status === 'evidence_ready' && data.configured)
      || (data.status === 'awaiting_key' && data.configured) || (data.status === 'retry' && due);
    if (running || !needed) return;
    running = true;
    var body = new URLSearchParams({ property_id: host.getAttribute('data-property-id') });
    fetch('/api/property-analysis', {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': host.getAttribute('data-csrf') || '' },
      body: body
    }).then(function (r) {
      if (!r.ok) throw new Error('Analysis request failed');
      return r.json();
    }).catch(function () {
      // A gateway timeout can occur while the server continues collecting evidence.
    }).finally(function () {
      running = false;
      schedule(1000);
    });
  }
  function poll() {
    if (document.hidden || polls >= 60) return;
    polls += 1;
    fetch(host.getAttribute('data-intelligence-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('Report status unavailable'); return r.json(); })
      .then(function (data) {
        host.innerHTML = data.html;
        start(data);
        if (['ready', 'awaiting_key', 'failed'].indexOf(data.status) < 0 || running) schedule(5000);
      })
      .catch(function () { schedule(15000); });
  }
  schedule(50);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(50); });
})();
