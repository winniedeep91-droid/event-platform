<?php
/**
 * The permanent identity-resolution service for EventOS.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

use EventOS\Activity_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One resolution path, reused by every caller — historical backfill today,
 * and future WooCommerce purchases, guest/ticket creation, marketing,
 * rewards and the customer portal. Nothing that resolves identity should
 * ever go around this class.
 *
 * Trust order, deliberately narrow:
 *   1. An existing identity linked to a non-zero WooCommerce customer ID.
 *   2. An existing identity linked to the normalized email.
 *   3. A Person whose own `primary_email` already matches, with no formal
 *      identity row yet (defensive — covers a Person created before an
 *      identity was ever attached).
 *   4. Otherwise, create a new Person.
 *
 * Phone is intentionally never a matching signal — see
 * {@see Identity_Normalizer}. Name is never a matching signal either: two
 * different people can share a name, so it is only ever used to fill a
 * genuinely blank profile field, never to find or merge a Person.
 *
 * Conflicts are never resolved automatically. If a signal turns out to
 * already belong to a different Person than the one this call resolved to,
 * {@see Person_Identity_Repository::attach_identity()} refuses to reassign
 * it, and that refusal is surfaced here as `conflict` in the return value
 * and logged to the Activity Log for review — not silently merged, and
 * neither Person is touched beyond that.
 */
final class Person_Resolver {

	/**
	 * Person repository.
	 *
	 * @var Person_Repository
	 */
	private Person_Repository $persons;

	/**
	 * Person identity repository.
	 *
	 * @var Person_Identity_Repository
	 */
	private Person_Identity_Repository $identities;

	/**
	 * Timeline service.
	 *
	 * @var Person_Timeline_Service
	 */
	private Person_Timeline_Service $timeline;

	/**
	 * Constructor.
	 *
	 * @param Person_Repository          $persons    Person repository.
	 * @param Person_Identity_Repository $identities Person identity repository.
	 * @param Person_Timeline_Service    $timeline   Timeline service.
	 */
	public function __construct( Person_Repository $persons, Person_Identity_Repository $identities, Person_Timeline_Service $timeline ) {
		$this->persons    = $persons;
		$this->identities = $identities;
		$this->timeline   = $timeline;
	}

	/**
	 * Resolve a Person for one set of identity signals, creating one only
	 * if nothing matches. Safe to call repeatedly with the same signals —
	 * see the class docblock and the concurrency note on
	 * {@see Person_Identity_Repository::attach_identity()}.
	 *
	 * Accepted keys: wc_customer_id (int, 0 = none), email, name, phone
	 * (all raw/un-normalized), source, source_id (free-form provenance,
	 * recorded on the identity_attached timeline entry only — the
	 * `eventos_person_identities` table itself stays exactly the Phase 1
	 * schema, see class-person-backfill-service.php's docblock for why).
	 *
	 * @param array<string, mixed> $signals Identity signals.
	 * @return array{person: array<string, mixed>, created: bool, attachments: array<string, array<string, mixed>>, conflict: array<string, mixed>|null}
	 */
	public function find_or_create( array $signals ): array {
		$wc_customer_id = (int) ( $signals['wc_customer_id'] ?? 0 );
		$email          = Identity_Normalizer::normalize_email( (string) ( $signals['email'] ?? '' ) );
		$name           = trim( (string) ( $signals['name'] ?? '' ) );
		$phone          = Identity_Normalizer::normalize_phone( (string) ( $signals['phone'] ?? '' ) );
		$source         = (string) ( $signals['source'] ?? 'unknown' );
		$source_id      = (string) ( $signals['source_id'] ?? '' );

		$resolved_person_id = $this->resolve_person_id( $wc_customer_id, $email );
		$created            = false;

		if ( null === $resolved_person_id ) {
			$person = $this->persons->create(
				array(
					'display_name'  => $name,
					'primary_email' => $email,
					'primary_phone' => $phone,
				)
			);

			$resolved_person_id = (int) $person['id'];
			$created            = true;

			$this->timeline->record(
				$resolved_person_id,
				'person_created',
				array(
					'source'    => $source,
					'source_id' => $source_id,
				)
			);
		}

		$attachments = array();
		$conflict    = null;

		if ( $wc_customer_id > 0 ) {
			$attachments['wc_customer_id'] = $this->identities->attach_identity( $resolved_person_id, 'wc_customer_id', (string) $wc_customer_id );
		}

		if ( '' !== $email ) {
			$attachments['email'] = $this->identities->attach_identity( $resolved_person_id, 'email', $email );
		}

		foreach ( $attachments as $signal_type => $result ) {
			if ( 'attached' === $result['status'] ) {
				$this->timeline->record(
					$resolved_person_id,
					'identity_attached',
					array(
						'type'      => $signal_type,
						'source'    => $source,
						'source_id' => $source_id,
					)
				);
			} elseif ( 'conflict' === $result['status'] ) {
				$conflict = array(
					'signal_type'        => $signal_type,
					'value'              => $result['identity']['value'],
					'resolved_person_id' => $resolved_person_id,
					'owner_person_id'    => $result['owner_person_id'],
				);

				Activity_Log::log(
					array(
						'action'      => 'crm_identity_conflict',
						'module'      => 'crm',
						'severity'    => Activity_Log::SEVERITY_WARNING,
						'object_type' => 'person',
						'object_id'   => (string) $resolved_person_id,
						'context'     => array_merge( $conflict, array( 'source' => $source, 'source_id' => $source_id ) ),
					)
				);
			}
		}

		$person = $this->fill_blank_profile_fields( $this->persons->find_by_id( $resolved_person_id ), $name, $email, $phone );

		return array(
			'person'      => $person,
			'created'     => $created,
			'attachments' => $attachments,
			'conflict'    => $conflict,
		);
	}

