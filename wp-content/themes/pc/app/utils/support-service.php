<?php

namespace PC;

use WP_Error;
use WP_Query;

/**
 * Support subjects (CPT `pc_support_subject`) and tickets
 * (`wp_pc_support_tickets`, Install_Schema 1.6.0).
 *
 * Controllers own HTTP; this class owns the storage rules — what a valid
 * ticket is, how the subject list is replaced atomically, and who gets
 * the notification mail.
 */
final class Support_Service {

	public const STATUS_OPEN        = 'open';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_RESOLVED    = 'resolved';
	public const STATUS_CLOSED      = 'closed';

	public const DESCRIPTION_MIN = 10;
	public const DESCRIPTION_MAX = 5000;

	public static function status_values(): array {
		return [ self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED ];
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_support_tickets';
	}

	/* ---------------------------------------------------------------
	 * Subjects
	 * ------------------------------------------------------------- */

	/**
	 * Subject list in `menu_order`. Players see published subjects only;
	 * the admin list also carries drafts, which is how a subject is
	 * retired without breaking the tickets that reference it.
	 */
	public static function subjects( bool $include_hidden = false ): array {
		$query = new WP_Query( [
			'post_type'      => 'pc_support_subject',
			'post_status'    => $include_hidden ? [ 'publish', 'draft' ] : 'publish',
			'posts_per_page' => 100,
			'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
			'no_found_rows'  => true,
		] );

		return array_map( static fn( $post ) => [
			'id'     => (int) $post->ID,
			'label'  => $post->post_title,
			'hidden' => 'publish' !== $post->post_status,
			'order'  => (int) $post->menu_order,
		], $query->posts );
	}

