<?php
/**
 * Manages per-event custom registration fields.
 *
 * Each field is a row in {prefix}cem_custom_fields. Two columns carry the
 * per-type configuration:
 *
 *   field_options – comma-joined option labels for select/radio/checkbox, or
 *                   the waiver body for waiver fields. Kept as-is so every
 *                   pre-1.12 read path still works.
 *   field_meta    – JSON added in DB v1.5.0:
 *                     option_caps  { "Option label": int }  0/absent = unlimited
 *                     per_attendee 1 = ask once per attendee, not per booking
 *
 * Answers land in {prefix}cem_registration_meta two different ways:
 *   • Shared questions   → one row, meta_key = field_name
 *   • Per-attendee ones  → inside the `_attendees` JSON roster on the
 *                          registration (see CEM_Ajax::handle_registration)
 * Capacity counting reads both, so toggling `per_attendee` on a live event
 * never loses track of already-claimed spots.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Custom_Fields {

	/** Field types whose answers come from a fixed option list. */
	const CHOICE_TYPES = [ 'select', 'radio', 'checkbox' ];

	/** Statuses that hold a spot — mirrors CEM_Helpers::get_registration_count(). */
	const HOLDING_STATUSES = [ 'confirmed', 'checked_in', 'pending' ];

	public function init() {
		add_action( 'add_meta_boxes',    [ $this, 'add_meta_box' ] );
		add_action( 'save_post_cem_event', [ $this, 'save_fields' ], 20 );
	}

	// ── Meta Box ──────────────────────────────────────────────────────────────

	public function add_meta_box() {
		add_meta_box(
			'cem_custom_fields',
			__( 'Registration Form Fields', 'church-event-manager' ),
			[ $this, 'render_meta_box' ],
			'cem_event',
			'normal',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'cem_save_custom_fields', 'cem_custom_fields_nonce' );
		$fields = self::get_fields( $post->ID );
		?>
		<div id="cem-custom-fields-wrap">
			<p class="description"><?php esc_html_e( 'Add custom questions to the registration form for this event.', 'church-event-manager' ); ?></p>

			<table class="cem-fields-table widefat" id="cem-fields-list">
				<thead>
					<tr>
						<th style="width:30px"></th>
						<th><?php esc_html_e( 'Label', 'church-event-manager' ); ?></th>
						<th style="width:150px"><?php esc_html_e( 'Type', 'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Options / Limits', 'church-event-manager' ); ?></th>
						<th style="width:70px"><?php esc_html_e( 'Required', 'church-event-manager' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Per person', 'church-event-manager' ); ?></th>
						<th style="width:40px"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $fields ) ) : ?>
					<tr class="cem-no-fields">
						<td colspan="7"><?php esc_html_e( 'No custom fields added yet.', 'church-event-manager' ); ?></td>
					</tr>
					<?php else : ?>
					<?php foreach ( $fields as $i => $field ) : ?>
					<?php echo self::field_row_html( $i, $field, $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="cem-add-field">
					+ <?php esc_html_e( 'Add Field', 'church-event-manager' ); ?>
				</button>
			</p>

			<p class="description">
				<strong><?php esc_html_e( 'Per person', 'church-event-manager' ); ?></strong>
				<?php esc_html_e( 'asks the question separately for every attendee on a sign-up — use it for things like workshop choice or age group when one person registers their whole family. Leave it off for questions that apply to the whole booking.', 'church-event-manager' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Set a limit next to an option to cap how many people can choose it (e.g. a workshop that only holds 30). Leave it blank or 0 for no limit — the option shows "Full" and can\'t be picked once it fills.', 'church-event-manager' ); ?>
				<?php esc_html_e( 'Option labels can\'t contain commas; any you type are replaced with a space when saved.', 'church-event-manager' ); ?>
			</p>
		</div>

		<style>
		.cem-fields-table .cem-choice-row { display:flex; gap:6px; align-items:center; margin-bottom:4px; }
		.cem-fields-table .cem-choice-row .cem-choice-label { flex:1 1 auto; min-width:0; }
		.cem-fields-table .cem-choice-row .cem-choice-cap   { flex:0 0 78px; width:78px; }
		.cem-fields-table .cem-choice-used { flex:0 0 auto; font-size:11px; color:#666; white-space:nowrap; }
		.cem-fields-table .cem-choice-head { display:flex; gap:6px; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#666; margin-bottom:3px; }
		.cem-fields-table .cem-choice-head span:first-child { flex:1 1 auto; }
		.cem-fields-table .cem-choice-head span:last-child  { flex:0 0 84px; }
		</style>

		<script>
		jQuery(function($){
			var idx      = <?php echo count( $fields ); ?>;
			var rowTpl   = <?php echo wp_json_encode( self::field_row_html( '__I__' ) ); ?>;
			var choiceTpl= <?php echo wp_json_encode( self::choice_row_html( '__I__', '__C__' ) ); ?>;

			$('#cem-add-field').on('click', function(){
				$('.cem-no-fields').remove();
				$('#cem-fields-list tbody').append( rowTpl.split('__I__').join(idx) );
				idx++;
			});

			$(document).on('click', '.cem-remove-field', function(){
				$(this).closest('tr').remove();
			});

			// Show the right editor for the chosen type: option rows for
			// select/radio/checkbox, a body textarea for waivers, nothing else.
			//
			// The editor that doesn't apply is DISABLED as well as hidden, so it
			// can't reach save_fields(). Hiding alone isn't enough: switching a
			// Waiver to Multiple Choice would otherwise still post the waiver's
			// body text, and save_fields' legacy fallback would explode that
			// paragraph on commas into a set of nonsense options.
			function syncTypeUI($row){
				var type     = $row.find('.cem-field-type').val();
				var isChoice = <?php echo wp_json_encode( self::CHOICE_TYPES ); ?>.indexOf(type) !== -1;

				$row.find('.cem-field-choices').toggle(isChoice)
					.find('input').prop('disabled', !isChoice);

				$row.find('.cem-field-options-text')
					.toggle(type === 'waiver')
					.prop('disabled', type !== 'waiver');

				// Seed one blank option row so a fresh choice field isn't empty.
				if (isChoice && $row.find('.cem-choice-row').length === 0) {
					$row.find('.cem-add-choice').trigger('click');
				}
			}

			$(document).on('change', '.cem-field-type', function(){
				syncTypeUI( $(this).closest('tr') );
			});

			$(document).on('click', '.cem-add-choice', function(){
				var $row  = $(this).closest('tr');
				var fIdx  = $row.data('index');
				var cIdx  = $row.find('.cem-choice-row').length;
				// Rows are re-indexed on save, so a monotonically increasing
				// index per row is enough — gaps from removals are harmless.
				while ($row.find('input[name="cem_fields['+fIdx+'][choices]['+cIdx+'][label]"]').length) cIdx++;
				$row.find('.cem-choice-list').append(
					choiceTpl.split('__I__').join(fIdx).split('__C__').join(cIdx)
				);
			});

			$(document).on('click', '.cem-remove-choice', function(){
				$(this).closest('.cem-choice-row').remove();
			});

			$('#cem-fields-list tbody tr.cem-field-row').each(function(){ syncTypeUI($(this)); });

			// Sortable rows
			if ( $.fn.sortable ) {
				$('#cem-fields-list tbody').sortable({ handle: '.cem-drag-handle' });
			}
		});
		</script>
		<?php
	}

	/**
	 * One editor row. Also used verbatim as the JS "add field" template, with
	 * `__I__` standing in for the array index — so the markup lives in exactly
	 * one place instead of being mirrored in a JS string.
	 */
	private static function field_row_html( $i, $field = null, $event_id = 0 ) {
		$type     = $field ? $field->field_type : 'text';
		$is_choice= in_array( $type, self::CHOICE_TYPES, true );
		$caps     = $field ? self::get_option_caps( $field ) : [];
		$options  = $field ? self::get_options( $field ) : [];
		$per_att  = $field ? self::is_per_attendee( $field ) : false;
		$sold     = ( $field && $event_id && $is_choice ) ? self::get_option_sold_counts( $event_id, $field ) : [];

		ob_start();
		?>
		<tr class="cem-field-row" data-index="<?php echo esc_attr( $i ); ?>">
			<td class="cem-drag-handle"><span class="dashicons dashicons-menu"></span></td>
			<td>
				<input type="text" name="cem_fields[<?php echo esc_attr( $i ); ?>][label]"
					value="<?php echo $field ? esc_attr( $field->field_label ) : ''; ?>"
					class="widefat" placeholder="<?php esc_attr_e( 'e.g. Dietary Restrictions', 'church-event-manager' ); ?>">
				<input type="hidden" name="cem_fields[<?php echo esc_attr( $i ); ?>][id]" value="<?php echo $field ? esc_attr( $field->id ) : '0'; ?>">
			</td>
			<td>
				<select name="cem_fields[<?php echo esc_attr( $i ); ?>][type]" class="cem-field-type">
					<?php foreach ( self::get_field_types() as $t => $label ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php // Visible only for waivers (their body text), but kept populated for
				      // every non-choice type so an unrelated save doesn't wipe the column.
				      // Deliberately left EMPTY for choice types: their editor is the option
				      // rows below, and echoing the stale comma list here would resurrect
				      // options the admin had just deleted (save_fields falls back to this
				      // field when no option rows are posted). ?>
				<textarea name="cem_fields[<?php echo esc_attr( $i ); ?>][options]"
					class="widefat cem-field-options-text" rows="4"
					placeholder="<?php esc_attr_e( 'Waiver / agreement text shown above the checkbox', 'church-event-manager' ); ?>"
					<?php echo $type === 'waiver' ? '' : 'style="display:none"'; ?>><?php
						echo ( $field && ! $is_choice ) ? esc_textarea( $field->field_options ) : '';
					?></textarea>

				<div class="cem-field-choices" <?php echo $is_choice ? '' : 'style="display:none"'; ?>>
					<div class="cem-choice-head">
						<span><?php esc_html_e( 'Option', 'church-event-manager' ); ?></span>
						<span><?php esc_html_e( 'Limit', 'church-event-manager' ); ?></span>
					</div>
					<div class="cem-choice-list">
						<?php foreach ( $options as $c => $opt ) :
							echo self::choice_row_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								$i,
								$c,
								$opt,
								(int) ( $caps[ $opt ] ?? 0 ),
								isset( $sold[ $opt ] ) ? (int) $sold[ $opt ] : null
							);
						endforeach; ?>
					</div>
					<button type="button" class="button button-small cem-add-choice" style="margin-top:4px">
						+ <?php esc_html_e( 'Add option', 'church-event-manager' ); ?>
					</button>
				</div>
			</td>
			<td style="text-align:center">
				<input type="checkbox" name="cem_fields[<?php echo esc_attr( $i ); ?>][required]" value="1"
					<?php checked( $field ? (int) $field->required : 0, 1 ); ?>>
			</td>
			<td style="text-align:center">
				<input type="checkbox" name="cem_fields[<?php echo esc_attr( $i ); ?>][per_attendee]" value="1"
					<?php checked( $per_att ); ?>>
			</td>
			<td>
				<button type="button" class="button button-small cem-remove-field" aria-label="<?php esc_attr_e( 'Remove field', 'church-event-manager' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** One option row (label + optional cap). `__I__`/`__C__` are JS placeholders. */
	private static function choice_row_html( $field_index, $choice_index, $label = '', $cap = 0, $used = null ) {
		$base = 'cem_fields[' . $field_index . '][choices][' . $choice_index . ']';
		ob_start();
		?>
		<div class="cem-choice-row">
			<input type="text" class="cem-choice-label" name="<?php echo esc_attr( $base ); ?>[label]"
				value="<?php echo esc_attr( $label ); ?>"
				placeholder="<?php esc_attr_e( 'Option label', 'church-event-manager' ); ?>">
			<input type="number" class="cem-choice-cap" name="<?php echo esc_attr( $base ); ?>[cap]"
				value="<?php echo $cap > 0 ? esc_attr( $cap ) : ''; ?>" min="0" step="1"
				placeholder="<?php esc_attr_e( 'none', 'church-event-manager' ); ?>"
				title="<?php esc_attr_e( 'Maximum people who can choose this option. Blank = no limit.', 'church-event-manager' ); ?>">
			<?php if ( $used !== null && $used > 0 ) : ?>
			<span class="cem-choice-used">
				<?php
				printf(
					/* translators: %d: number of people who chose this option */
					esc_html( _n( '%d signed up', '%d signed up', $used, 'church-event-manager' ) ),
					(int) $used
				);
				?>
			</span>
			<?php endif; ?>
			<button type="button" class="button button-small cem-remove-choice" aria-label="<?php esc_attr_e( 'Remove option', 'church-event-manager' ); ?>">&times;</button>
		</div>
		<?php
		return ob_get_clean();
	}

	public function save_fields( $post_id ) {
		if ( ! isset( $_POST['cem_custom_fields_nonce'] ) ) return;
		if ( ! wp_verify_nonce( $_POST['cem_custom_fields_nonce'], 'cem_save_custom_fields' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		global $wpdb;
		$table = "{$wpdb->prefix}cem_custom_fields";

		// Delete removed fields
		$posted_ids = [];
		if ( ! empty( $_POST['cem_fields'] ) ) {
			foreach ( $_POST['cem_fields'] as $field ) {
				if ( ! empty( $field['id'] ) && (int) $field['id'] > 0 ) {
					$posted_ids[] = (int) $field['id'];
				}
			}
		}

		// Remove fields not in posted list
		$existing_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $table WHERE event_id = %d", $post_id
		) );
		foreach ( $existing_ids as $existing_id ) {
			if ( ! in_array( (int) $existing_id, $posted_ids ) ) {
				$wpdb->delete( $table, [ 'id' => $existing_id ], [ '%d' ] );
			}
		}

		if ( empty( $_POST['cem_fields'] ) ) return;

		$sort = 0;
		foreach ( $_POST['cem_fields'] as $field ) {
			$label   = sanitize_text_field( $field['label'] ?? '' );
			if ( ! $label ) continue;

			$type    = sanitize_key( $field['type'] ?? 'text' );
			$required= ! empty( $field['required'] ) ? 1 : 0;
			$name    = sanitize_key( strtolower( str_replace( ' ', '_', $label ) ) );
			$id      = (int) ( $field['id'] ?? 0 );

			$meta = [];
			if ( ! empty( $field['per_attendee'] ) ) {
				$meta['per_attendee'] = 1;
			}

			if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
				// Option rows: label + optional cap. `field_options` stays the
				// comma-joined labels for back-compat; caps go to field_meta.
				$labels = [];
				$caps   = [];
				foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
					$opt = sanitize_text_field( $choice['label'] ?? '' );
					// Commas are the separator for both the stored option list and
					// the comma-joined checkbox answers in registration meta, so a
					// label containing one would split into phantom options and
					// quietly break that option's capacity count (spots would look
					// available forever). Fold commas to spaces rather than storing
					// something we can't read back reliably.
					$opt = trim( preg_replace( '/\s+/', ' ', str_replace( ',', ' ', $opt ) ) );
					if ( $opt === '' ) continue;
					// A duplicate label would make its cap ambiguous (answers are
					// stored by label, not index), so keep the first one only.
					if ( in_array( $opt, $labels, true ) ) continue;
					$labels[] = $opt;
					$cap = absint( $choice['cap'] ?? 0 );
					if ( $cap > 0 ) $caps[ $opt ] = $cap;
				}
				// Fall back to the legacy comma list if no option rows came
				// through (e.g. a save posted by older cached admin JS).
				if ( empty( $labels ) && ! empty( $field['options'] ) ) {
					$labels = array_values( array_filter( array_map(
						'trim',
						explode( ',', sanitize_textarea_field( (string) wp_unslash( $field['options'] ) ) )
					), 'strlen' ) );
				}
				$options = implode( ', ', $labels );
				if ( ! empty( $caps ) ) $meta['option_caps'] = $caps;
			} else {
				// `field_options` is reused for several purposes:
				//   - waiver:          the waiver body (multi-line HTML)
				//   - everything else: an optional helper string
				//
				// `sanitize_text_field` strips line breaks, so waiver text was
				// rendering as one mashed paragraph. Use `wp_kses_post` for
				// waivers (permits safe HTML + preserves newlines) and the
				// stricter `sanitize_textarea_field` for other types so we keep
				// line breaks but block tags.
				$raw_options = (string) wp_unslash( $field['options'] ?? '' );
				$options = $type === 'waiver'
					? wp_kses_post( $raw_options )
					: sanitize_textarea_field( $raw_options );
			}

			$meta_json = ! empty( $meta ) ? wp_json_encode( $meta ) : null;

			$row = [
				'field_label'   => $label,
				'field_name'    => $name,
				'field_type'    => $type,
				'field_options' => $options,
				'field_meta'    => $meta_json,
				'required'      => $required,
				'sort_order'    => $sort,
			];
			$formats = [ '%s','%s','%s','%s','%s','%d','%d' ];

			if ( $id > 0 ) {
				$wpdb->update( $table, $row, [ 'id' => $id ], $formats, [ '%d' ] );
			} else {
				$row['event_id'] = $post_id;
				$formats[]       = '%d';
				$wpdb->insert( $table, $row, $formats );
			}
			$sort++;
		}
	}

	// ── Static Helpers ────────────────────────────────────────────────────────

	public static function get_fields( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_custom_fields WHERE event_id = %d ORDER BY sort_order ASC",
			$event_id
		) );
	}

	public static function get_field_types() {
		return [
			'text'     => __( 'Text',          'church-event-manager' ),
			'textarea' => __( 'Paragraph',      'church-event-manager' ),
			'email'    => __( 'Email',          'church-event-manager' ),
			'phone'    => __( 'Phone',          'church-event-manager' ),
			'number'   => __( 'Number',         'church-event-manager' ),
			'date'     => __( 'Date',           'church-event-manager' ),
			'select'   => __( 'Dropdown',       'church-event-manager' ),
			'radio'    => __( 'Multiple Choice','church-event-manager' ),
			'checkbox' => __( 'Checkboxes',     'church-event-manager' ),
			'waiver'   => __( 'Waiver/Agreement','church-event-manager' ),
		];
	}

	/** Decode the field_meta JSON blob. Safe on pre-1.12 rows (column absent/NULL). */
	public static function get_field_meta( $field ) {
		if ( ! isset( $field->field_meta ) || $field->field_meta === '' || $field->field_meta === null ) {
			return [];
		}
		$meta = json_decode( (string) $field->field_meta, true );
		return is_array( $meta ) ? $meta : [];
	}

	/** Is this question asked once per attendee rather than once per booking? */
	public static function is_per_attendee( $field ) {
		$meta = self::get_field_meta( $field );
		return ! empty( $meta['per_attendee'] );
	}

	/** Option labels for a choice field, in admin order. */
	public static function get_options( $field ) {
		if ( ! in_array( $field->field_type, self::CHOICE_TYPES, true ) ) return [];
		return array_values( array_filter(
			array_map( 'trim', explode( ',', (string) $field->field_options ) ),
			'strlen'
		) );
	}

	/** Per-option signup caps: [ label => int ]. Options without a cap are absent. */
	public static function get_option_caps( $field ) {
		$meta = self::get_field_meta( $field );
		$caps = isset( $meta['option_caps'] ) && is_array( $meta['option_caps'] ) ? $meta['option_caps'] : [];
		$out  = [];
		foreach ( $caps as $label => $cap ) {
			$cap = (int) $cap;
			if ( $cap > 0 ) $out[ (string) $label ] = $cap;
		}
		return $out;
	}

	/** Does any option on this field have a cap? */
	public static function has_option_caps( $field ) {
		return ! empty( self::get_option_caps( $field ) );
	}

	/** True if a value (string or array) selects the given option. */
	private static function value_selects( $value, $option ) {
		if ( is_array( $value ) ) {
			return in_array( (string) $option, array_map( 'strval', $value ), true );
		}
		return (string) $value === (string) $option;
	}

	/**
	 * Count how many people have already claimed each option of a choice field.
	 *
	 * Reads BOTH storage shapes so a field that was switched between shared and
	 * per-attendee mid-event still counts correctly:
	 *
	 *   • Shared answer  → one meta row; the whole party counts toward it, the
	 *     same way a single-tier registration counts all its attendees toward
	 *     that tier (see CEM_Helpers::get_tier_sold_counts).
	 *   • Per-attendee   → the `_attendees` roster; each attendee counts once.
	 *
	 * Pending holds a spot; cancelled and waitlisted never count.
	 *
	 * @return array<string,int> [ option label => people signed up ]
	 */
	public static function get_option_sold_counts( $event_id, $field ) {
		global $wpdb;

		$options = self::get_options( $field );
		if ( empty( $options ) ) return [];

		$statuses = self::HOLDING_STATUSES;
		$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$counts = array_fill_keys( $options, 0 );

		// ── Shared answers ──────────────────────────────────────────────────
		$flat = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.num_attendees, m.meta_value
			 FROM {$wpdb->prefix}cem_registrations r
			 INNER JOIN {$wpdb->prefix}cem_registration_meta m ON m.registration_id = r.id
			 WHERE r.event_id = %d AND r.status IN ($ph) AND m.meta_key = %s",
			array_merge( [ (int) $event_id ], $statuses, [ sanitize_key( $field->field_name ) ] )
		) );

		foreach ( (array) $flat as $row ) {
			// Checkbox answers are stored comma-joined by CEM_Registration::create.
			$picked = array_map( 'trim', explode( ',', (string) $row->meta_value ) );
			$people = max( 1, (int) $row->num_attendees );
			foreach ( $options as $opt ) {
				if ( in_array( $opt, $picked, true ) ) {
					$counts[ $opt ] += $people;
				}
			}
		}

		// ── Per-attendee answers ────────────────────────────────────────────
		$rosters = $wpdb->get_col( $wpdb->prepare(
			"SELECT m.meta_value
			 FROM {$wpdb->prefix}cem_registrations r
			 INNER JOIN {$wpdb->prefix}cem_registration_meta m ON m.registration_id = r.id
			 WHERE r.event_id = %d AND r.status IN ($ph) AND m.meta_key = '_attendees'",
			array_merge( [ (int) $event_id ], $statuses )
		) );

		foreach ( (array) $rosters as $json ) {
			$roster = json_decode( (string) $json, true );
			if ( ! is_array( $roster ) ) continue;
			foreach ( $roster as $attendee ) {
				$answer = $attendee['fields'][ $field->field_name ] ?? null;
				if ( $answer === null || $answer === '' ) continue;
				foreach ( $options as $opt ) {
					if ( self::value_selects( $answer, $opt ) ) {
						$counts[ $opt ]++;
					}
				}
			}
		}

		return $counts;
	}

	/** Remaining spots for each capped option: [ label => int ]. Uncapped omitted. */
	public static function get_option_remaining( $event_id, $field ) {
		$caps = self::get_option_caps( $field );
		if ( empty( $caps ) ) return [];
		$sold = self::get_option_sold_counts( $event_id, $field );
		$out  = [];
		foreach ( $caps as $label => $cap ) {
			$out[ $label ] = max( 0, $cap - (int) ( $sold[ $label ] ?? 0 ) );
		}
		return $out;
	}

	/**
	 * Enforce per-option caps for an incoming registration.
	 *
	 * Runs before payment so nobody is charged and then bounced — same contract
	 * as the per-tier capacity guard in CEM_Ajax::handle_registration.
	 *
	 * @param int   $event_id
	 * @param array $shared     field_name => value|array (whole-booking answers)
	 * @param array $attendees  roster rows: [ [ 'fields' => [ name => value ] ] ]
	 * @param int   $num_attendees Headcount, for shared answers.
	 * @return string[] Error messages; empty when everything fits.
	 */
	public static function check_option_capacity( $event_id, array $shared, array $attendees, $num_attendees = 1 ) {
		$errors = [];

		foreach ( self::get_fields( $event_id ) as $field ) {
			$caps = self::get_option_caps( $field );
			if ( empty( $caps ) ) continue;

			// How many spots this submission wants for each capped option.
			$want = array_fill_keys( array_keys( $caps ), 0 );

			if ( self::is_per_attendee( $field ) ) {
				foreach ( $attendees as $attendee ) {
					$answer = $attendee['fields'][ $field->field_name ] ?? null;
					if ( $answer === null || $answer === '' ) continue;
					foreach ( array_keys( $caps ) as $opt ) {
						if ( self::value_selects( $answer, $opt ) ) $want[ $opt ]++;
					}
				}
			} else {
				$answer = $shared[ $field->field_name ] ?? null;
				if ( $answer !== null && $answer !== '' ) {
					foreach ( array_keys( $caps ) as $opt ) {
						if ( self::value_selects( $answer, $opt ) ) {
							$want[ $opt ] += max( 1, (int) $num_attendees );
						}
					}
				}
			}

			$sold = null;
			foreach ( $want as $opt => $qty ) {
				if ( $qty <= 0 ) continue;
				if ( $sold === null ) $sold = self::get_option_sold_counts( $event_id, $field );
				$remaining = max( 0, (int) $caps[ $opt ] - (int) ( $sold[ $opt ] ?? 0 ) );
				if ( $qty > $remaining ) {
					$errors[] = $remaining === 0
						? sprintf(
							/* translators: 1: option label, 2: question label */
							__( '"%1$s" is now full for %2$s. Please choose another option.', 'church-event-manager' ),
							$opt,
							$field->field_label
						)
						: sprintf(
							/* translators: 1: spots left, 2: option label */
							_n(
								'Only %1$d spot is left for "%2$s" — please adjust your choices.',
								'Only %1$d spots are left for "%2$s" — please adjust your choices.',
								$remaining,
								'church-event-manager'
							),
							$remaining,
							$opt
						);
				}
			}
		}

		return $errors;
	}

	// ── Stored rosters ────────────────────────────────────────────────────────

	/**
	 * The per-attendee roster saved against a registration, decoded.
	 *
	 * @return array Empty when the registration has no roster (the normal case
	 *               for events without "per person" questions).
	 */
	public static function get_roster( $registration_id ) {
		global $wpdb;
		$json = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}cem_registration_meta
			 WHERE registration_id = %d AND meta_key = '_attendees' LIMIT 1",
			(int) $registration_id
		) );
		if ( ! $json ) return [];
		$roster = json_decode( (string) $json, true );
		return is_array( $roster ) ? $roster : [];
	}

	/**
	 * Shape a stored roster for display — resolves field_name keys to the
	 * admin's question labels and drops unanswered questions.
	 *
	 * Shared by the confirmation email, the admin detail modal and the CSV
	 * export so all three describe a booking the same way.
	 *
	 * @return array [ [ 'name' => str, 'answers' => [ [ 'label'=>, 'value'=> ] ] ] ]
	 */
	public static function describe_roster( $event_id, array $roster ) {
		if ( empty( $roster ) ) return [];

		$labels = [];
		foreach ( self::get_fields( $event_id ) as $field ) {
			$labels[ $field->field_name ] = $field->field_label;
		}

		$out = [];
		foreach ( $roster as $i => $person ) {
			$name = trim( ( $person['first_name'] ?? '' ) . ' ' . ( $person['last_name'] ?? '' ) );
			if ( $name === '' ) {
				/* translators: %d: attendee number */
				$name = sprintf( __( 'Attendee %d', 'church-event-manager' ), (int) $i + 1 );
			}

			$answers = [];
			foreach ( (array) ( $person['fields'] ?? [] ) as $field_name => $value ) {
				$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				if ( trim( $value ) === '' ) continue;
				$answers[] = [
					'label' => $labels[ $field_name ] ?? $field_name,
					'value' => $value,
				];
			}

			$out[] = [ 'name' => $name, 'answers' => $answers ];
		}

		return $out;
	}

	/** One-line summary of a described roster, for CSV cells and list views. */
	public static function summarize_roster( array $described ) {
		$parts = [];
		foreach ( $described as $person ) {
			$bits = [];
			foreach ( $person['answers'] as $answer ) {
				$bits[] = $answer['label'] . ': ' . $answer['value'];
			}
			$parts[] = $bits
				? $person['name'] . ' (' . implode( '; ', $bits ) . ')'
				: $person['name'];
		}
		return implode( ' | ', $parts );
	}

	// ── Front-end rendering ───────────────────────────────────────────────────

	/**
	 * Input name for a field, optionally scoped to one attendee.
	 *
	 * Shared:       cem_custom_dietary_needs
	 * Per-attendee: cem_attendee[2][fields][dietary_needs]
	 */
	public static function build_field_name( $field, $attendee_index = null, $multi = false ) {
		$name = $attendee_index === null
			? 'cem_custom_' . $field->field_name
			: 'cem_attendee[' . (int) $attendee_index . '][fields][' . $field->field_name . ']';
		return $multi ? $name . '[]' : $name;
	}

	/**
	 * Render a custom field for the registration form.
	 *
	 * @param object $field
	 * @param array  $args {
	 *   @type int|null $attendee_index  Scope inputs to one attendee (null = shared).
	 *   @type array    $remaining       [ option => spots left ] to show/disable options.
	 * }
	 *
	 * Inputs inside a hidden attendee block are `disabled` by the front-end JS,
	 * which keeps them out of both validation and the submitted payload — so
	 * `required` can be emitted unconditionally here.
	 */
	public static function render_field_html( $field, array $args = [] ) {
		$attendee_index = $args['attendee_index'] ?? null;
		$remaining      = $args['remaining'] ?? [];

		$name     = self::build_field_name( $field, $attendee_index );
		$name_arr = self::build_field_name( $field, $attendee_index, true );
		// Element ids must be unique across attendee blocks or clicking a label
		// would focus/toggle the first attendee's input instead of its own.
		$id_base  = 'cem_' . ( $attendee_index === null ? 'shared' : 'att' . (int) $attendee_index ) . '_' . $field->field_name;
		$required = $field->required ? 'required' : '';
		$req_star = $field->required ? '<span class="cem-required">*</span>' : '';
		$label    = esc_html( $field->field_label );

		$classes = 'cem-field cem-field-type-' . $field->field_type;
		if ( $field->required ) $classes .= ' cem-field-required';

		echo "<div class='" . esc_attr( $classes ) . "' data-field-name='" . esc_attr( $field->field_name ) . "'>";
		echo "<label for='" . esc_attr( $id_base ) . "'>$label $req_star</label>";

		switch ( $field->field_type ) {
			case 'textarea':
				echo "<textarea id='" . esc_attr( $id_base ) . "' name='" . esc_attr( $name ) . "' rows='4' $required></textarea>";
				break;

			case 'select':
				echo "<select id='" . esc_attr( $id_base ) . "' name='" . esc_attr( $name ) . "' $required>";
				echo "<option value=''>" . esc_html__( '— Select —', 'church-event-manager' ) . "</option>";
				foreach ( self::get_options( $field ) as $opt ) {
					$left = $remaining[ $opt ] ?? null;
					$full = ( $left !== null && $left <= 0 );
					$text = $opt . self::option_suffix( $left );
					echo "<option value='" . esc_attr( $opt ) . "' " . ( $full ? 'disabled' : '' ) . ">"
						. esc_html( $text ) . "</option>";
				}
				echo "</select>";
				break;

			case 'radio':
				// Optional questions can be un-picked. A native radio can't be
				// cleared by clicking it again, so anyone who ticks something
				// like "Under 21" by mistake is otherwise stuck with it.
				$allow_clear = ! $field->required;

				foreach ( self::get_options( $field ) as $opt ) {
					$left    = $remaining[ $opt ] ?? null;
					$full    = ( $left !== null && $left <= 0 );
					$id_slug = $id_base . '_' . sanitize_key( $opt );
					$cls     = 'cem-option-label' . ( $full ? ' cem-option-full' : '' );
					echo "<label class='" . esc_attr( $cls ) . "' for='" . esc_attr( $id_slug ) . "'>";
					echo "<input type='radio' id='" . esc_attr( $id_slug ) . "' name='" . esc_attr( $name ) . "'"
						. " value='" . esc_attr( $opt ) . "'"
						. ( $allow_clear ? " data-cem-deselect='1'" : '' )
						. ( $full ? ' disabled' : '' ) . " $required>";
					echo " <span class='cem-option-text'>" . esc_html( $opt ) . "</span>";
					echo self::option_badge( $left ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo "</label>";
				}

				// Clicking the selected option again clears it, but that isn't
				// discoverable — so optional questions also get a visible
				// control, revealed by cem-public.js once something is picked.
				if ( $allow_clear ) {
					echo "<button type='button' class='cem-clear-choice' hidden>"
						. esc_html__( 'Clear selection', 'church-event-manager' ) . "</button>";
				}
				break;

			case 'checkbox':
				foreach ( self::get_options( $field ) as $opt ) {
					$left    = $remaining[ $opt ] ?? null;
					$full    = ( $left !== null && $left <= 0 );
					$id_slug = $id_base . '_' . sanitize_key( $opt );
					$cls     = 'cem-option-label' . ( $full ? ' cem-option-full' : '' );
					echo "<label class='" . esc_attr( $cls ) . "' for='" . esc_attr( $id_slug ) . "'>";
					echo "<input type='checkbox' id='" . esc_attr( $id_slug ) . "' name='" . esc_attr( $name_arr ) . "'"
						. " value='" . esc_attr( $opt ) . "' " . ( $full ? 'disabled' : '' ) . ">";
					echo " <span class='cem-option-text'>" . esc_html( $opt ) . "</span>";
					echo self::option_badge( $left ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo "</label>";
				}
				break;

			case 'waiver':
				echo "<div class='cem-waiver-text'>" . wpautop( wp_kses_post( $field->field_options ) ) . "</div>";
				echo "<label class='cem-option-label' for='" . esc_attr( $id_base ) . "'>"
					. "<input type='checkbox' id='" . esc_attr( $id_base ) . "' name='" . esc_attr( $name ) . "' value='agreed' $required> "
					. esc_html__( 'I agree to the above', 'church-event-manager' ) . "</label>";
				break;

			default: // text, email, phone, number, date
				$type = in_array( $field->field_type, [ 'email', 'number', 'date' ], true ) ? $field->field_type : 'text';
				echo "<input type='" . esc_attr( $type ) . "' id='" . esc_attr( $id_base ) . "' name='" . esc_attr( $name ) . "' $required>";
				break;
		}

		echo "</div>";
	}

	/** " (Full)" / " (3 left)" appended to <option> text, which can't hold markup. */
	private static function option_suffix( $left ) {
		if ( $left === null ) return '';
		if ( $left <= 0 )    return ' — ' . __( 'Full', 'church-event-manager' );
		return sprintf(
			/* translators: %d: spots remaining */
			' — ' . _n( '%d spot left', '%d spots left', $left, 'church-event-manager' ),
			$left
		);
	}

	/** Availability badge for radio/checkbox option labels. */
	private static function option_badge( $left ) {
		if ( $left === null ) return '';
		if ( $left <= 0 ) {
			return '<span class="cem-option-avail cem-option-avail--full">'
				. esc_html__( 'Full', 'church-event-manager' ) . '</span>';
		}
		return '<span class="cem-option-avail">' . esc_html( sprintf(
			/* translators: %d: spots remaining */
			_n( '%d spot left', '%d spots left', $left, 'church-event-manager' ),
			$left
		) ) . '</span>';
	}

	// ── Validation ────────────────────────────────────────────────────────────

	/**
	 * Validate the whole-booking (shared) custom fields.
	 *
	 * Per-attendee questions are validated separately against the roster — see
	 * validate_attendees() — because their inputs live under cem_attendee[].
	 */
	public static function validate_posted_fields( $event_id, array $post_data ) {
		$errors = [];

		foreach ( self::get_fields( $event_id ) as $field ) {
			if ( self::is_per_attendee( $field ) ) continue;
			if ( ! $field->required ) continue;

			$value = $post_data[ 'cem_custom_' . $field->field_name ] ?? '';
			if ( self::is_empty_answer( $value ) ) {
				$errors[] = sprintf(
					/* translators: %s: question label */
					__( '"%s" is required.', 'church-event-manager' ),
					$field->field_label
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate the per-attendee roster: every attendee needs a name and an
	 * answer to each required per-attendee question.
	 *
	 * @param array $attendees Normalized roster (see CEM_Ajax::parse_attendees).
	 * @return string[]
	 */
	public static function validate_attendees( $event_id, array $attendees ) {
		$errors     = [];
		$per_fields = array_filter( self::get_fields( $event_id ), [ self::class, 'is_per_attendee' ] );
		if ( empty( $per_fields ) ) return $errors;

		foreach ( $attendees as $i => $attendee ) {
			$who = trim( ( $attendee['first_name'] ?? '' ) . ' ' . ( $attendee['last_name'] ?? '' ) );
			if ( $who === '' ) {
				/* translators: %d: attendee number */
				$who = sprintf( __( 'Attendee %d', 'church-event-manager' ), (int) $i + 1 );
				$errors[] = sprintf(
					/* translators: %d: attendee number */
					__( 'Please enter a name for attendee %d.', 'church-event-manager' ),
					(int) $i + 1
				);
			}

			foreach ( $per_fields as $field ) {
				if ( ! $field->required ) continue;
				if ( self::is_empty_answer( $attendee['fields'][ $field->field_name ] ?? '' ) ) {
					$errors[] = sprintf(
						/* translators: 1: question label, 2: attendee name or number */
						__( '"%1$s" is required for %2$s.', 'church-event-manager' ),
						$field->field_label,
						$who
					);
				}
			}
		}

		return $errors;
	}

	/** Treats "", [], null and all-blank arrays as no answer. */
	private static function is_empty_answer( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( trim( (string) $v ) !== '' ) return false;
			}
			return true;
		}
		return trim( (string) $value ) === '';
	}
}