	/**
	 * Find the Person owning an identity signal, if any. Delegates to
	 * {@see Person_Identity_Repository}; normalizes email the same way
	 * {@see self::find_or_create()} does so a caller can never accidentally
	 * look up an un-normalized value and get a false miss.
	 *
	 * @param string $type  Identity type, e.g. 'wc_customer_id' or 'email'.
	 * @param string $value Raw identity value.
	 * @return array<string, mixed>|null
	 */
	public function find_by_identity( string $type, string $value ): ?array {
		$normalized = 'email' === $type ? Identity_Normalizer::normalize_email( $value ) : trim( $value );
		$identity   = $this->identities->find_by_type_value( $type, $normalized );

		return null === $identity ? null : $this->persons->find_by_id( (int) $identity['person_id'] );
	}

	/**
	 * The trust-order lookup described in the class docblock, steps 1–3.
	 *
	 * @param int    $wc_customer_id WooCommerce customer ID, 0 when absent.
	 * @param string $email          Already-normalized email, '' when absent.
	 * @return int|null
	 */
	private function resolve_person_id( int $wc_customer_id, string $email ): ?int {
		if ( $wc_customer_id > 0 ) {
			$identity = $this->identities->find_by_type_value( 'wc_customer_id', (string) $wc_customer_id );

			if ( null !== $identity ) {
				return (int) $identity['person_id'];
			}
		}

		if ( '' !== $email ) {
			$identity = $this->identities->find_by_type_value( 'email', $email );

			if ( null !== $identity ) {
				return (int) $identity['person_id'];
			}

			$fallback = $this->persons->find_by_primary_email( $email );

			if ( null !== $fallback ) {
				return (int) $fallback['id'];
			}
		}

		return null;
	}

	/**
	 * Fill genuinely blank profile fields only — never overwrite a
	 * meaningful existing value, whether with blank or with a different
	 * value from another source. See the class docblock's "source
	 * precedence" note and Section 13 of your Phase 2 brief.
	 *
	 * @param array<string, mixed> $person Current Person row.
	 * @param string                $name   Raw display name from this call's signals.
	 * @param string                $email  Normalized email from this call's signals.
	 * @param string                $phone  Normalized phone from this call's signals.
	 * @return array<string, mixed>
	 */
	private function fill_blank_profile_fields( array $person, string $name, string $email, string $phone ): array {
		$updates = array();

		if ( '' === $person['display_name'] && '' !== $name ) {
			$updates['display_name'] = $name;
		}

		if ( '' === $person['primary_email'] && '' !== $email ) {
			$updates['primary_email'] = $email;
		}

		if ( '' === $person['primary_phone'] && '' !== $phone ) {
			$updates['primary_phone'] = $phone;
		}

		if ( ! $updates ) {
			return $person;
		}

		return $this->persons->update( (int) $person['id'], $updates ) ?? $person;
	}
}
