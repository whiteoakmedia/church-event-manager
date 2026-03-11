<?php defined('ABSPATH') || exit; ?>

<p>Hi <?php echo esc_html($first_name); ?>,</p>

<p>Your registration for <strong><?php echo esc_html($event_title); ?></strong> has been cancelled as requested.</p>

<table class="info-table">
  <tr><th>Event</th>     <td><?php echo esc_html($event_title); ?></td></tr>
  <tr><th>Date</th>      <td><?php echo esc_html($event_date); ?></td></tr>
  <tr><th>Reg. Code</th> <td><?php echo esc_html($registration_code); ?></td></tr>
  <tr><th>Status</th>    <td><span class="badge" style="background:#fed7d7;color:#9b2c2c">Cancelled</span></td></tr>
</table>

<p style="margin-top:20px">We hope to see you at a future event!</p>

<p>
  <a href="<?php echo esc_url($event_url); ?>" class="btn">View Other Events</a>
</p>

<p style="font-size:13px;color:#888;">
  If you believe this was an error or would like to re-register, please visit the event page or contact us at
  <a href="mailto:<?php echo esc_attr($church_phone ?: get_option('admin_email')); ?>"><?php echo esc_html(get_option('admin_email')); ?></a>.
</p>

<p>
  — <?php echo esc_html($church_name); ?>
</p>
