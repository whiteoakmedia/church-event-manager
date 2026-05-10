/**
 * Church Event Manager — Dedicated Check-In Page
 *
 * Simple, tablet-friendly check-in interface.
 * Select event → search by name → tap to check in.
 */
(function ($) {
  'use strict';

  var registrants = [];
  var eventId = 0;

  // ── Load event registrants ──────────────────────────────────────────────

  $('#cem-checkin-event').on('change', function () {
    eventId = parseInt(this.value) || 0;
    var search = $('#cem-checkin-search');
    var grid = $('#cem-checkin-grid');
    var counter = $('#cem-checkin-counter');
    var empty = $('#cem-checkin-empty');

    if (!eventId) {
      search.prop('disabled', true).val('');
      counter.hide();
      grid.html('<div class="cem-checkin-empty" id="cem-checkin-empty"><div class="cem-checkin-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div><p>' + cemCheckin.strings.selectEvent + '</p></div>');
      return;
    }

    grid.html('<div class="cem-checkin-loading">Loading...</div>');

    $.get(cemCheckin.ajaxUrl, {
      action: 'cem_checkin_load',
      event_id: eventId,
      nonce: cemCheckin.nonce
    }).done(function (res) {
      if (!res.success) {
        grid.html('<div class="cem-checkin-empty"><p>' + (res.data.message || cemCheckin.strings.error) + '</p></div>');
        return;
      }

      registrants = res.data.registrants;
      search.prop('disabled', false).focus();

      updateCounter(res.data.checked_in, res.data.total, res.data.capacity);
      renderCards(registrants);

    }).fail(function () {
      grid.html('<div class="cem-checkin-empty"><p>' + cemCheckin.strings.error + '</p></div>');
    });
  });

  // Auto-load if an event is pre-selected
  if ($('#cem-checkin-event').val()) {
    $('#cem-checkin-event').trigger('change');
  }

  // ── Search / filter ─────────────────────────────────────────────────────

  $('#cem-checkin-search').on('input', function () {
    var q = this.value.toLowerCase().trim();
    if (!q) {
      renderCards(registrants);
      return;
    }
    var filtered = registrants.filter(function (r) {
      var name = (r.first_name + ' ' + r.last_name).toLowerCase();
      return name.indexOf(q) !== -1 || r.email.toLowerCase().indexOf(q) !== -1;
    });
    renderCards(filtered);
  });

  // Enter key: check in the first visible unchecked person
  $('#cem-checkin-search').on('keydown', function (e) {
    if (e.which !== 13) return;
    e.preventDefault();
    var firstBtn = $('.cem-checkin-card:visible .cem-checkin-btn:not(.cem-checkin-btn--done)').first();
    if (firstBtn.length) firstBtn.trigger('click');
  });

  // ── Check in ────────────────────────────────────────────────────────────

  $(document).on('click', '.cem-checkin-btn:not(.cem-checkin-btn--done)', function () {
    var btn = $(this);
    var card = btn.closest('.cem-checkin-card');
    var regId = card.data('id');

    btn.prop('disabled', true).text('...');

    $.post(cemCheckin.ajaxUrl, {
      action: 'cem_check_in',
      registration_id: regId,
      nonce: cemCheckin.nonce
    }).done(function (res) {
      if (res.success) {
        // Update local data
        for (var i = 0; i < registrants.length; i++) {
          if (registrants[i].id === regId) {
            registrants[i].status = 'checked_in';
            registrants[i].checked_in_at = 'now';
            break;
          }
        }

        // Visual feedback
        btn.text('✓ ' + cemCheckin.strings.checkedIn)
           .addClass('cem-checkin-btn--done')
           .prop('disabled', true);
        card.addClass('cem-checkin-card--done');

        // Flash animation
        card.addClass('cem-checkin-flash');
        setTimeout(function () { card.removeClass('cem-checkin-flash'); }, 600);

        // Update counter
        var checked = registrants.filter(function (r) { return r.status === 'checked_in'; });
        var totalAtt = 0, checkedAtt = 0;
        registrants.forEach(function (r) {
          totalAtt += r.num_attendees;
          if (r.status === 'checked_in') checkedAtt += r.num_attendees;
        });
        updateCounter(checkedAtt, totalAtt, 0);
      } else {
        btn.prop('disabled', false).text(cemCheckin.strings.checkIn);
        alert(res.data.message || cemCheckin.strings.error);
      }
    }).fail(function () {
      btn.prop('disabled', false).text(cemCheckin.strings.checkIn);
    });
  });

  // ── Render ──────────────────────────────────────────────────────────────

  function renderCards(list) {
    var grid = $('#cem-checkin-grid');

    if (!list.length) {
      grid.html('<div class="cem-checkin-empty"><p>' + cemCheckin.strings.noResults + '</p></div>');
      return;
    }

    var html = '';
    // Show checked-in people at the bottom
    var unchecked = list.filter(function (r) { return r.status !== 'checked_in'; });
    var checked = list.filter(function (r) { return r.status === 'checked_in'; });
    var sorted = unchecked.concat(checked);

    sorted.forEach(function (r) {
      var isDone = r.status === 'checked_in';
      var name = r.first_name + ' ' + r.last_name;
      var attendees = r.num_attendees > 1 ? ' <span class="cem-checkin-att">+' + (r.num_attendees - 1) + ' guest' + (r.num_attendees > 2 ? 's' : '') + '</span>' : '';

      html += '<div class="cem-checkin-card' + (isDone ? ' cem-checkin-card--done' : '') + '" data-id="' + r.id + '">'
        + '<div class="cem-checkin-card-info">'
        + '<div class="cem-checkin-name">' + escHtml(name) + attendees + '</div>'
        + '<div class="cem-checkin-email">' + escHtml(r.email) + (r.phone ? ' &middot; ' + escHtml(r.phone) : '') + '</div>'
        + '</div>'
        + '<button class="cem-checkin-btn' + (isDone ? ' cem-checkin-btn--done' : '') + '"' + (isDone ? ' disabled' : '') + '>'
        + (isDone ? '&#10003; ' + cemCheckin.strings.checkedIn : cemCheckin.strings.checkIn)
        + '</button>'
        + '</div>';
    });

    grid.html(html);
  }

  function updateCounter(checkedIn, total, capacity) {
    var wrap = $('#cem-checkin-counter');
    wrap.show();
    $('#cem-checkin-num').text(checkedIn);
    var detail = checkedIn + ' of ' + total + ' people';
    if (capacity > 0) detail += ' (capacity: ' + capacity + ')';
    $('#cem-checkin-detail').text(detail);

    var pct = total > 0 ? Math.round((checkedIn / total) * 100) : 0;
    $('#cem-checkin-bar').css('width', pct + '%');
  }

  function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ── Walk-in modal ───────────────────────────────────────────────────────
  // Enable the button only when an event is picked.
  $('#cem-checkin-event').on('change', function () {
    $('#cem-walkin-open').prop('disabled', !parseInt(this.value));
  }).trigger('change');

  $('#cem-walkin-open').on('click', function () {
    if (!parseInt($('#cem-checkin-event').val())) return;
    $('#cem-walkin-modal').show();
    $('#cem-walkin-form')[0].reset();
    $('#cem-walkin-msg').text('').removeClass('is-error is-success');
    setTimeout(function () { $('#cem-walkin-form input[name="first_name"]').focus(); }, 50);
  });
  $('#cem-walkin-close, #cem-walkin-modal .cem-modal-overlay').on('click', function () {
    $('#cem-walkin-modal').hide();
  });

  // ── QR SCANNER ──────────────────────────────────────────────────────────
  // Continuous scanner: open camera once, every successful scan immediately
  // checks the matching person in. Volunteer keeps holding the camera up to
  // each registrant's QR until the line is done.
  var scanner = null;          // Html5Qrcode instance
  var scanCooldown = {};       // code → last-scanned-at, prevents the same
                               // QR from being processed 30+ times in a row
                               // when it's held in front of the camera.
  var scanSessionCount = 0;    // visible counter
  var scanCameraId = null;
  var scanCameras = [];

  $('#cem-checkin-event').on('change', function () {
    $('#cem-scan-open').prop('disabled', !parseInt(this.value));
  }).trigger('change');

  $('#cem-scan-open').on('click', openScanner);
  $('#cem-scanner-close, #cem-scanner-modal .cem-modal-overlay').on('click', closeScanner);
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $('#cem-scanner-modal').is(':visible')) closeScanner();
  });

  function openScanner() {
    if (!eventId) {
      alert(cemCheckin.strings.scanNoEvent || 'Pick an event first.');
      return;
    }
    if (typeof Html5Qrcode === 'undefined') {
      alert('Scanner library failed to load.');
      return;
    }

    // Reset session state
    scanSessionCount = 0;
    scanCooldown = {};
    $('#cem-scanner-count').text('0');
    setFeedback('idle', cemCheckin.strings.scanWaiting || 'Waiting for first scan…');

    $('#cem-scanner-modal').show();

    if (!scanner) {
      scanner = new Html5Qrcode('cem-scanner-frame', { verbose: false });
    }

    // Pick a camera. Prefer the rear-facing one on phones/tablets.
    Html5Qrcode.getCameras().then(function (devices) {
      scanCameras = devices || [];
      if (!scanCameras.length) {
        setFeedback('error', cemCheckin.strings.scanCamFail || 'No camera available.');
        return;
      }
      $('#cem-scanner-switch-cam').toggle(scanCameras.length > 1);

      // Heuristic: pick the first "back" / "rear" / "environment" camera if any
      var preferred = scanCameras.find(function (d) {
        return /back|rear|environment/i.test(d.label || '');
      });
      scanCameraId = (preferred || scanCameras[0]).id;

      startScanner(scanCameraId);
    }).catch(function (err) {
      console.error('[cem] camera enumeration failed', err);
      setFeedback('error', cemCheckin.strings.scanCamFail || 'Camera unavailable.');
    });
  }

  function startScanner(cameraId) {
    var config = {
      fps: 10,
      qrbox: { width: 250, height: 250 },
      aspectRatio: 1.0,
    };

    scanner.start(cameraId, config, onScanSuccess, /* onScanFailure */ function () {
      // Per-frame failures are noise (no QR in view yet). Ignore.
    }).catch(function (err) {
      console.error('[cem] scanner start failed', err);
      setFeedback('error', cemCheckin.strings.scanCamFail || 'Camera unavailable.');
    });
  }

  $('#cem-scanner-switch-cam').on('click', function () {
    if (scanCameras.length < 2 || !scanner) return;
    var idx = scanCameras.findIndex(function (d) { return d.id === scanCameraId; });
    var next = scanCameras[(idx + 1) % scanCameras.length];
    scanCameraId = next.id;
    scanner.stop().then(function () {
      startScanner(scanCameraId);
    }).catch(function () {});
  });

  function closeScanner() {
    if (scanner && scanner.isScanning) {
      scanner.stop().then(function () {
        $('#cem-scanner-modal').hide();
      }).catch(function () {
        $('#cem-scanner-modal').hide();
      });
    } else {
      $('#cem-scanner-modal').hide();
    }
  }

  function onScanSuccess(decodedText) {
    // Pull the registration code out of the scanned URL (or accept a
    // bare code if someone encoded just the code instead of the URL).
    var code = parseCode(decodedText);
    if (!code) {
      setFeedback('error', cemCheckin.strings.scanInvalid || "Not a check-in code.");
      return;
    }

    // Cooldown: ignore the same code for 3 seconds. Without this, a held
    // QR fires onScanSuccess 10× per second per fps setting.
    var now = Date.now();
    if (scanCooldown[code] && (now - scanCooldown[code]) < 3000) return;
    scanCooldown[code] = now;

    // Match against the registrant list already loaded for this event.
    // Codes are typically uppercase, but normalize defensively.
    var needle = code.toUpperCase();
    var hit = registrants.find(function (r) {
      return (r.registration_code || '').toUpperCase() === needle;
    });
    if (!hit) {
      setFeedback('error', cemCheckin.strings.scanNotFound || 'Not registered for this event.');
      buzz();
      return;
    }

    if (hit.status === 'checked_in') {
      setFeedback('warning', sprintfName(cemCheckin.strings.scanAlready || 'Already checked in: %s', hit));
      return;
    }

    // Trigger the existing card check-in handler. We don't duplicate the
    // POST here — we just click the same button so the optimistic UI,
    // counter update, and audit-log entry all stay in one place.
    var $card = $('.cem-checkin-card[data-id="' + hit.id + '"]');
    var $btn  = $card.find('.cem-checkin-btn:not(.cem-checkin-btn--done)');
    if ($btn.length) {
      $btn.trigger('click');
      setFeedback('success', sprintfName(cemCheckin.strings.scanSuccess || '✓ Checked in: %s', hit));
      scanSessionCount++;
      $('#cem-scanner-count').text(scanSessionCount);
      chime();
    } else {
      // Card not in current DOM — registrant exists but isn't rendered.
      // Fall back to direct AJAX so we still check them in.
      $.post(cemCheckin.ajaxUrl, {
        action: 'cem_check_in',
        nonce: cemCheckin.nonce,
        event_id: eventId,
        registration_id: hit.id
      }).done(function (res) {
        if (res.success) {
          hit.status = 'checked_in';
          setFeedback('success', sprintfName(cemCheckin.strings.scanSuccess || '✓ Checked in: %s', hit));
          scanSessionCount++;
          $('#cem-scanner-count').text(scanSessionCount);
          chime();
        } else {
          setFeedback('error', (res.data && res.data.message) || cemCheckin.strings.error);
          buzz();
        }
      }).fail(function () {
        setFeedback('error', cemCheckin.strings.error);
        buzz();
      });
    }
  }

  // ── Helpers ────────────────────────────────────────────────────────────
  function parseCode(decoded) {
    if (!decoded) return null;
    decoded = String(decoded).trim();
    // URL with cem_code= param
    var m = decoded.match(/[?&]cem_code=([A-Za-z0-9-]+)/);
    if (m) return m[1];
    // Bare code (uppercase alphanumeric)
    if (/^[A-Z0-9]{6,20}$/.test(decoded)) return decoded;
    return null;
  }

  function sprintfName(fmt, hit) {
    var name = (hit.first_name + ' ' + hit.last_name).trim();
    return fmt.replace('%s', name);
  }

  function setFeedback(state, text) {
    var $fb = $('#cem-scanner-feedback');
    $fb.removeClass('is-success is-warning is-error is-idle')
       .addClass('is-' + state);
    $fb.find('.cem-scanner-feedback-text').text(text);
  }

  // Audio cues — Web Audio "ka-ching" beep, no asset file needed.
  var audioCtx = null;
  function getAudioCtx() {
    if (audioCtx) return audioCtx;
    var Ctx = window.AudioContext || window.webkitAudioContext;
    audioCtx = Ctx ? new Ctx() : null;
    return audioCtx;
  }
  function chime() {
    if (!$('#cem-scanner-sound').is(':checked')) return;
    var ctx = getAudioCtx(); if (!ctx) return;
    tone(ctx, 880, 0.07);
    setTimeout(function () { tone(ctx, 1320, 0.10); }, 70);
  }
  function buzz() {
    if (!$('#cem-scanner-sound').is(':checked')) return;
    var ctx = getAudioCtx(); if (!ctx) return;
    tone(ctx, 220, 0.15, 'square');
  }
  function tone(ctx, freq, dur, type) {
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = type || 'sine';
    osc.frequency.value = freq;
    osc.connect(gain);
    gain.connect(ctx.destination);
    gain.gain.setValueAtTime(0, ctx.currentTime);
    gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + dur);
    osc.start();
    osc.stop(ctx.currentTime + dur + 0.02);
  }

  $('#cem-walkin-form').on('submit', function (e) {
    e.preventDefault();
    var $form = $(this);
    var msg = $('#cem-walkin-msg');
    var btn = $form.find('button[type="submit"]');
    btn.prop('disabled', true).text('Adding…');
    msg.text('').removeClass('is-error is-success');

    var data = {
      action: 'cem_walkin_register',
      nonce: cemCheckin.nonce,
      event_id: eventId,
      first_name: $form.find('[name="first_name"]').val(),
      last_name:  $form.find('[name="last_name"]').val(),
      email:      $form.find('[name="email"]').val(),
      phone:      $form.find('[name="phone"]').val(),
      num_attendees: $form.find('[name="num_attendees"]').val()
    };

    $.post(cemCheckin.ajaxUrl, data).done(function (res) {
      btn.prop('disabled', false).text('Add & Check In');
      if (!res.success) {
        msg.text(res.data && res.data.message ? res.data.message : 'Failed to add walk-in.').addClass('is-error');
        return;
      }
      msg.text('✓ Added & checked in').addClass('is-success');
      // Reload the list so the new walk-in appears immediately.
      $('#cem-checkin-event').trigger('change');
      setTimeout(function () { $('#cem-walkin-modal').hide(); }, 700);
    }).fail(function () {
      btn.prop('disabled', false).text('Add & Check In');
      msg.text('Network error.').addClass('is-error');
    });
  });

})(jQuery);
