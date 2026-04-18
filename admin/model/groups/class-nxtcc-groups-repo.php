<?php
/**
 * Groups repository.
 *
 * Provides a clean API for handlers without exposing $wpdb usage. This layer is
 * responsible for passing tenant identifiers to the DB layer.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository layer for Groups.
 *
 * This class provides a stable API for the AJAX handlers and other callers.
 * It intentionally avoids direct database usage and delegates to the DB layer.
 *
 * Groups are strictly tenant-scoped by:
 * - user_mailid
 * - business_account_id
 * - phone_number_id
 */
final class NXTCC_Groups_Repo {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $inst = null;

	/**
	 * Get instance.
	 *
	 * @return self
	 */
	public static function i(): self {
		if ( null === self::$inst ) {
			self::$inst = new self();
		}
		return self::$inst;
	}

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	private function __construct() {}

	/**
	 * List groups for a tenant.
	 *
	 * @param string $owner     Owner identifier (user_mailid).
	 * @param string $baid      Business account ID.
	 * @param string $pnid      Phone number ID.
	 * @param string $search    Search term.
	 * @param string $order_key Sort key.
	 * @param string $order_dir Sort direction (asc|desc).
	 * @return array
	 */
	public function list_user_groups(
		string $owner,
		string $baid,
		string $pnid,
		string $search,
		string $order_key,
		string $order_dir
	): array {
		return NXTCC_Groups_DB::i()->list_groups( $owner, $baid, $pnid, $search, $order_key, $order_dir );
	}

	/**
	 * Get group row for a tenant.
	 *
	 * @param int    $id    Group ID.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return array|null
	 */
	public function get_group_for_owner( int $id, string $owner, string $baid, string $pnid ): ?array {
		return NXTCC_Groups_DB::i()->get_group_for_owner( $id, $owner, $baid, $pnid );
	}

	/**
	 * Get minimal group data for permission/verification checks.
	 *
	 * This method is not tenant-scoped by arguments because it is used to read a
	 * row by ID and then compare against the current tenant in the handler.
	 *
	 * @param int $id Group ID.
	 * @return object|null
	 */
	public function get_group_min( int $id ): ?object {
		return NXTCC_Groups_DB::i()->get_group_min( $id );
	}

	/**
	 * Count duplicates by tenant + group name.
	 *
	 * @param string $owner      Owner identifier (user_mailid).
	 * @param string $baid       Business account ID.
	 * @param string $pnid       Phone number ID.
	 * @param string $name       Group name.
	 * @param int    $exclude_id Excluded ID.
	 * @return int
	 */
	public function count_dupe( string $owner, string $baid, string $pnid, string $name, int $exclude_id = 0 ): int {
		return NXTCC_Groups_DB::i()->count_dupe( $owner, $baid, $pnid, $name, $exclude_id );
	}

	/**
	 * Insert a group (tenant scoped).
	 *
	 * @param string $name        Group name.
	 * @param string $owner       Owner identifier (user_mailid).
	 * @param string $baid        Business account ID.
	 * @param string $pnid        Phone number ID.
	 * @param int    $is_verified Verified flag.
	 * @param int    $actor_id    Acting WordPress user ID.
	 * @return int
	 */
	public function insert_group( string $name, string $owner, string $baid, string $pnid, int $is_verified, int $actor_id = 0 ): int {
		return NXTCC_Groups_DB::i()->insert_group( $name, $owner, $baid, $pnid, $is_verified, $actor_id );
	}

	/**
	 * Update group name (tenant list-version bump is tenant-scoped).
	 *
	 * @param int    $id    Group ID.
	 * @param string $name  Group name.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @param int    $actor_id Acting WordPress user ID.
	 * @return bool
	 */
	public function update_group_name( int $id, string $name, string $owner, string $baid, string $pnid, int $actor_id = 0 ): bool {
		return NXTCC_Groups_DB::i()->update_group_name( $id, $name, $owner, $baid, $pnid, $actor_id );
	}

