/* Church Event Manager — Admin JS */
(function ($) {
  'use strict';

  const ajax    = cemAdmin.ajaxUrl;
  const nonce   = cemAdmin.nonce;

  // ── Utility ────────────────────────────────────────────────────────────────

  function showNotice(container, message, type) {
    const cls = type === 'success' ? 'notice-success' : (type === 'error' ? 'notice-error' : 'notice-info');
    $(container).html('<div class="notice ' + cls + ' is-dismissible"><p>' + message + '</p></div>');
  }

  function getCheckedIds(cls) {
    const ids = [];
    $(cls + ':checked').each(function () { ids.push($(this).val()); });
    return ids;
  }

  // ── Select All ─────────────────────────────────────────────────────────────

  $('#cem-select-all').on('change', function () {
    $('.cem-reg-cb').prop('checked', this.checked);
  });

  // ── Check In ──────────────────────────────────────────────────────────────

  $(document).on('click', '.cem-check-in-btn', function () {
    const btn   = $(this);
    const regId = btn.data('id');
    btn.prop('disabled', true).text('…');

    $.post(ajax, { action: 'cem_check_in', nonce, registration_id: regId }, function (res) {
      if (res.success) {
        btn.closest('tr').find('td:nth-child(6)').html('<span class="cem-badge cem-badge--purple">Checked In</span>');
        btn.remove();
      } else {
        alert(res.data.message);
        btn.prop('disabled', false).text('✔');
      }
    });
  });

  // ── View Registration Details ──────────────────────────────────────────────

  $(document).on('click', '.cem-view-reg', function (e) {
    e.preventDefault();
    const regId = $(this).data('id');
    const modal = $('#cem-reg-modal');
    const body  = $('#cem-reg-modal-body');

    body.html('<p class="cem-muted">' + cemAdmin.strings.loading + '</p>');
    modal.show();

    $.get(ajax, { action: 'cem_get_reg_details', nonce, registration_id: regId }, function (res) {
      if (!res.success) { body.html('<p>' + res.data.message + '</p>'); return; }
      const r    = res.data.registration;
      const meta = res.data.meta;
      const ev   = res.data.event_title;

      let html = '<h2>' + r.first_name + ' ' + r.last_name + '</h2>';
      html += '<table class="cem-table widefat"><tbody>';
      html += row('Event', ev);
      html += row('Email', '<a href="mailto:' + r.email + '">' + r.email + '</a>');
      html += row('Phone', r.phone || '—');
      html += row('Attendees', r.num_attendees);
      html += row('Status', r.status);
      html += row('Code', '<code>' + r.registration_code + '</code>');
      html += row('Registered', r.created_at);
      if (r.checked_in_at) html += row('Checked In', r.checked_in_at);
      if (r.notes)         html += row('Notes', r.notes);

      if (meta && Object.keys(meta).length) {
        html += '<tr><td colspan="2"><strong>Custom Fields</strong></td></tr>';
        Object.keys(meta).forEach(function (k) {
          html += row(k, meta[k]);
        });
      }

      html += '</tbody></table>';

      html += '<div style="margin-top:14px;display:flex;gap:8px">';
      if (r.status !== 'checked_in') {
        html += '<button class="button button-primary cem-check-in-btn" data-id="' + r.id + '">✔ Check In</button>';
      }
      html += '<button class="button cem-delete-reg" data-id="' + r.id + '">🗑 Delete</button>';
      html += '</div>';

      body.html(html);
    });
  });

  function row(label, value) {
    return '<tr><th style="width:140px;font-size:12px;color:#718096;text-transform:uppercase">' + label + '</th><td>' + (value || '—') + '</td></tr>';
  }

  // Modal close
  $(document).on('click', '.cem-modal-close, .cem-modal-overlay', function () {
    $('#cem-reg-modal').hide();
  });
  $(document).on('keyup', function (e) {
    if (e.key === 'Escape') $('#cem-reg-modal').hide();
  });

  // ── Delete Registration ────────────────────────────────────────────────────

  $(document).on('click', '.cem-delete-reg', function () {
    if (!confirm(cemAdmin.confirmDelete)) return;
    const btn   = $(this);
    const regId = btn.data('id');

    $.post(ajax, { action: 'cem_delete_registration', nonce, registration_id: regId }, function (res) {
      if (res.success) {
        $('tr[data-id="' + regId + '"]').fadeOut(300, function () { $(this).remove(); });
        $('#cem-reg-modal').hide();
      } else {
        alert(res.data.message);
      }
    });
  });

  // ── Promote from Waitlist ─────────────────────────────────────────────────

  $(document).on('click', '.cem-promote-btn', function () {
    const btn   = $(this);
    const regId = btn.data('id');
    btn.prop('disabled', true).text('…');

    $.post(ajax, { action: 'cem_waitlist_promote', nonce, registration_id: regId }, function (res) {
      if (res.success) {
        location.reload();
      } else {
        alert(res.data.message);
        btn.prop('disabled', false).text('⬆');
      }
    });
  });

  // ── Bulk Actions ──────────────────────────────────────────────────────────

  $('#cem-apply-bulk').on('click', function () {
    const action = $('#cem-bulk-action').val();
    if (!action) return alert('Please select an action.');

    const ids = getCheckedIds('.cem-reg-cb');
    if (!ids.length) return alert('Please select at least one registration.');

    if (action === 'reminder') {
      // Send reminders
      $.post(ajax, { action: 'cem_send_reminder', nonce, registration_ids: ids }, function (res) {
        alert(res.success ? res.data.message : res.data.message);
      });
    } else {
      // Status change
      $.post(ajax, { action: 'cem_update_reg_status', nonce, ids, status: action }, function (res) {
        if (res.success) {
          showNotice('.cem-results-count', res.data.message, 'success');
          setTimeout(() => location.reload(), 1200);
        } else {
          alert(res.data.message);
        }
      });
    }
  });

  // ── Email Center — Preview Recipients ────────────────────────────────────

  $('#cem-preview-recipients').on('click', function () {
    const eventId = $('#cem-email-event').val();
    const statusVal = $('input[name="cem_email_status"]:checked').val();

    // Use registered registrations AJAX to build list
    const args = { action: 'cem_get_reg_details_bulk', nonce, event_id: eventId, status: statusVal };
    // Fallback: just pull from page
    let recipientHtml = '';
    let count = 0;

    // If no event selected, show all
    const statusFilter = statusVal === 'all' ? '' : statusVal;
    const params = new URLSearchParams({ action: 'cem_get_recipients_preview', nonce, event_id: eventId, status: statusFilter });

    $.get(ajax + '?' + params.toString(), function (res) {
      if (res.success && res.data.recipients) {
        res.data.recipients.forEach(function (r) {
          recipientHtml += '<div class="cem-recipient-item"><span>' + r.name + '</span><span class="cem-muted">' + r.email + '</span></div>';
          count++;
        });
        $('#cem-recipient-count').text(count);
        $('#cem-recipient-list').html(recipientHtml || '<p class="cem-muted">No recipients found.</p>');
        $('#cem-email-preview-wrap').slideDown();
        // Store ids
        window.cemEmailRecipientIds = res.data.ids;
      }
    });
  });

  // ── Email Center — Send Bulk Email ───────────────────────────────────────

  $('#cem-send-bulk-email').on('click', function () {
    const subject = $('#cem-email-subject').val().trim();
    const message = (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor)
      ? tinyMCE.activeEditor.getContent()
      : $('#cem_email_body').val();

    if (!subject)  return alert('Please enter a subject.');
    if (!message)  return alert('Please enter a message.');

    const ids = window.cemEmailRecipientIds;
    if (!ids || !ids.length) return alert('No recipients selected.');

    if (!confirm(cemAdmin.confirmBulkEmail)) return;

    const btn = $(this);
    btn.prop('disabled', true).text(cemAdmin.strings.sending);

    $.post(ajax, {
      action: 'cem_bulk_email',
      nonce,
      registration_ids: ids,
      subject,
      message,
      event_id: $('#cem-email-event').val(),
    }, function (res) {
      btn.prop('disabled', false).text('✉️ Send Email to All Recipients');
      const type = res.success ? 'success' : 'error';
      showNotice('#cem-email-result', res.data.message, type);
    });
  });

  // ── Settings save ──────────────────────────────────────────────────────────

  $('#cem-save-settings').on('click', function () {
    const btn  = $(this);
    const form = $('#cem-settings-form');
    const data = form.serialize() + '&action=cem_save_settings&nonce=' + cemAdmin.settingsNonce;

    btn.prop('disabled', true).text(cemAdmin.strings.loading);

    $.post(ajax, data, function (res) {
      btn.prop('disabled', false).text('Save Settings');
      const type = res.success ? 'success' : 'error';
      showNotice('#cem-settings-messages', res.success ? cemAdmin.strings.saved : res.data.message, type);
    });
  });

  // Also save page dropdowns via native form post
  $('#cem-settings-form select[name="cem_events_page_id"], #cem-settings-form select[name="cem_my_registrations_page_id"]').on('change', function () {
    const key = $(this).attr('name');
    const val = $(this).val();
    $.post(ajax, { action: 'cem_save_settings', nonce: cemAdmin.settingsNonce, [key]: val }, function () {});
  });

  // ── Color picker ──────────────────────────────────────────────────────────

  if ($.fn.wpColorPicker) {
    $('.cem-color-picker').wpColorPicker();
  }

  // ── Datepicker enhancement ────────────────────────────────────────────────

  // WordPress adds jQuery UI datepicker; datetime-local inputs work natively
  // in modern browsers, nothing extra needed.

  // ── Sortable fields table ─────────────────────────────────────────────────

  if ($.fn.sortable) {
    $('#cem-fields-list tbody').sortable({ handle: '.cem-drag-handle', axis: 'y' });
  }

})(jQuery);
