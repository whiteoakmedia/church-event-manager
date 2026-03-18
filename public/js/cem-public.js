/* Church Event Manager — Public JS */
(function ($) {
  'use strict';

  const ajax = cemPublic.ajaxUrl;

  // ── Registration Form ──────────────────────────────────────────────────────

  $(document).on('submit', '#cem-registration-form', function (e) {
    e.preventDefault();

    const form    = $(this);

    // ── Stripe payment gate ──────────────────────────────────────────────────
    // If this event requires payment, cem-stripe.js intercepts first, processes
    // the Stripe payment, then re-triggers submit with data-payment-confirmed set.
    // Only skip to the AJAX registration if payment is already confirmed.
    // NOTE: jQuery's .data() auto-coerces the HTML attribute data-needs-payment="1"
    // to the NUMBER 1, so we use == (loose equality) instead of === to match both
    // the number 1 and the string '1'.
    if (form.data('needs-payment') == 1 && !form.data('payment-confirmed')) { // eslint-disable-line eqeqeq
      // cem-stripe.js hasn't confirmed payment yet — it will handle the submit.
      return;
    }
    const btn     = $('#cem-submit-btn');
    const spinner = $('#cem-spinner');
    const msgs    = $('#cem-form-messages');

    // Basic required validation
    let valid = true;
    form.find('[required]').each(function () {
      const el = $(this);
      if (!el.val() || !el.val().trim()) {
        el.addClass('cem-field-error');
        valid = false;
      } else {
        el.removeClass('cem-field-error');
      }
    });

    if (!valid) {
      msgs.html('<div class="cem-notice cem-notice-error">Please fill in all required fields.</div>');
      return;
    }

    btn.prop('disabled', true).text(cemPublic.strings.submitting);
    spinner.show();
    msgs.html('');

    const formData = form.serialize() + '&action=cem_register';

    $.post(ajax, formData, function (res) {
      btn.prop('disabled', false).text(btn.data('original-text') || 'Register Now');
      spinner.hide();

      if (res.success) {
        form.slideUp(300);
        $('#cem-success-message')
          .html(res.data.message + '<br><small>Your registration code: <strong>' + res.data.code + '</strong></small>')
          .slideDown(300);

        // Scroll to success message
        $('html, body').animate({
          scrollTop: $('#cem-success-message').offset().top - 80
        }, 400);

        // Redirect after a short delay if a redirect URL is set
        if (res.data.redirect_url) {
          setTimeout(function () {
            window.location.href = res.data.redirect_url;
          }, 2500);
        }
      } else {
        msgs.html('<div class="cem-notice cem-notice-error">' + res.data.message + '</div>');
        $('html, body').animate({ scrollTop: msgs.offset().top - 80 }, 300);
      }
    }).fail(function () {
      btn.prop('disabled', false).text(btn.data('original-text') || 'Register Now');
      spinner.hide();
      msgs.html('<div class="cem-notice cem-notice-error">' + cemPublic.strings.error + '</div>');
    });
  });

  // Store original button text
  $('#cem-submit-btn').each(function () {
    $(this).data('original-text', $(this).text().trim());
  });

  // Remove field error class on input
  $(document).on('input change', '.cem-field-error', function () {
    $(this).removeClass('cem-field-error');
  });

  // ── Registration Type / Pricing Tier Selection ─────────────────────────────

  $(document).on('change', 'input[name="registration_type_index"]', function () {
    var price = parseFloat($(this).data('price')) || 0;
    var name  = $(this).data('name') || '';
    var btn   = $(this).closest('form').find('#cem-submit-btn');

    // Update submit button text to reflect selected tier price
    if (price > 0 && btn.length) {
      var symbol = (typeof cemStripe !== 'undefined' && cemStripe.priceDisplay)
        ? cemStripe.priceDisplay.charAt(0)
        : '$';
      btn.text(symbol + price.toFixed(2) + ' — Register Now');
    } else if (btn.length) {
      btn.text(btn.data('original-text') || 'Register Now');
    }
  });

  // ── Attendee count validation ──────────────────────────────────────────────

  $('#cem_num_attendees').on('change', function () {
    const val = parseInt($(this).val()) || 1;
    const min = parseInt($(this).attr('min')) || 1;
    const max = parseInt($(this).attr('max')) || 999;
    if (val < min) $(this).val(min);
    if (max && val > max) $(this).val(max);
  });

  // ── Smooth scroll to registration form ────────────────────────────────────

  $('a[href$="?register=1"]').on('click', function (e) {
    const targetId = '#cem-registration-' + ($(this).closest('[data-event]').data('event') || '');
    if ($(targetId).length) {
      e.preventDefault();
      $('html, body').animate({ scrollTop: $(targetId).offset().top - 80 }, 500);
    }
  });

  // ── Email lookup form auto-submit on enter ─────────────────────────────────

  $('#cem_lookup_email').on('keypress', function (e) {
    if (e.which === 13) {
      $(this).closest('form').submit();
    }
  });

  // ── Calendar: event tooltips ────────────────────────────────────────────────

  var tooltip = $('#cem-cal-tooltip');
  if (tooltip.length) {
    $(document).on('mouseenter', '.cem-cal-event-item', function (e) {
      var el    = $(this);
      var title = el.data('event-title') || '';
      var time  = el.data('event-time')  || '';
      var loc   = el.data('event-location') || '';
      var type  = el.data('event-type') || 'event';

      $('#cem-tooltip-title').text(title);
      $('#cem-tooltip-time').text(time).toggle(!!time);
      $('#cem-tooltip-location').text(loc).toggle(!!loc);
      $('#cem-tooltip-badge').text(type === 'group' ? 'Group' : 'Event')
        .attr('class', 'cem-cal-tooltip__badge cem-cal-tooltip__badge--' + type);

      var rect = el[0].getBoundingClientRect();
      var wrapRect = el.closest('.cem-calendar-wrap')[0].getBoundingClientRect();
      tooltip.css({
        top:  (rect.bottom - wrapRect.top + 4) + 'px',
        left: Math.max(0, rect.left - wrapRect.left - 40) + 'px',
      }).show();
    });

    $(document).on('mouseleave', '.cem-cal-event-item', function () {
      tooltip.hide();
    });
  }

  // ── Field error styles ─────────────────────────────────────────────────────

  $('<style>')
    .text('.cem-field-error { border-color: #c62828 !important; box-shadow: 0 0 0 3px rgba(198,40,40,.15) !important; }')
    .appendTo('head');

  // ── Leave Group form ───────────────────────────────────────────────────────

  if (typeof cemGroup !== 'undefined') {
    $(document).on('submit', '.cem-leave-group-form', function (e) {
      e.preventDefault();
      const form    = $(this);
      const btn     = form.find('.cem-leave-group-btn');
      const msg     = form.find('.cem-leave-group-msg');
      const email   = form.find('.cem-leave-email').val().trim();
      const groupId = form.data('group-id');

      btn.prop('disabled', true).text(cemGroup.strings.leaving);
      msg.hide().removeClass('cem-msg-success cem-msg-error');

      $.post(cemGroup.ajaxUrl, {
        action:   'cem_leave_group',
        nonce:    cemGroup.leaveGroupNonce,
        email:    email,
        group_id: groupId,
      })
      .done(function (res) {
        if (res.success) {
          msg.addClass('cem-msg-success').text(res.data.message).show();
          form.find('.cem-leave-email').val('');
        } else {
          msg.addClass('cem-msg-error').text(res.data.message).show();
          btn.prop('disabled', false).text('Leave Group');
        }
      })
      .fail(function () {
        msg.addClass('cem-msg-error').text('Something went wrong. Please try again.').show();
        btn.prop('disabled', false).text('Leave Group');
      });
    });
  }

})(jQuery);
