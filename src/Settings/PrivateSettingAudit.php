<?php
/**
 * Records private-form outcomes without accepting an input value.
 *
 * @package Aculect\AICompanion\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Settings;

use Aculect\AICompanion\Activity\ActivityRepository;

/** A fixed metadata boundary for native browser submissions. */
final class PrivateSettingAudit {
	public function record( string $target, string $status ): void {
		if ( ! current_user_can( 'manage_options' ) || null === ( new PrivateSettingTargets() )->get( $target ) || ! in_array( $status, array( 'updated', 'failed', 'stale', 'busy', 'error' ), true ) ) {
			return;
		}
		try {
			( new ActivityRepository() )->insert(
				array(
					'provider'    => 'admin',
					'client_name' => 'WordPress private form',
					'user_id'     => get_current_user_id(),
					'action'      => 'settings.private_submit',
					'target_type' => 'setting',
					'status'      => 'updated' === $status ? 'success' : 'error',
					'error_code'  => 'updated' === $status ? '' : 'private_setting_' . $status,
					'message'     => 'Private settings form submission processed.',
					'context'     => array(
						'target'     => $target,
						'status'     => $status,
						'risk_level' => 'system',
					),
				)
			);
		} catch ( \Throwable ) {
			// Never echo logger exceptions or change the already-completed update.
			return;
		}
	}
}
