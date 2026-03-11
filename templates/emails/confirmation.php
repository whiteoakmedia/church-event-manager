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