	public static function subject_is_selectable( int $subject_id ): bool {
		$post = get_post( $subject_id );
		return $post && 'pc_support_subject' === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Replace the whole subject list in one call.
	 *
	 * `$items` is an ordered array of `{ id?, label, hidden? }`. Items
	 * with an `id` are updated in place so existing tickets keep pointing
	 * at a live subject; items without one are created. Anything absent
	 * from the payload is trashed, not deleted — a trashed subject still
	 * resolves to a label when an old ticket is rendered.
	 *
	 * Array position sets `menu_order`, so the admin reorders by
	 * reordering the payload.
	 */
	public static function replace_subjects( array $items ) {
		$seen = [];

		foreach ( array_values( $items ) as $index => $item ) {
			$label = trim( (string) ( $item['label'] ?? '' ) );
			if ( '' === $label ) {
				return new WP_Error( 'invalid_subject', 'Every subject needs a label.', [ 'status' => 400 ] );
			}
			if ( mb_strlen( $label ) > 200 ) {
				return new WP_Error( 'invalid_subject', 'Subject labels are limited to 200 characters.', [ 'status' => 400 ] );
			}

			$postarr = [
				'post_type'   => 'pc_support_subject',
				'post_title'  => $label,
				'post_status' => ! empty( $item['hidden'] ) ? 'draft' : 'publish',
				'menu_order'  => $index,
			];

			$existing_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
			if ( $existing_id && self::is_subject( $existing_id ) ) {
				$postarr['ID'] = $existing_id;
				$post_id       = wp_update_post( $postarr, true );
			} else {
				$post_id = wp_insert_post( $postarr, true );
			}

			if ( is_wp_error( $post_id ) ) {
				return new WP_Error( 'invalid_subject', $post_id->get_error_message(), [ 'status' => 400 ] );
			}

			$seen[] = (int) $post_id;
		}

		foreach ( self::subjects( true ) as $subject ) {
			if ( ! in_array( $subject['id'], $seen, true ) ) {
				wp_trash_post( $subject['id'] );
			}
		}

		return self::subjects( true );
	}

	private static function is_subject( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post && 'pc_support_subject' === $post->post_type;
	}

	/* ---------------------------------------------------------------
	 * Tickets
	 * ------------------------------------------------------------- */

	/**
	 * Validate + persist a ticket, then notify support.
	 *
	 * `$data` keys: `user_id` (nullable), `email`, `subject_id`,
	 * `description`, `email_verified`.
	 */
	public static function create_ticket( array $data ) {
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.', [ 'status' => 400 ] );
		}

		$subject_id = (int) ( $data['subject_id'] ?? 0 );
		if ( ! self::subject_is_selectable( $subject_id ) ) {
			return new WP_Error( 'subject_not_found', 'Unknown support subject.', [ 'status' => 404 ] );
		}

		$description = trim( (string) ( $data['description'] ?? '' ) );
		$length      = mb_strlen( $description );
		if ( $length < self::DESCRIPTION_MIN || $length > self::DESCRIPTION_MAX ) {
			return new WP_Error(
				'invalid_description',
				sprintf( 'Description must be between %d and %d characters.', self::DESCRIPTION_MIN, self::DESCRIPTION_MAX ),
				[ 'status' => 400 ]
			);
		}

		global $wpdb;
		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			self::table(),
			[
				'user_id'        => ! empty( $data['user_id'] ) ? (int) $data['user_id'] : null,
				'email'          => $email,
				'subject_id'     => $subject_id,
				'description'    => $description,
				'ip'             => self::ip_binary(),
				'user_agent'     => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 ),
				'email_verified' => ! empty( $data['email_verified'] ) ? 1 : 0,
				'status'         => self::STATUS_OPEN,
				'created_at'     => $now,
				'updated_at'     => $now,
			]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'ticket_write_failed', 'Could not save the ticket.', [ 'status' => 500 ] );
		}

		$ticket_id = (int) $wpdb->insert_id;
		self::notify_support( $ticket_id, $email, $subject_id, $description );

		return $ticket_id;
	}

	/**
	 * Paginated ticket list for the admin SPA. Filterable by status and
	 * by a free-text needle matched against email + description.
	 */
	public static function list_tickets( array $filters = [] ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$table    = self::table();

		$where  = '1=1';
		$params = [];

		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$needle   = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where   .= ' AND (email LIKE %s OR description LIKE %s)';
			$params[] = $needle;
			$params[] = $needle;
		}

		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", ...$params )
				: "SELECT COUNT(*) FROM $table WHERE $where"
		);

		$offset = ( $page - 1 ) * $per_page;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);

		return [
			'items'    => array_map( [ self::class, 'serialize_ticket' ], $rows ?: [] ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	public static function get_ticket( int $ticket_id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $ticket_id ), ARRAY_A );
		return $row ? self::serialize_ticket( $row ) : null;
	}

	public static function update_status( int $ticket_id, string $status ) {
		if ( ! in_array( $status, self::status_values(), true ) ) {
			return new WP_Error(
				'invalid_ticket_status',
				sprintf( 'status must be one of: %s.', implode( ', ', self::status_values() ) ),
				[ 'status' => 400 ]
			);
		}

		if ( ! self::get_ticket( $ticket_id ) ) {
			return new WP_Error( 'ticket_not_found', 'Unknown ticket.', [ 'status' => 404 ] );
		}

		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $ticket_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return self::get_ticket( $ticket_id );
	}

	/**
	 * Mail the operator. Replies happen from the mail client — the admin
	 * SPA tracks status, it is not an inbox — so the ticket's email is
	 * set as Reply-To and hitting reply lands on the player.
	 */
	private static function notify_support( int $ticket_id, string $email, int $subject_id, string $description ): void {
		$to = (string) get_option( 'pc_support_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject_post = get_post( $subject_id );
		$label        = $subject_post ? $subject_post->post_title : sprintf( '#%d', $subject_id );

		$message = sprintf(
			"New support ticket #%d\n\nFrom: %s\nSubject: %s\n\n%s\n",
			$ticket_id,
			$email,
			$label,
			$description
		);

		wp_mail(
			$to,
			sprintf( '[Pusher Coin] Support ticket #%d — %s', $ticket_id, $label ),
			$message,
			[
				'Content-Type: text/plain; charset=UTF-8',
				sprintf( 'Reply-To: %s', $email ),
			]
		);
	}

	private static function serialize_ticket( array $row ): array {
		$subject = get_post( (int) $row['subject_id'] );

		return [
			'id'             => (int) $row['id'],
			'user_id'        => null === $row['user_id'] ? null : (int) $row['user_id'],
			'email'          => $row['email'],
			'subject_id'     => (int) $row['subject_id'],
			'subject_label'  => $subject ? $subject->post_title : null,
			'description'    => $row['description'],
			'email_verified' => (bool) (int) $row['email_verified'],
			'status'         => $row['status'],
			'ip'             => $row['ip'] ? ( @inet_ntop( $row['ip'] ) ?: null ) : null,
			'user_agent'     => $row['user_agent'],
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'],
		];
	}

	private static function ip_binary(): ?string {
		$packed = @inet_pton( Rate_Limiter::client_ip() );
		return false === $packed ? null : $packed;
	}
}
