/* Church Event Manager — Public JS */
(function ($) {
  'use strict';

  const ajax = cemPublic.ajaxUrl;

  // ── Spam gate: submit timing ────────────────────────────────────────────────
  // How long the visitor had the form open. Written into the payload by JS at
  // submit time rather than rendered into the page HTML, so a CDN serving a
  // cached page can't bake in a stale value. The server treats a MISSING value
  // as fine (see CEM_Antispam::submitted_too_fast) — only an implausibly fast
  // submit is rejected.
  const cemLoadedAt = Date.now();

  function cemStampSubmitTime(form) {
    let $field = form.find('input[name="cem_ft"]');
    if (!$field.length) {
      $field = $('<input type="hidden" name="cem_ft">').appendTo(form);
    }
    $field.val(Date.now() - cemLoadedAt);
  }

  /**
   * Fetch a freshly-minted cem_register_nonce from the plugin's REST endpoint
   * and write it into the form's hidden cem_nonce input before submission.
   *
   * Why: the nonce baked into the page HTML by wp_nonce_field() expires after
   * 24 hours. When a CDN / page cache (e.g. Cloudflare, CDN Cache plugin)
   * serves the same cached HTML for longer than that, every visitor lands on
   * a page with an expired nonce and the registration AJAX call returns
   * "Security check failed." Fetching fresh on submit dodges that entirely.
   *
   * Resolves with `true` on success, `false` if the request failed (in which
   * case we fall back to the baked-in nonce — same behavior as before).
   */
  function refreshNonce(form) {
    if (!cemPublic.nonceUrl) return $.Deferred().resolve(false).promise();
    // Cache-bust the URL itself — `cache: false` only sets a query
    // param via jQuery, but some CDNs (Bunny in particular) strip
    // unknown query params from the cache key. Embedding the
    // timestamp directly in a meaningful-looking param + sending
    // No-Store headers + an explicit Cache-Control header on the
    // request makes the request truly uncacheable end-to-end.
    var url = cemPublic.nonceUrl
      + (cemPublic.nonceUrl.indexOf('?') === -1 ? '?' : '&')
      + 'ts=' + Date.now()
      + '&r='  + Math.random().toString(36).slice(2);
    return $.ajax({
      url: url,
      method: 'GET',
      cache: false,
      dataType: 'json',
      timeout: 5000,
      headers: {
        'Cache-Control': 'no-store, no-cache',
        'Pragma':        'no-cache',
      },
    }).then(function (res) {
      if (res && res.nonce) {
        // Update the form's hidden nonce input in-place. The form may also
        // be a group signup form — same field name is used either way.
        var $field = form.find('input[name="cem_nonce"]');
        if ($field.length) {
          $field.val(res.nonce);
        } else {
          form.append('<input type="hidden" name="cem_nonce" value="' + res.nonce + '">');
        }
        return true;
      }
      return false;
    }, function () {
      return false; // network/timeout — fall back to baked nonce
    });
  }

  // ── Registration Form ──────────────────────────────────────────────────────

  /**
   * Validate the registration form. Returns true when it's safe to submit.
   *
   * Called BEFORE the payment gate on purpose: cem-stripe.js charges the card
   * the moment it sees a submit on a paid event, so anything we would reject
   * has to be caught first — otherwise a registrant is billed and only then
   * told that a required answer is missing.
   */
  function cemValidateForm(form, msgs) {
    // Checkboxes and radios must be tested with `checked`, not `val()`: val()
    // returns the element's value attribute whether or not it is ticked, so a
    // required waiver or age-group box used to pass while left unchecked.
    // Radio/checkbox groups are judged as a group via their field wrapper.
    // Disabled inputs (hidden attendee blocks) are skipped — they aren't
    // submitted either.
    let valid = true;

    function flag($el, isError) {
      $el.toggleClass('cem-field-error', !!isError);
      if (isError) valid = false;
    }

    // Custom-field wrappers carry their own required flag and hold every input
    // in the group, so one pass handles text, selects, radios and checkboxes.
    form.find('.cem-field-required').each(function () {
      const $wrap   = $(this);
      const $inputs = $wrap.find('input, select, textarea').not(':disabled');
      if (!$inputs.length) return; // hidden attendee block
      const type = ($inputs.first().attr('type') || '').toLowerCase();
      let ok;
      if (type === 'radio' || type === 'checkbox') {
        ok = $inputs.filter(':checked').length > 0;
      } else {
        const v = $inputs.first().val();
        ok = !!v && !!String(v).trim();
      }
      flag($wrap, !ok);
    });

    // Core required inputs (name, email, per-attendee names…).
    form.find('[required]').each(function () {
      const el = $(this);
      if (el.closest('.cem-field-required').length) return; // handled above
      if (el.is(':disabled')) { el.removeClass('cem-field-error'); return; }
      const v = el.val();
      flag(el, !v || !String(v).trim());
    });

    if (!valid) {
      msgs.html('<div class="cem-notice cem-notice-error">Please fill in all required fields.</div>');
      const $firstError = form.find('.cem-field-error').first();
      if ($firstError.length) {
        $('html, body').animate({ scrollTop: $firstError.offset().top - 100 }, 300);
      }
      return false;
    }

    // Mixed-tier mode: require at least one tier with qty > 0.
    if (form.data('mixed-tiers') == 1) { // eslint-disable-line eqeqeq
      var totalQty = 0;
      form.find('.cem-tier-qty').each(function () {
        totalQty += parseInt($(this).val(), 10) || 0;
      });
      if (totalQty < 1) {
        msgs.html('<div class="cem-notice cem-notice-error">Please choose at least one attendee in any tier.</div>');
        return false;
      }
    }

    // The per-attendee blocks are rendered server-side, so their count is a hard
    // ceiling on party size. The headcount input is capped to match, but in
    // mixed-tier mode the headcount is the sum of the tier quantities and can
    // still overshoot — refuse it here rather than letting the server reject the
    // sign-up over questions the registrant was never shown.
    const $blocks = form.find('.cem-attendee-block');
    if ($blocks.length && cemHeadcount(form) > $blocks.length) {
      msgs.html('<div class="cem-notice cem-notice-error">You can sign up up to '
        + $blocks.length + ' people at a time. Please lower the number of attendees '
        + 'and register anyone else separately.</div>');
      return false;
    }

    return true;
  }

  $(document).on('submit', '#cem-registration-form', function (e) {
    e.preventDefault();

    const form    = $(this);
    const btn     = $('#cem-submit-btn');
    const spinner = $('#cem-spinner');
    const msgs    = $('#cem-form-messages');

    // Keep attendee #1's roster name in step with the contact fields, and make
    // sure only the blocks for the current headcount are enabled, before we
    // read any values off the form.
    cemSyncRegistrantName(form);
    cemSyncAttendeeBlocks(form);

    if (!cemValidateForm(form, msgs)) {
      // stopImmediatePropagation keeps cem-stripe.js's own submit handler from
      // running and charging the card for a form we've just rejected.
      e.stopImmediatePropagation();
      return;
    }

    cemStampSubmitTime(form);

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

    btn.prop('disabled', true).text(cemPublic.strings.submitting);
    spinner.show();
    msgs.html('');

    // Refresh the nonce just-in-time so cached pages still submit cleanly,
    // then build the payload AFTER the form's hidden cem_nonce has been
    // updated. .always() runs whether the fetch succeeded or failed — on
    // failure we fall back to the baked-in nonce.
    refreshNonce(form).always(function () {
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
    }); // end refreshNonce().always()
  });

  // Store original button text
  $('#cem-submit-btn').each(function () {
    $(this).data('original-text', $(this).text().trim());
  });

  // Remove field error class on input
  $(document).on('input change', '.cem-field-error', function () {
    $(this).removeClass('cem-field-error');
  });

  // ── Group Signup Form ─────────────────────────────────────────────────────

  $(document).on('submit', '#cem-group-signup-form', function (e) {
    e.preventDefault();
    var form = $(this);
    var btn  = form.find('#cem-group-signup-btn');
    var msgs = $('#cem-group-form-messages');

    // Basic required field validation
    var valid = true;
    form.find('[required]').each(function () {
      var el = $(this);
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

    cemStampSubmitTime(form);

    btn.prop('disabled', true).text(cemPublic.strings.submitting || 'Submitting…');
    msgs.html('');

    refreshNonce(form).always(function () {
      $.post(ajax, form.serialize() + '&action=cem_register', function (res) {
        btn.prop('disabled', false).text('Join Group');
        if (res.success) {
          form.slideUp(300);
          msgs.html(
            '<div class="cem-notice cem-notice-success">' +
            res.data.message +
            '</div>'
          ).show();
          $('html, body').animate({ scrollTop: msgs.offset().top - 80 }, 400);
        } else {
          msgs.html('<div class="cem-notice cem-notice-error">' + res.data.message + '</div>');
          $('html, body').animate({ scrollTop: msgs.offset().top - 80 }, 300);
        }
      }).fail(function () {
        btn.prop('disabled', false).text('Join Group');
        msgs.html('<div class="cem-notice cem-notice-error">' + (cemPublic.strings.error || 'Something went wrong. Please try again.') + '</div>');
      });
    });
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

  // ── Mixed-Tier Quantity Mode ──────────────────────────────────────────────
  // When the event has "Allow mixed quantities" enabled, the form renders a
  // qty input per tier instead of radios. Recompute total + total headcount
  // on every change. Also auto-syncs the hidden num_attendees field so the
  // capacity check and confirmation email both reflect the real headcount.

  function cemMixedTiersRecalc($form) {
    if (!$form.length || $form.data('mixed-tiers') != 1) return; // eslint-disable-line eqeqeq

    var symbol = (typeof cemStripe !== 'undefined' && cemStripe.priceDisplay)
      ? cemStripe.priceDisplay.charAt(0)
      : '$';

    var total     = 0;
    var headcount = 0;
    var lines     = [];

    $form.find('.cem-tier-qty').each(function () {
      var qty   = parseInt($(this).val(), 10) || 0;
      var price = parseFloat($(this).data('price')) || 0;
      var name  = $(this).data('name') || '';
      if (qty > 0) {
        var sub = qty * price;
        total     += sub;
        headcount += qty;
        lines.push(
          '<div class="cem-tier-qty-line" style="display:flex;justify-content:space-between;margin:3px 0">'
          + '<span>' + qty + ' &times; ' + $('<span>').text(name).html()
          + (price > 0 ? ' @ ' + symbol + price.toFixed(2) : '') + '</span>'
          + '<span>' + (price > 0 ? symbol + sub.toFixed(2) : 'Free') + '</span>'
          + '</div>'
        );
      }
    });

    $form.find('#cem-tier-qty-lines').html(lines.join('') || '<span class="cem-tier-qty-empty">No quantities selected yet.</span>');
    $form.find('#cem-tier-qty-total-display').text(symbol + total.toFixed(2));

    // Ensure a hidden num_attendees field exists; sync it to headcount.
    var $hidden = $form.find('input[name="num_attendees"]');
    if (!$hidden.length) {
      $hidden = $('<input type="hidden" name="num_attendees" value="1">').appendTo($form);
    }
    $hidden.val(Math.max(headcount, 1));

    // Also hide the user-facing "Number of Attendees" field row in mixed mode
    // (it's derived, not entered). Visible num_attendees fields keep an id.
    $form.find('#cem_num_attendees').closest('.cem-form-row').hide();

    // Update submit button label.
    var $btn = $form.find('#cem-submit-btn');
    if ($btn.length) {
      if (total > 0) {
        $btn.text(symbol + total.toFixed(2) + ' — Register Now');
      } else if (headcount > 0) {
        $btn.text($btn.data('original-text') || 'Register Now');
      } else {
        $btn.text($btn.data('original-text') || 'Register Now');
      }
    }

    // Fire a custom event so cem-stripe.js can update the PaymentIntent amount.
    $form.trigger('cem:mixedTotalChanged', [{ total: total, headcount: headcount }]);
  }

  $(document).on('input change', '.cem-tier-qty', function () {
    var $form = $(this).closest('form');
    cemMixedTiersRecalc($form);
  });

  // Initial pass on page load (covers refreshes where values persist).
  $(function () {
    $('form[data-mixed-tiers="1"]').each(function () {
      cemMixedTiersRecalc($(this));
    });
  });

  // Expose for cem-stripe.js to call after PI updates.
  window.cemMixedTiersRecalc = cemMixedTiersRecalc;

  // ── Un-picking a radio option ──────────────────────────────────────────────
  // A native radio can't be cleared by clicking it again, which traps anyone
  // who ticks something like "Under 21" by mistake. For questions rendered with
  // data-cem-deselect, clicking the option that's already selected clears it.
  //
  // We track the group's selected value on the form rather than reading
  // `this.checked` — by the time the click handler runs the browser has already
  // ticked the input, and clicking the label (not the input) never fires
  // mousedown on the input at all.
  function cemRadioStateKey(input) {
    return 'cemRadio:' + input.name;
  }

  $(function () {
    $('input[type="radio"][data-cem-deselect="1"]:checked').each(function () {
      $(this).closest('form').data(cemRadioStateKey(this), this.value);
    });
  });

  $(document).on('click', 'input[type="radio"][data-cem-deselect="1"]', function () {
    var $form = $(this).closest('form');
    var key   = cemRadioStateKey(this);

    if ($form.data(key) === this.value) {
      this.checked = false;
      $form.data(key, null);
      $(this).trigger('change');
    } else {
      $form.data(key, this.value);
    }
  });

  // Show the "Clear selection" control only while something is actually picked.
  function cemSyncClearChoice($field) {
    $field.find('.cem-clear-choice')
          .prop('hidden', $field.find('input[type="radio"]:checked').length === 0);
  }

  $(document).on('change click', '.cem-field-type-radio input[type="radio"]', function () {
    cemSyncClearChoice($(this).closest('.cem-field-type-radio'));
  });

  $(document).on('click', '.cem-clear-choice', function () {
    var $field  = $(this).closest('.cem-field-type-radio');
    var $radios = $field.find('input[type="radio"]');
    $radios.prop('checked', false);
    if ($radios.length) {
      $field.closest('form').data(cemRadioStateKey($radios[0]), null);
    }
    $field.removeClass('cem-field-error');
    cemSyncClearChoice($field);
  });

  $(function () {
    $('.cem-field-type-radio').each(function () {
      cemSyncClearChoice($(this));
    });
  });

  // ── Per-attendee question blocks ───────────────────────────────────────────
  // Questions flagged "per person" render one block per possible attendee.
  // Reveal exactly as many as the current headcount and disable the inputs in
  // the rest: disabled inputs are skipped by validation AND omitted from
  // form.serialize(), so unused attendee slots never reach the server.

  function cemHeadcount($form) {
    if ($form.data('mixed-tiers') == 1) { // eslint-disable-line eqeqeq
      var total = 0;
      $form.find('.cem-tier-qty').each(function () {
        total += parseInt($(this).val(), 10) || 0;
      });
      return Math.max(total, 1);
    }
    var $n = $form.find('#cem_num_attendees');
    if (!$n.length) return 1;
    return Math.max(parseInt($n.val(), 10) || 1, 1);
  }

  function cemSyncAttendeeBlocks($form) {
    var $blocks = $form.find('.cem-attendee-block');
    if (!$blocks.length) return;

    var count = cemHeadcount($form);
    $blocks.each(function (i) {
      var show = i < count;
      $(this).prop('hidden', !show)
             .find('input, select, textarea').prop('disabled', !show);
    });
  }

  // Attendee #1 is the registrant, so their roster name mirrors the contact
  // fields instead of being asked twice.
  function cemSyncRegistrantName($form) {
    $form.find('.cem-attendee-mirror-first').val($form.find('input[name="first_name"]').val() || '');
    $form.find('.cem-attendee-mirror-last').val($form.find('input[name="last_name"]').val() || '');
  }

  $(document).on('input change', '#cem_num_attendees, .cem-tier-qty', function () {
    cemSyncAttendeeBlocks($(this).closest('form'));
  });

  $(document).on('input change', 'input[name="first_name"], input[name="last_name"]', function () {
    cemSyncRegistrantName($(this).closest('form'));
  });

  $(function () {
    $('#cem-registration-form').each(function () {
      cemSyncAttendeeBlocks($(this));
      cemSyncRegistrantName($(this));
    });
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

  // ── Email me my registrations ──────────────────────────────────────────────

  $(document).on('click', '.cem-email-summary-btn', function () {
    var btn   = $(this);
    var msg   = btn.siblings('.cem-email-summary-msg');
    var email = btn.data('email');
    var nonce = btn.data('nonce');

    btn.prop('disabled', true).text(cemPublic.strings.submitting);
    msg.text('').css('color', '');

    $.post(ajax, {
      action: 'cem_email_my_registrations',
      nonce:  nonce,
      email:  email,
    }, function (res) {
      btn.prop('disabled', false).text(cemPublic.strings.emailMyRegistrations || 'Email me my registrations');
      if (res.success) {
        msg.css('color', '#2e7d32').text(res.data.message);
        btn.prop('disabled', true);
      } else {
        msg.css('color', '#c62828').text(res.data.message);
      }
    }).fail(function () {
      btn.prop('disabled', false).text(cemPublic.strings.emailMyRegistrations || 'Email me my registrations');
      msg.css('color', '#c62828').text(cemPublic.strings.error);
    });
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
