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
        btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span>');
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
      renderRegDetails(body, res.data.registration, res.data.meta, res.data.event_title);
    });
  });

  // Render the read-only details view for a registration.
  function renderRegDetails(body, r, meta, ev) {
    let html = '<h2>' + esc(r.first_name) + ' ' + esc(r.last_name) + '</h2>';
    html += '<table class="cem-table widefat"><tbody>';
    html += row('Event', esc(ev));
    html += row('Email', '<a href="mailto:' + escAttr(r.email) + '">' + esc(r.email) + '</a>');
    html += row('Phone', esc(r.phone) || '—');
    html += row('Attendees', esc(r.num_attendees));
    html += row('Status', esc(r.status));
    html += row('Code', '<code>' + esc(r.registration_code) + '</code>');
    html += row('Registered', esc(r.created_at));
    if (r.checked_in_at) html += row('Checked In', esc(r.checked_in_at));
    if (r.notes)         html += row('Notes', esc(r.notes));

    if (meta && Object.keys(meta).length) {
      html += '<tr><td colspan="2"><strong>Custom Fields</strong></td></tr>';
      Object.keys(meta).forEach(function (k) {
        html += row(esc(k), esc(meta[k]));
      });
    }

    html += '</tbody></table>';

    html += '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">';
    if (r.status !== 'checked_in') {
      html += '<button class="button button-primary cem-check-in-btn" data-id="' + r.id + '"><span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> Check In</button>';
    }
    html += '<button class="button cem-edit-reg" data-id="' + r.id + '"><span class="dashicons dashicons-edit" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> Edit Contact Info</button>';
    html += '<button class="button cem-delete-reg" data-id="' + r.id + '"><span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> Delete</button>';
    html += '</div>';

    body.html(html);
    // Stash the loaded record so the Edit button can pre-fill without re-fetching.
    body.data('reg', r).data('meta', meta).data('ev', ev);
  }

  // Swap the modal into an editable contact-info form.
  $(document).on('click', '.cem-edit-reg', function () {
    const body = $('#cem-reg-modal-body');
    const r    = body.data('reg');
    if (!r) return;

    let html = '<h2>Edit Contact Info</h2>';
    html += '<p class="cem-muted" style="margin-top:-8px">Correct a mistyped name, email, or phone. Other details (status, attendees, payment) are unchanged.</p>';
    html += '<div class="cem-edit-reg-form" style="display:flex;flex-direction:column;gap:12px;max-width:420px">';
    html += field('First name', 'first_name', r.first_name, 'text');
    html += field('Last name',  'last_name',  r.last_name,  'text');
    html += field('Email',      'email',      r.email,      'email');
    html += field('Phone',      'phone',      r.phone || '', 'tel');
    html += '</div>';
    html += '<p class="cem-edit-reg-error" style="color:#c53030;display:none;margin-top:10px"></p>';
    html += '<div style="margin-top:16px;display:flex;gap:8px">';
    html += '<button class="button button-primary cem-save-reg" data-id="' + r.id + '">Save Changes</button>';
    html += '<button class="button cem-cancel-edit-reg">Cancel</button>';
    html += '</div>';

    body.html(html);
  });

  // Cancel edit → back to the read-only view.
  $(document).on('click', '.cem-cancel-edit-reg', function () {
    const body = $('#cem-reg-modal-body');
    renderRegDetails(body, body.data('reg'), body.data('meta'), body.data('ev'));
  });

  // Save edited contact info.
  $(document).on('click', '.cem-save-reg', function () {
    const btn  = $(this);
    const body = $('#cem-reg-modal-body');
    const err  = body.find('.cem-edit-reg-error');
    const payload = {
      action: 'cem_update_registration',
      nonce,
      registration_id: btn.data('id'),
      first_name: body.find('[name="first_name"]').val().trim(),
      last_name:  body.find('[name="last_name"]').val().trim(),
      email:      body.find('[name="email"]').val().trim(),
      phone:      body.find('[name="phone"]').val().trim()
    };

    err.hide();
    btn.prop('disabled', true).text('Saving…');
    $.post(ajax, payload, function (res) {
      if (res.success) {
        location.reload();
      } else {
        btn.prop('disabled', false).text('Save Changes');
        err.text(res.data && res.data.message ? res.data.message : 'Could not save.').show();
      }
    }).fail(function () {
      btn.prop('disabled', false).text('Save Changes');
      err.text('Could not save. Please try again.').show();
    });
  });

  function row(label, value) {
    return '<tr><th style="width:140px;font-size:12px;color:#718096;text-transform:uppercase">' + label + '</th><td>' + (value || '—') + '</td></tr>';
  }

  function field(label, name, value, type) {
    return '<label style="font-size:12px;color:#718096;text-transform:uppercase;font-weight:600">' + label +
      '<input type="' + type + '" name="' + name + '" value="' + escAttr(value) + '" class="widefat" style="margin-top:4px;text-transform:none"></label>';
  }

  // Minimal HTML / attribute escapers for values rendered into the modal.
  function esc(v) {
    if (v === null || typeof v === 'undefined') return '';
    return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function escAttr(v) {
    if (v === null || typeof v === 'undefined') return '';
    return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
    const btn   = $(this);
    const regId = btn.data('id');

    // Pull the name off the row so the confirmation prompt can say
    // *who* is about to be deleted instead of a generic "are you sure?"
    // The action is destructive (cascades to checkins/waitlist/meta and
    // nulls FK in email log) so the wording calls that out clearly.
    const row    = $('tr[data-id="' + regId + '"]');
    const person = row.find('td:nth-child(2) strong').text().trim() || 'this registration';
    const msg    = 'Permanently delete ' + person + '?\n\n'
                 + 'This removes the registration, any check-in record, and answers to custom questions. The action cannot be undone.';

    if (!confirm(msg)) return;

    btn.prop('disabled', true);
    $.post(ajax, { action: 'cem_delete_registration', nonce, registration_id: regId }, function (res) {
      if (res.success) {
        row.fadeOut(300, function () { $(this).remove(); });
        $('#cem-reg-modal').hide();
      } else {
        btn.prop('disabled', false);
        alert(res.data && res.data.message ? res.data.message : 'Delete failed.');
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
        btn.prop('disabled', false).html('<span class="dashicons dashicons-arrow-up-alt"></span>');
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

  // ── Email Center — Preview Email ─────────────────────────────────────────

  $('#cem-preview-email-btn').on('click', function () {
    const subject = $('#cem-email-subject').val().trim();
    const message = (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor)
      ? tinyMCE.activeEditor.getContent()
      : $('#cem_email_body').val();

    if (!subject) return alert('Please enter a subject before previewing.');
    if (!message) return alert('Please enter a message before previewing.');

    const ids = window.cemEmailRecipientIds;
    if (!ids || !ids.length) return alert('Preview Recipients first to load recipients.');

    const btn = $(this);
    btn.prop('disabled', true).text('Loading…');

    $.post(ajax, {
      action:          'cem_preview_bulk_email',
      nonce:           nonce,
      registration_id: ids[0],
      subject:         subject,
      message:         message,
    }, function (res) {
      btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> Preview Email');
      if (!res.success) { alert(res.data.message); return; }

      const d = res.data;
      $('#cem-preview-recipient').text(d.recipient_name + ' <' + d.recipient_email + '>');
      $('#cem-preview-subject').text(d.subject);

      // Write rendered HTML into the sandboxed iframe
      const frame = document.getElementById('cem-preview-frame');
      frame.contentDocument.open();
      frame.contentDocument.write(d.html);
      frame.contentDocument.close();

      $('#cem-email-preview-panel').slideDown();
      $('html, body').animate({ scrollTop: $('#cem-email-preview-panel').offset().top - 60 }, 400);
    }).fail(function () {
      btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> Preview Email');
      alert('Request failed. Check browser console.');
    });
  });

  $('#cem-close-preview').on('click', function () {
    $('#cem-email-preview-panel').slideUp();
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
      btn.prop('disabled', false).html('<span class="dashicons dashicons-email" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> Send Email to All Recipients');
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

  // ── Stripe test connection ────────────────────────────────────────────────

  $(document).on('click', '#cem-test-stripe', function () {
    const btn    = $(this);
    const result = $('#cem-stripe-test-result');
    btn.prop('disabled', true).text('Testing…');
    result.text('').css('color', '');
    $.post(ajax, { action: 'cem_test_stripe', nonce: cemAdmin.nonce }, function (res) {
      btn.prop('disabled', false).text('Test Stripe Connection');
      result.text(res.data.message).css('color', res.success ? '#008000' : '#c62828');
    }).fail(function () {
      btn.prop('disabled', false).text('Test Stripe Connection');
      result.text('Request failed. Check browser console.').css('color', '#c62828');
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

  // ── Email Log Preview ─────────────────────────────────────────────────────
  // Look up elements inside handlers so they're never stale empty-jQuery objects
  // (the modal markup only exists in the DOM when on the log tab).

  function closeEmailPreview() {
    $('#cem-email-preview-modal').hide();
  }

  function writeIframe(html) {
    var iframe = document.getElementById('cem-email-preview-iframe');
    if (!iframe) return;
    var doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
  }

  $(document).on('click', '.cem-preview-email-btn', function () {
    var logId   = $(this).data('id');
    var subject = $(this).data('subject');
    var $modal  = $('#cem-email-preview-modal');

    if (!$modal.length) return;

    $('#cem-email-preview-subject').text(subject);
    writeIframe('<p style="padding:20px;color:#888;">Loading…</p>');
    $modal.css('display', 'flex');

    $.post(ajax, { action: 'cem_preview_email_log', nonce: nonce, log_id: logId })
      .done(function (res) {
        if (res.success) {
          writeIframe(res.data.html);
        } else {
          writeIframe('<p style="padding:20px;color:#c62828;">Could not load email preview.</p>');
        }
      })
      .fail(function () {
        writeIframe('<p style="padding:20px;color:#c62828;">Request failed.</p>');
      });
  });

  $(document).on('click', '#cem-email-preview-close', function () {
    closeEmailPreview();
  });

  $(document).on('click', '#cem-email-preview-modal', function (e) {
    if (e.target === this) closeEmailPreview();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeEmailPreview();
  });


  // ── Link Event to Group ───────────────────────────────────────────────────

  $(document).on('click', '#cem-link-event-btn', function () {
    var btn      = $(this);
    var groupId  = btn.data('group');
    var nonce    = btn.data('nonce');
    var select   = $('#cem-link-event-select');
    var eventId  = select.val();
    var msg      = $('#cem-link-event-msg');

    if (!eventId) { alert('Please select an event to link.'); return; }

    btn.prop('disabled', true).text('Linking…');

    $.post(ajax, {
      action:   'cem_link_event_to_group',
      nonce:    nonce,
      event_id: eventId,
      group_id: groupId,
    }, function (res) {
      btn.prop('disabled', false).text('Link Event');
      if (!res.success) { alert(res.data.message); return; }

      var d = res.data;
      var tbody = $('#cem-linked-events-tbody');
      if (!tbody.length) {
        // table not yet visible — reload so PHP re-renders cleanly
        location.reload(); return;
      }
      tbody.append(
        '<tr id="cem-event-row-' + d.event_id + '">' +
          '<td>' + d.title + '</td>' +
          '<td>' + d.date + '</td>' +
          '<td>' + d.status + '</td>' +
          '<td>' +
            '<a href="' + d.edit_url + '">Edit</a>' +
            ' | <a href="' + d.view_url + '" target="_blank">View</a>' +
            ' | <a href="#" class="cem-unlink-event" style="color:#dc2626"' +
              ' data-event="' + d.event_id + '"' +
              ' data-group="' + groupId + '"' +
              ' data-nonce="' + nonce + '">Unlink</a>' +
          '</td>' +
        '</tr>'
      );
      // Remove from dropdown
      select.find('option[value="' + d.event_id + '"]').remove();
      msg.text('Event linked!').css({ color: '#166534', display: 'inline' });
      setTimeout(function () { msg.fadeOut(); }, 2500);
    });
  });

  $(document).on('click', '.cem-unlink-event', function (e) {
    e.preventDefault();
    if (!confirm('Unlink this event from the group?')) return;
    var link    = $(this);
    var eventId = link.data('event');
    var groupId = link.data('group');
    var nonce   = link.data('nonce');

    $.post(ajax, {
      action:   'cem_unlink_event_from_group',
      nonce:    nonce,
      event_id: eventId,
      group_id: groupId,
    }, function (res) {
      if (!res.success) { alert(res.data.message); return; }
      $('#cem-event-row-' + eventId).fadeOut(300, function () { $(this).remove(); });
      // Re-add to dropdown
      $('#cem-link-event-select').append('<option value="' + eventId + '">' + link.closest('tr').find('td:first').text() + '</option>');
    });
  });

})(jQuery);
