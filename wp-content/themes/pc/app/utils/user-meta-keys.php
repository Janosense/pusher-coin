<?php

namespace PC;

/**
 * Registry of user meta keys used by the pc/v1 REST controllers.
 *
 * Single source of truth: any meta key written or read by application code
 * must appear here. Phase 0 introduced this class so PHP no longer references
 * meta-key string literals inline.
 *
 * Adding a key: define the constant, document the owning controller / phase
 * inline, and update DATA-MODEL.md at the monorepo root.
 */
final class User_Meta_Keys {
	// Profile (signup, owned by UserController).
	public const PHONE = 'phone';

	// Email/password 2FA (UserController::request_verification_code / verify_code).
	public const VERIFICATION_CODE        = 'verification_code';
	public const VERIFICATION_CODE_EXPIRY = 'verification_code_expiry';

	// Google OAuth (GoogleAuthController).
	public const GOOGLE_ID                       = 'google_id';
	public const GOOGLE_VERIFICATION_CODE        = 'google_verification_code';
	public const GOOGLE_VERIFICATION_CODE_EXPIRY = 'google_verification_code_expiry';

	// Apple OAuth (AppleAuthController; stubbed in Phase 1).
	public const APPLE_ID                       = 'apple_id';
	public const APPLE_VERIFICATION_CODE        = 'apple_verification_code';
	public const APPLE_VERIFICATION_CODE_EXPIRY = 'apple_verification_code_expiry';

	// Phase 1 — terms acceptance and nickname state.
	public const TERMS_ACCEPTED_AT      = 'terms_accepted_at';
	public const TERMS_ACCEPTED_VERSION = 'terms_accepted_version';
	public const NICKNAME_CHOSEN        = 'nickname_chosen';
}
