<?php
/**
 * Notification service for activation alerts.
 *
 * Sends email alerts and outbound webhooks when a license is activated
 * on a new domain for the first time.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

class NotificationService {

    public function __construct(
        private readonly WebhookService $webhook_service,
    ) {}

    /**
     * Handle a new-domain activation event.
     *
     * Fires the wplicense_new_activation action hook, sends an email alert
     * to the license owner, and queues an outbound webhook.
     *
     * @param int    $license_id    The license ID.
     * @param string $owner_email   License owner's email address.
     * @param string $domain        The newly activated domain.
     * @param string $client_ip     IP address from the activation request.
     * @param string $activation_id UUID of the new activation.
     */
    public function on_new_activation(
        int    $license_id,
        string $owner_email,
        string $domain,
        string $client_ip,
        string $activation_id
    ): void {
        $payload = [
            'license_id'     => $license_id,
            'domain'         => $domain,
            'ip_address'     => $client_ip,
            'activation_id'  => $activation_id,
            'activated_at'   => ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
                                    ->format( \DateTimeInterface::ATOM ),
        ];

        // 1. Fire action hook for extensibility.
        do_action( 'wplicense_new_activation', $payload );

        // 2. Email alert to license owner.
        if ( ! empty( $owner_email ) && is_email( $owner_email ) ) {
            $this->send_email_alert( $owner_email, $payload );
        }

        // 3. Queue outbound webhook (deterministic: prevents double-alert on cron retry).
        $this->webhook_service->queue_event(
            $license_id,
            'new_activation',
            $payload,
            true
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
	private function send_email_alert( string $owner_email, array $payload ): void {
		$subject = sprintf(
			/* translators: %s: domain name */
			__( '[License Alert] New activation on domain: %s', 'wp-license-server' ),
			sanitize_text_field( $payload['domain'] )
		);

		$message = sprintf(
			/* translators: %1$s domain, %2$s IP address, %3$s activation time, %4$s activation ID */
			__( "A new activation was recorded for your license.\n\nDomain:        %1\$s\nIP Address:    %2\$s\nActivated At:  %3\$s\nActivation ID: %4\$s\n\nIf you did not authorize this activation, please revoke it in your dashboard.", 'wp-license-server' ),
			sanitize_text_field( $payload['domain'] ),
			sanitize_text_field( $payload['ip_address'] ),
			sanitize_text_field( $payload['activated_at'] ),
			sanitize_text_field( $payload['activation_id'] )
		);

		wp_mail( $owner_email, $subject, $message );
	}
}
