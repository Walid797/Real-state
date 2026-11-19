/* Look around: a 3D flight around one home, using CesiumJS (self-hosted, Apache-2.0) and
   Google's Photorealistic 3D Tiles through the team's Cesium ion account.
   Nothing heavy loads until someone presses "Open 3D view". */
(function () {
  'use strict';

  var labels = JSON.parse(document.documentElement.getAttribute('data-ui-labels') || '{}');
  var ui = function (text) { return labels[text] || text; };

  var box = document.querySelector('[data-aerial]');
  if (!box) return;
  var stage = box.querySelector('.aerial-stage');
  var cover = box.querySelector('.aerial-cover');
  var bar = box.querySelector('.aerial-bar');
  var msg = box.querySelector('.aerial-msg');
  var openBtn = box.querySelector('[data-aerial-open]');
  var BASE = '/assets/vendor/cesium/';

  var say = function (text) { msg.hidden = !text; msg.textContent = text || ''; };

  var loadCss = function (href) {
    if (document.querySelector('link[href="' + href + '"]')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.href = href;
    document.head.appendChild(l);
  };
  var loadScript = function (src) {
    return new Promise(function (ok, fail) {
      if (window.Cesium) return ok();
      var s = document.createElement('script');
      s.src = src; s.onload = ok; s.onerror = function () { fail(new Error(ui('Could not load the 3D viewer.'))); };
      document.head.appendChild(s);
    });
  };

  var ask = function () {
    return fetch(box.getAttribute('data-aerial'), {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': box.getAttribute('data-csrf') }
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) throw new Error(d.error || 'The 3D view could not start (' + r.status + ').');
        return d;
      });
    });
  };

  var start = function (d) {
    var C = window.Cesium;
    C.Ion.defaultAccessToken = d.token;
    var widget = new C.CesiumWidget(stage, {
      baseLayer: false, globe: false, skyBox: false, skyAtmosphere: false,
      scene3DOnly: true, requestRenderMode: false, msaaSamples: 4,
      showRenderLoopErrors: false
    });
    var scene = widget.scene;
    var camera = widget.camera;
    scene.backgroundColor = C.Color.fromCssColorString('#dfe9ee');

    var home = C.Cartesian3.fromDegrees(d.lng, d.lat, 0);
    var range = 260, pitch = C.Math.toRadians(-32), heading = C.Math.toRadians(20);
    var orbiting = true;
    var target = home;

    var aim = function () {
      camera.lookAt(target, new C.HeadingPitchRange(heading, pitch, range));
    };

    // A small marker on the house, visible through anything in front of it.
    var pin = function (pos) {
      widget.entities.add({
        position: pos,
        point: { pixelSize: 11, color: C.Color.fromCssColorString('#0f766e'), outlineColor: C.Color.WHITE, outlineWidth: 2, disableDepthTestDistance: Number.POSITIVE_INFINITY },
        label: {
          text: d.label, font: '600 13px "Public Sans", sans-serif', fillColor: C.Color.WHITE,
          showBackground: true, backgroundColor: C.Color.fromCssColorString('rgba(15,21,19,.78)'),
          pixelOffset: new C.Cartesian2(0, -22), disableDepthTestDistance: Number.POSITIVE_INFINITY
        }
      });
    };

    C.createGooglePhotorealistic3DTileset({ onlyUsingWithGoogleGeocoder: true }).then(function (tileset) {
      scene.primitives.add(tileset);
      aim();
      // Put the camera's pivot on the ground (or roof) at the house, not at sea level.
      var carto = C.Cartographic.fromDegrees(d.lng, d.lat);
      var settle = function () {
        scene.sampleHeightMostDetailed([carto]).then(function (r) {
          var h = r && r[0] && isFinite(r[0].height) ? r[0].height : 0;
          target = C.Cartesian3.fromDegrees(d.lng, d.lat, h);
          pin(C.Cartesian3.fromDegrees(d.lng, d.lat, h + 2));
          aim();
        }).catch(function () { pin(home); });
      };
      // let the first tiles arrive before measuring
      var once = tileset.initialTilesLoaded.addEventListener(function () { once(); settle(); });
      setTimeout(function () { if (target === home) settle(); }, 6000);
    }).catch(function (e) {
      var why = String(e && e.message ? e.message : e).replace(/\.+$/, '');
      say(/40[13]/.test(why)
        ? 'Cesium refused this request (' + why + '). In ion, check that the token’s Allowed URLs include ' + location.origin + ' and that Google Photorealistic 3D Tiles is in My Assets.'
        : 'Google’s 3D tiles did not load (' + why + '). Reload to try again.');
    });

    // Slow orbit until someone takes the controls.
    var spin = C.Math.toRadians(0.12);
    scene.preRender.addEventListener(function () {
      if (orbiting) { heading += spin; aim(); }
    });
    var stop = function () { setOrbit(false); };
    stage.addEventListener('pointerdown', stop);
    stage.addEventListener('wheel', stop, { passive: true });
    stage.addEventListener('touchstart', stop, { passive: true });

    var orbitBtn = box.querySelector('[data-aerial-orbit]');
    var setOrbit = function (on) {
      orbiting = on;
      if (!on) {
        // hand the camera back to the user, still pivoting around the house
        heading = camera.heading; pitch = camera.pitch;
        range = C.Cartesian3.distance(camera.positionWC, target);
      }
      orbitBtn.querySelector('span').textContent = on ? ui('Pause') : ui('Orbit');
    };
    orbitBtn.addEventListener('click', function () { setOrbit(!orbiting); });
    box.querySelector('[data-aerial-reset]').addEventListener('click', function () {
      range = 260; pitch = C.Math.toRadians(-32); aim(); setOrbit(true);
    });
    box.querySelector('[data-aerial-full]').addEventListener('click', function () {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (box.requestFullscreen) box.requestFullscreen();
    });
  };

  openBtn.addEventListener('click', function () {
    openBtn.disabled = true;
    say(ui('Loading the 3D viewer…'));
    loadCss(BASE + 'Widgets/widgets.css');
    Promise.all([ask(), loadScript(BASE + 'Cesium.js')]).then(function (r) {
      var d = r[0];
      cover.hidden = true;
      bar.hidden = false;
      box.classList.add('is-open');
      say('');
      var cost = box.querySelector('.aerial-cost');
      if (cost) cost.textContent = d.left + ' 3D views left this month.';
      start(d);
    }).catch(function (e) {
      openBtn.disabled = false;
      say(e.message || String(e));
    });
  });
})();
