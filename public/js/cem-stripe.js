/**
 * Church Event Manager — Stripe Payment Handler
 *
 * Uses the Stripe Card Element (stripe.confirmCardPayment) for card-only
 * payments.  This is simpler and more explicit than the Payment Element for
 * single-method flows.
 *
 * Flow:
 *  1. Page loads → cem_create_payment_intent AJAX → Card Element mounted
 *  2. User fills form → clicks "Pay & Register"
 *  3. cem-public.js sees data-needs-payment="1" + no data-payment-confirmed → returns early
 *  4. THIS file intercepts, runs stripe.confirmCardPayment(clientSecret, {...})
 *  5. On success → sets #cem-payment-intent-id + data-payment-confirmed="1"
 *  6. Re-triggers submit → cem-public.js handles AJAX registration
 *
 * Depends on: jQuery, stripe-js (https://js.stripe.com/v3/), cem-public.js
 * Localized as: cemStripe { publishableKey, ajaxUrl, nonce, eventId, strings }
 */
(function ($) {
  'use strict';

  // Guard: only run when localization data is present (i.e. event has a price).
  if (typeof cemStripe === 'undefined' || !cemStripe.publishableKey) {
    return;
  }

  const stripe = Stripe(cemStripe.publishableKey); // eslint-disable-line no-undef

  let cardElement  = null;
  let clientSecret = null;   // Stored for use in confirmCardPayment
  let initError    = false;

  // ── Helpers ─────────────────────────────────────────────────────────────────

  function showStripeError(msg) {
    const $el = $('#cem-stripe-errors');
    $el.html('<p class="cem-stripe-error-msg">' + $('<span>').text(msg).html() + '</p>');
    $el[0] && $el[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function clearStripeError() {
    $('#cem-stripe-errors').empty();
  }

  function setButtonState(loading) {
    const $btn = $('#cem-submit-btn');
    if (loading) {
      $btn.prop('disabled', true)
          .data('original-text', $btn.data('original-text') || $btn.text().trim())
          .text(cemStripe.strings.processing);
      $('#cem-spinner').show();
    } else {
      $btn.prop('disabled', false)
          .text($btn.data('original-text') || cemStripe.strings.payButton);
      $('#cem-spinner').hide();
    }
  }

  // ── Step 1: Create PaymentIntent + mount Card Element on page load ───────────

  function initCardElement() {
    const $mount = $('#cem-stripe-element');
    if (!$mount.length) return;

    $.post(cemStripe.ajaxUrl, {
      action:   'cem_create_payment_intent',
      nonce:    cemStripe.nonce,
      event_id: cemStripe.eventId,
    })
    .done(function (res) {
      if (!res.success) {
        initError = true;
        console.error('[CEM Stripe] PaymentIntent creation failed:', res.data);
        $mount.html(
          '<p class="cem-stripe-error-msg">' +
          $('<span>').text(res.data.message || cemStripe.strings.loadError).html() +
          '</p>'
        );
        $('#cem-submit-btn').prop('disabled', true);
        return;
      }

      // Store clientSecret for use in confirmCardPayment.
      clientSecret = res.data.client_secret;

      // Also pre-populate the hidden field with the PI ID so it's ready.
      $('#cem-payment-intent-id').val(res.data.payment_intent_id);

      // Create a plain elements instance (no clientSecret needed here for
      // the Card Element — the clientSecret goes to confirmCardPayment instead).
      const elements = stripe.elements();

      // Resolve the site's accent colour for the card field focus ring.
      const accentColor =
        getComputedStyle(document.documentElement)
          .getPropertyValue('--cem-accent').trim() || '#3b5998';

      cardElement = elements.create('card', {
        style: {
          base: {
            fontSize:   '16px',
            fontFamily: 'inherit',
            color:      '#32325d',
            '::placeholder': { color: '#aab7c4' },
            iconColor:  accentColor,
          },
          invalid: {
            color:     '#c62828',
            iconColor: '#c62828',
          },
        },
        // Show postal/ZIP field for AVS verification (improves auth rates).
        hidePostalCode: false,
      });

      // Replace the loading placeholder with the real card input.
      $mount.empty();
      cardElement.mount('#cem-stripe-element');

      cardElement.on('ready', function () {
        $mount.addClass('cem-stripe-element--ready');
      });

      // Show inline validation errors as the user types.
      cardElement.on('change', function (event) {
        if (event.error) {
          showStripeError(event.error.message);
        } else {
          clearStripeError();
        }
      });
    })
    .fail(function () {
      initError = true;
      $('#cem-stripe-element').html(
        '<p class="cem-stripe-error-msg">' +
        $('<span>').text(cemStripe.strings.loadError).html() +
        '</p>'
      );
      $('#cem-submit-btn').prop('disabled', true);
    });
  }

  // ── Step 2–6: Intercept submit, confirm card payment, re-trigger ─────────────

  $(document).on('submit', '#cem-registration-form', function (e) {
    const $form = $(this);

    // Only handle forms that need payment and haven't confirmed yet.
    // NOTE: jQuery's .data() coerces the HTML attribute data-needs-payment="1"
    // to the NUMBER 1, not the string '1'.  Use != (loose) to catch both types.
    if ($form.data('needs-payment') != 1 || $form.data('payment-confirmed')) { // eslint-disable-line eqeqeq
      return; // Free event or already paid — let cem-public.js handle it.
    }

    // Abort submit (cem-public.js gate already returned early; this handler
    // owns the submit event for paid forms).
    e.preventDefault();
    e.stopImmediatePropagation();

    if (initError || !cardElement || !clientSecret) {
      showStripeError(cemStripe.strings.loadError);
      return;
    }

    clearStripeError();
    setButtonState(true);

    // Collect optional billing name from the registration form fields.
    const firstName = ($('#cem_first_name').val() || '').trim();
    const lastName  = ($('#cem_last_name').val()  || '').trim();
    const fullName  = [firstName, lastName].filter(Boolean).join(' ');
    const email     = ($('#cem_email').val() || '').trim();

    stripe.confirmCardPayment(clientSecret, {
      payment_method: {
        card: cardElement,
        billing_details: {
          name:  fullName  || undefined,
          email: email     || undefined,
        },
      },
    })
    .then(function (result) {
      if (result.error) {
        // Show Stripe's own error message (e.g. "Your card was declined.").
        console.error('[CEM Stripe] confirmCardPayment error:', result.error);
        // Clear PI ID so a failed intent can't be accidentally resubmitted.
        $('#cem-payment-intent-id').val('');
        setButtonState(false);
        showStripeError(result.error.message);
        return;
      }

      if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
        // Payment confirmed — update hidden field and re-trigger submit so
        // cem-public.js can complete the registration AJAX call.
        $('#cem-payment-intent-id').val(result.paymentIntent.id);
        $form.data('payment-confirmed', true);
        $form.trigger('submit');
      } else {
        // Unexpected status (e.g. requires_action for 3DS). Log for diagnosis.
        console.error('[CEM Stripe] Unexpected PI status:', result.paymentIntent ? result.paymentIntent.status : 'unknown', result);
        $('#cem-payment-intent-id').val('');
        setButtonState(false);
        showStripeError(cemStripe.strings.error);
      }
    })
    .catch(function () {
      setButtonState(false);
      showStripeError(cemStripe.strings.error);
    });
  });

  // ── Init ────────────────────────────────────────────────────────────────────

  $(function () {
    initCardElement();
  });

})(jQuery);
