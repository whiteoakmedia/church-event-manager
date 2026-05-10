<?php defined('ABSPATH') || exit; ?>

<p>Hi <?php echo esc_html($first_name); ?>,</p>

<p>Great news! Your registration for <strong><?php echo esc_html($event_title); ?></strong> has been confirmed. We can't wait to see you there!</p>

<table class="info-table">
  <tr><th>Event</th>      <td><?php echo esc_html($event_title); ?></td></tr>
  <tr><th>Date</th>       <td><?php echo esc_html($event_date); ?></td></tr>
  <tr><th>Time</th>       <td><?php echo esc_html($event_time); ?><?php if ($event_end_time) echo ' – ' . esc_html($event_end_time); ?></td></tr>
  <?php if ($event_location) : ?>
  <tr><th>Location</th>   <td><?php echo esc_html($event_location); ?></td></tr>
  <?php endif; ?>
  <tr><th>Attendees</th>  <td><?php echo esc_html($num_attendees); ?></td></tr>
  <tr><th>Reg. Code</th>  <td><strong><?php echo esc_html($registration_code); ?></strong></td></tr>
</table>

<?php
// QR code — encodes the manage URL. At check-in the volunteer scans
// it; at home the registrant can scan it with their phone camera to
// open their manage page directly.
$qr_url = ( class_exists( 'CEM_QR' ) && ! empty( $registration_code ) )
	? CEM_QR::get_url( $registration_code )
	: '';
if ( $qr_url ) :
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px auto 0;border-collapse:collapse">
  <tr>
    <td style="text-align:center;padding:14px 18px;border:1px solid #e2e8f0;border-radius:8px;background:#ffffff">
      <img src="<?php echo esc_url( $qr_url ); ?>"
           width="180" height="180"
           alt="<?php esc_attr_e( 'Check-in QR code', 'church-event-manager' ); ?>"
           style="display:block;margin:0 auto 8px;border:0;width:180px;height:180px">
      <div style="font-size:12px;color:#666;line-height:1.5;max-width:220px;margin:0 auto">
        <?php esc_html_e( 'Show this code at check-in.', 'church-event-manager' ); ?>
      </div>
    </td>
  </tr>
</table>
<?php endif; ?>

<?php
// Save-to-Calendar buttons — Google / Apple / Outlook. Only rendered
// for events with a real start/end datetime (groups skip this entirely
// because recurring meetings don't translate to a single calendar
// entry).
//
// Bullet-proof email HTML: outer table for layout (some clients still
// strip flexbox/grid) + button-styled <a> tags with inline styles +
// mso-padding-alt for Outlook. No external CSS — every email client
// strips <style> blocks differently.
if ( ! empty( $calendar_links ) ) :
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;border-collapse:collapse;width:100%">
  <tr>
    <td style="padding:0 0 8px;font-size:12px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#666">
      <?php esc_html_e( 'Save to your calendar', 'church-event-manager' ); ?>
    </td>
  </tr>
  <tr>
    <td>
      <!--[if mso]>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
      <![endif]-->
      <?php if ( ! empty( $calendar_links['google'] ) ) : ?>
      <a href="<?php echo esc_url( $calendar_links['google'] ); ?>"
         style="display:inline-block;margin:0 6px 6px 0;padding:9px 16px;background:#ffffff;color:#3b5998;border:1px solid #d1d9e6;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;mso-padding-alt:9px 16px"
         target="_blank" rel="noopener">
        <?php esc_html_e( 'Google Calendar', 'church-event-manager' ); ?>
      </a>
      <?php endif; ?>
      <?php if ( ! empty( $calendar_links['ics'] ) ) : ?>
      <a href="<?php echo esc_url( $calendar_links['ics'] ); ?>"
         style="display:inline-block;margin:0 6px 6px 0;padding:9px 16px;background:#ffffff;color:#3b5998;border:1px solid #d1d9e6;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;mso-padding-alt:9px 16px"
         target="_blank" rel="noopener">
        <?php esc_html_e( 'Apple Calendar', 'church-event-manager' ); ?>
      </a>
      <?php endif; ?>
      <?php if ( ! empty( $calendar_links['outlook'] ) ) : ?>
      <a href="<?php echo esc_url( $calendar_links['outlook'] ); ?>"
         style="display:inline-block;margin:0 6px 6px 0;padding:9px 16px;background:#ffffff;color:#3b5998;border:1px solid #d1d9e6;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;mso-padding-alt:9px 16px"
         target="_blank" rel="noopener">
        <?php esc_html_e( 'Outlook', 'church-event-manager' ); ?>
      </a>
      <?php endif; ?>
      <!--[if mso]></tr></table><![endif]-->
    </td>
  </tr>
</table>
<?php endif; ?>

<p style="margin-top:20px">
  <a href="<?php echo esc_url($event_url); ?>" class="btn">View Event Details</a>
</p>

<p style="margin-top:24px;font-size:13px;color:#888;">
  Need to cancel? You can manage your registration here:<br>
  <a href="<?php echo esc_url($manage_url); ?>"><?php echo esc_url($manage_url); ?></a>
</p>

<p style="margin-top:16px">
  We look forward to seeing you!<br>
  — <?php echo esc_html($church_name); ?>
</p>
