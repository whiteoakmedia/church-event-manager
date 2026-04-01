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

})(jQuery);