	/**
	 * Delete mappings for one group (tenant list-version bump is tenant-scoped).
	 *
	 * @param int    $gid   Group ID.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function delete_mappings_for_group( int $gid, string $owner, string $baid, string $pnid ): void {
		NXTCC_Groups_DB::i()->delete_mappings_for_group( $gid, $owner, $baid, $pnid );
	}

	/**
	 * Delete mappings for multiple groups (tenant list-version bump is tenant-scoped).
	 *
	 * @param int[]  $gids  Group IDs.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function delete_mappings_for_groups( array $gids, string $owner, string $baid, string $pnid ): void {
		NXTCC_Groups_DB::i()->delete_mappings_for_groups( $gids, $owner, $baid, $pnid );
	}

	/**
	 * Delete a group (tenant list-version bump is tenant-scoped).
	 *
	 * @param int    $gid   Group ID.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return bool
	 */
	public function delete_group( int $gid, string $owner, string $baid, string $pnid ): bool {
		return NXTCC_Groups_DB::i()->delete_group( $gid, $owner, $baid, $pnid );
	}

	/**
	 * Delete multiple groups (tenant list-version bump is tenant-scoped).
	 *
	 * @param int[]  $gids  Group IDs.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function delete_groups( array $gids, string $owner, string $baid, string $pnid ): void {
		NXTCC_Groups_DB::i()->delete_groups( $gids, $owner, $baid, $pnid );
	}

	/**
	 * Get contact IDs for a group.
	 *
	 * @param int $gid Group ID.
	 * @return int[]
	 */
	public function get_contact_ids_for_group( int $gid ): array {
		return NXTCC_Groups_DB::i()->contact_ids_for_group( $gid );
	}

	/**
	 * Get contact IDs for multiple groups.
	 *
	 * @param int[] $gids Group IDs.
	 * @return int[]
	 */
	public function get_contact_ids_for_groups( array $gids ): array {
		return NXTCC_Groups_DB::i()->contact_ids_for_groups( $gids );
	}

	/**
	 * Recompute contact verification flags.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return void
	 */
	public function recompute_contacts_verification( array $contact_ids ): void {
		NXTCC_Groups_DB::i()->recompute_contacts_verification( $contact_ids );
	}

	/**
	 * Get minimal owned rows for a set of groups (tenant scoped).
	 *
	 * @param int[]  $ids   Group IDs.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return array
	 */
	public function get_owned_rows_min( array $ids, string $owner, string $baid, string $pnid ): array {
		return NXTCC_Groups_DB::i()->owned_rows_min( $ids, $owner, $baid, $pnid );
	}

	/**
	 * Update subscription for explicit contact IDs.
	 *
	 * @param int[]  $contact_ids Contact IDs.
	 * @param int    $set_to      1 to subscribe, 0 to unsubscribe.
	 * @param string $owner       Owner identifier (user_mailid).
	 * @param string $baid        Business account ID.
	 * @param string $pnid        Phone number ID.
	 * @return void
	 */
	public function update_contacts_subscription( array $contact_ids, int $set_to, string $owner, string $baid, string $pnid ): void {
		NXTCC_Groups_DB::i()->update_contacts_subscription( $contact_ids, $set_to, $owner, $baid, $pnid );
	}

	/**
	 * Update subscription for contacts within group IDs.
	 *
	 * @param int[]  $gids   Group IDs.
	 * @param int    $set_to 1 to subscribe, 0 to unsubscribe.
	 * @param string $owner  Owner identifier (user_mailid).
	 * @param string $baid   Business account ID.
	 * @param string $pnid   Phone number ID.
	 * @return void
	 */
	public function update_contacts_subscription_by_groups( array $gids, int $set_to, string $owner, string $baid, string $pnid ): void {
		NXTCC_Groups_DB::i()->update_contacts_subscription_by_groups( $gids, $set_to, $owner, $baid, $pnid );
	}
}
