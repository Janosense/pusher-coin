<?php

namespace PC;

/**
 * Registry of post meta keys used by the pc/v1 REST controllers.
 *
 * Mirrors User_Meta_Keys for post-attached meta. Any meta key written or
 * read by application code against a CPT must appear here.
 *
 * Adding a key: define the constant, document the owning controller / phase
 * inline, and update DATA-MODEL.md at the monorepo root.
 */
final class Post_Meta_Keys {
	// Phase 3 — pc_room CPT (RoomController).
	public const ROOM_STATUS         = 'pc_room_status';
	public const ROOM_THEME_SONG_URL = 'pc_room_theme_song_url';
	public const ROOM_STREAM_URL     = 'pc_room_stream_url';
	public const ROOM_MACHINE_ID     = 'pc_room_machine_id';

	// pc_room_status enum values.
	public const ROOM_STATUS_AVAILABLE   = 'available';
	public const ROOM_STATUS_MAINTENANCE = 'maintenance';
	public const ROOM_STATUS_UNAVAILABLE = 'unavailable';

	public static function room_status_values(): array {
		return [
			self::ROOM_STATUS_AVAILABLE,
			self::ROOM_STATUS_MAINTENANCE,
			self::ROOM_STATUS_UNAVAILABLE,
		];
	}
}
