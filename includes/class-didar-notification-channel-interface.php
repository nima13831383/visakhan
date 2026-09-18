<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provider boundary for durable notification delivery. */
interface Didar_Notification_Channel_Interface {
	/**
	 * Send one already-snapshotted job using current protected credentials.
	 *
	 * @return array{success:bool,retryable:bool,provider_id:string,provider_code:string,error_code:string,error_message:string}
	 */
	public function send( $job, $credentials );
}
