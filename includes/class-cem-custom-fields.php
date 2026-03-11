<?php
/**
 * Manages per-event custom registration fields.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Custom_Fields {

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
						<th><?php esc_html_e( 'Type', 'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Options (comma-separated)', 'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Required', 'church-event-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $fields ) ) : ?>
					<tr class="cem-no-fields">
						<td colspan="6"><?php esc_html_e( 'No custom fields added yet.', 'church-event-manager' ); ?></td>
					</tr>
					<?php else : ?>
					<?php foreach ( $fields as $i => $field ) : ?>
					<tr class="cem-field-row" data-index="<?php echo esc_attr( $i ); ?>">
						<td class="cem-drag-handle">⠿</td>
						<td>
							<input type="text" name="cem_fields[<?php echo $i; ?>][label]"
								value="<?php echo esc_attr( $field->field_label ); ?>"
								class="widefat" placeholder="<?php esc_attr_e( 'e.g. Dietary Restrictions', 'church-event-manager' ); ?>">
							<input type="hidden" name="cem_fields[<?php echo $i; ?>][id]" value="<?php echo esc_attr( $field->id ); ?>">
						</td>
						<td>
							<select name="cem_fields[<?php echo $i; ?>][type]" class="cem-field-type">
								<?php foreach ( self::get_field_types() as $type => $label ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field->field_type, $type ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="text" name="cem_fields[<?php echo $i; ?>][options]"
								value="<?php echo esc_attr( $field->field_options ); ?>"
								class="widefat cem-field-options"
								placeholder="<?php esc_attr_e( 'Option A, Option B, Option C', 'church-event-manager' ); ?>"
								<?php echo in_array( $field->field_type, [ 'select', 'radio', 'checkbox' ] ) ? '' : 'style="display:none"'; ?>>
						</td>
						<td style="text-align:center">
							<input type="checkbox" name="cem_fields[<?php echo $i; ?>][required]" value="1"
								<?php checked( $field->required, 1 ); ?>>
						</td>
						<td>
							<button type="button" class="button button-small cem-remove-field">✕</button>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="cem-add-field">
					+ <?php esc_html_e( 'Add Field', 'church-event-manager' ); ?>
				</button>
			</p>
		</div>

		<script>
		jQuery(function($){
			var idx = <?php echo count( $fields ); ?>;

			$('#cem-add-field').on('click', function(){
				$('.cem-no-fields').remove();
				var row = '<tr class="cem-field-row" data-index="'+ idx +'">'
					+ '<td class="cem-drag-handle">⠿</td>'
					+ '<td><input type="text" name="cem_fields['+idx+'][label]" class="widefat" placeholder="Field label"><input type="hidden" name="cem_fields['+idx+'][id]" value="0"></td>'
					+ '<td><select name="cem_fields['+idx+'][type]" class="cem-field-type">'
					+ <?php echo json_encode( implode( '', array_map(
						fn( $t, $l ) => "<option value='$t'>$l</option>",
						array_keys( self::get_field_types() ),
						self::get_field_types()
					) ) ); ?>
					+ '</select></td>'
					+ '<td><input type="text" name="cem_fields['+idx+'][options]" class="widefat cem-field-options" placeholder="Option A, Option B" style="display:none"></td>'
					+ '<td style="text-align:center"><input type="checkbox" name="cem_fields['+idx+'][required]" value="1"></td>'
					+ '<td><button type="button" class="button button-small cem-remove-field">✕</button></td>'
					+ '</tr>';
				$('#cem-fields-list tbody').append(row);
				idx++;
			});

			$(document).on('click', '.cem-remove-field', function(){
				$(this).closest('tr').remove();
			});

			$(document).on('change', '.cem-field-type', function(){
				var show = ['select','radio','checkbox'].includes($(this).val());
				$(this).closest('tr').find('.cem-field-options').toggle(show);
			});

			// Sortable rows
			if ( $.fn.sortable ) {
				$('#cem-fields-list tbody').sortable({ handle: '.cem-drag-handle' });
			}
		});
		</script>
		<?php
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
			$options = sanitize_text_field( $field['options'] ?? '' );
			$required= ! empty( $field['required'] ) ? 1 : 0;
			$name    = sanitize_key( strtolower( str_replace( ' ', '_', $label ) ) );
			$id      = (int) ( $field['id'] ?? 0 );

			if ( $id > 0 ) {
				$wpdb->update( $table, [
					'field_label'   => $label,
					'field_name'    => $name,
					'field_type'    => $type,
					'field_options' => $options,
					'required'      => $required,
					'sort_order'    => $sort,
				], [ 'id' => $id ], [ '%s','%s','%s','%s','%d','%d' ], [ '%d' ] );
			} else {
				$wpdb->insert( $table, [
					'event_id'      => $post_id,
					'field_label'   => $label,
					'field_name'    => $name,
					'field_type'    => $type,
					'field_options' => $options,
					'required'      => $required,
					'sort_order'    => $sort,
				], [ '%d','%s','%s','%s','%s','%d','%d' ] );
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

	/** Render HTML for a custom field in the registration form. */
	public static function render_field_html( $field ) {
		$name     = 'cem_custom_' . esc_attr( $field->field_name );
		$required = $field->required ? 'required' : '';
		$req_star = $field->required ? '<span class="cem-required">*</span>' : '';
		$label    = esc_html( $field->field_label );

		echo "<div class='cem-field cem-field-type-{$field->field_type}'>";
		echo "<label for='$name'>$label $req_star</label>";

		switch ( $field->field_type ) {
			case 'textarea':
				echo "<textarea id='$name' name='$name' rows='4' $required></textarea>";
				break;

			case 'select':
				$options = array_map( 'trim', explode( ',', $field->field_options ) );
				echo "<select id='$name' name='$name' $required>";
				echo "<option value=''>" . esc_html__( '— Select —', 'church-event-manager' ) . "</option>";
				foreach ( $options as $opt ) {
					echo "<option value='" . esc_attr( $opt ) . "'>" . esc_html( $opt ) . "</option>";
				}
				echo "</select>";
				break;

			case 'radio':
				$options = array_map( 'trim', explode( ',', $field->field_options ) );
				foreach ( $options as $opt ) {
					$id_slug = $name . '_' . sanitize_key( $opt );
					echo "<label class='cem-option-label'><input type='radio' id='$id_slug' name='$name' value='" . esc_attr( $opt ) . "' $required> " . esc_html( $opt ) . "</label>";
				}
				break;

			case 'checkbox':
				$options = array_map( 'trim', explode( ',', $field->field_options ) );
				foreach ( $options as $opt ) {
					$id_slug = $name . '_' . sanitize_key( $opt );
					echo "<label class='cem-option-label'><input type='checkbox' id='$id_slug' name='{$name}[]' value='" . esc_attr( $opt ) . "'> " . esc_html( $opt ) . "</label>";
				}
				break;

			case 'waiver':
				echo "<div class='cem-waiver-text'>" . wpautop( wp_kses_post( $field->field_options ) ) . "</div>";
				echo "<label class='cem-option-label'><input type='checkbox' name='$name' value='agreed' $required> " . esc_html__( 'I agree to the above', 'church-event-manager' ) . "</label>";
				break;

			default: // text, email, phone, number, date
				$type = in_array( $field->field_type, [ 'email', 'number', 'date' ] ) ? $field->field_type : 'text';
				echo "<input type='$type' id='$name' name='$name' $required>";
				break;
		}

		echo "</div>";
	}

	/** Validate posted custom fields. Returns array of error messages. */
	public static function validate_posted_fields( $event_id, array $post_data ) {
		$fields = self::get_fields( $event_id );
		$errors = [];

		foreach ( $fields as $field ) {
			$key   = 'cem_custom_' . $field->field_name;
			$value = $post_data[ $key ] ?? '';

			if ( $field->required && ( $value === '' || $value === [] || $value === null ) ) {
				$errors[] = sprintf( __( '"%s" is required.', 'church-event-manager' ), $field->field_label );
			}
		}

		return $errors;
	}
}
