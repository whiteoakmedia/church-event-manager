<?php defined('ABSPATH') || exit; ?>

<p>A new registration has been submitted.</p>

<table class="info-table">
  <tr><th>Event</th>      <td><?php echo esc_html($event_title); ?></td></tr>
  <tr><th>Date</th>       <td><?php echo esc_html($event_date); ?></td></tr>
  <tr><th>Name</th>       <td><?php echo esc_html($full_name); ?></td></tr>
  <tr><th>Email</th>      <td><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></td></tr>
  <tr><th>Phone</th>      <td><?php echo $phone ? esc_html($phone) : '—'; ?></td></tr>
  <tr><th>Attendees</th>  <td><?php echo esc_html($num_attendees); ?></td></tr>
  <tr><th>Status</th>     <td><?php echo esc_html($registration_status); ?></td></tr>
  <tr><th>Reg. Code</th>  <td><?php echo esc_html($registration_code); ?></td></tr>
</table>

<p style="margin-top:20px">
  <a href="<?php echo esc_url($admin_url); ?>" class="btn">View in Admin Dashboard</a>
</p>
