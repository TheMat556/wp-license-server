<?php
/**
 * Native chat orchestration for owner/customer inbox flows.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\Contracts\ActivationRepositoryInterface;
use WpLicenseServer\Contracts\ActivityLogRepositoryInterface;
use WpLicenseServer\Models\ChatThread;
use WpLicenseServer\Models\License;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\ChatMessageRepository;
use WpLicenseServer\Repositories\ChatThreadRepository;

final class ChatService {

    private const GRACE_DAYS = 7;
    public const POLL_INTERVAL_SECONDS = 15;

    public function __construct(
        private readonly ChatThreadRepository $thread_repo,
        private readonly ChatMessageRepository $message_repo,
        private readonly ActivationRepositoryInterface $activation_repo,
        private readonly ActivityLogRepositoryInterface $activity_repo,
    ) {}

    /**
     * @return array{role: string, threads: array<int, array<string, mixed>>, selectedThreadId: ?int, messages: array<int, array<string, mixed>>, pollIntervalSeconds: int}|\WP_Error
     */
    public function bootstrap( License $license, string $domain, ?int $selected_thread_id = null ) {
        $activation = $this->require_active_activation( $license, $domain );
        if ( is_wp_error( $activation ) ) {
            return $activation;
        }

        if ( ! $this->license_allows_chat( $license ) ) {
            return new \WP_Error(
                'chat_not_available',
                'Chat is not available for this license.',
                [ 'status' => 403 ]
            );
        }

        $threads = $this->get_visible_threads( $license, $domain );
        $thread  = $this->resolve_selected_thread( $license, $threads, $selected_thread_id );

        if ( is_wp_error( $thread ) ) {
            return $thread;
        }

        $messages = $thread instanceof ChatThread ? $this->message_repo->find_for_thread( $thread->id ) : [];

        return [
            'role'                => $license->role,
            'threads'             => array_map( fn( ChatThread $item ) => $this->map_thread( $item, 'owner' === $license->role ), $threads ),
            'selectedThreadId'    => $thread instanceof ChatThread ? $thread->id : null,
            'messages'            => array_map( fn( $item ) => $this->map_message( $item ), $messages ),
            'pollIntervalSeconds' => self::POLL_INTERVAL_SECONDS,
        ];
    }

    /**
     * @return array{role: string, threads: array<int, array<string, mixed>>, selectedThreadId: int, messages: array<int, array<string, mixed>>, pollIntervalSeconds: int}|\WP_Error
     */
    public function poll( License $license, string $domain, int $selected_thread_id, int $after_message_id = 0 ) {
        $activation = $this->require_active_activation( $license, $domain );
        if ( is_wp_error( $activation ) ) {
            return $activation;
        }

        if ( ! $this->license_allows_chat( $license ) ) {
            return new \WP_Error(
                'chat_not_available',
                'Chat is not available for this license.',
                [ 'status' => 403 ]
            );
        }

        $threads = $this->get_visible_threads( $license, $domain );
        $thread  = $this->resolve_selected_thread( $license, $threads, $selected_thread_id );

        if ( is_wp_error( $thread ) || ! $thread instanceof ChatThread ) {
            return is_wp_error( $thread )
                ? $thread
                : new \WP_Error( 'chat_thread_required', 'A valid chat thread is required.', [ 'status' => 400 ] );
        }

        $messages = $this->message_repo->find_for_thread( $thread->id, max( 0, $after_message_id ) );

        return [
            'role'                => $license->role,
            'threads'             => array_map( fn( ChatThread $item ) => $this->map_thread( $item, 'owner' === $license->role ), $threads ),
            'selectedThreadId'    => $thread->id,
            'messages'            => array_map( fn( $item ) => $this->map_message( $item ), $messages ),
            'pollIntervalSeconds' => self::POLL_INTERVAL_SECONDS,
        ];
    }

    /**
     * @return array{role: string, thread: array<string, mixed>, message: array<string, mixed>}|\WP_Error
     */
    public function send_message( License $license, string $domain, int $selected_thread_id, string $message ) {
        $activation = $this->require_active_activation( $license, $domain );
        if ( is_wp_error( $activation ) ) {
            return $activation;
        }

        if ( ! $this->license_allows_chat( $license ) ) {
            return new \WP_Error(
                'chat_not_available',
                'Chat is not available for this license.',
                [ 'status' => 403 ]
            );
        }

        $threads = $this->get_visible_threads( $license, $domain );
        $thread  = $this->resolve_selected_thread( $license, $threads, $selected_thread_id );

        if ( is_wp_error( $thread ) || ! $thread instanceof ChatThread ) {
            return is_wp_error( $thread )
                ? $thread
                : new \WP_Error( 'chat_thread_required', 'A valid chat thread is required.', [ 'status' => 400 ] );
        }

        $content = trim( sanitize_textarea_field( $message ) );
        if ( '' === $content ) {
            return new \WP_Error(
                'chat_message_empty',
                'Enter a message before sending.',
                [ 'status' => 400 ]
            );
        }

        $author_role = 'owner' === $license->role ? 'owner' : 'customer';
        $author_name = '' !== $license->customer_name ? $license->customer_name : ( 'owner' === $author_role ? 'Owner' : $domain );
        $saved       = $this->message_repo->create( $thread->id, $author_role, $author_name, $content );

        $this->thread_repo->touch_after_message( $thread->id, $content );
        $updated_thread = $this->thread_repo->find_by_id( $thread->id ) ?? $thread;

        $this->activity_repo->insert(
            [
                'license_id' => $license->id,
                'action'     => 'chat_message_sent',
                'domain'     => $domain,
                'actor'      => 'chat:' . $author_role,
                'details'    => [
                    'thread_id'   => $updated_thread->id,
                    'author_role' => $author_role,
                ],
            ]
        );

        return [
            'role'    => $license->role,
            'thread'  => $this->map_thread( $updated_thread, 'owner' === $license->role ),
            'message' => $this->map_message( $saved ),
        ];
    }

    /**
     * @return ChatThread[]
     */
    private function get_visible_threads( License $license, string $domain ): array {
        if ( 'owner' === $license->role ) {
            return $this->thread_repo->find_all();
        }

        return [ $this->thread_repo->ensure_customer_thread( $license, $domain ) ];
    }

    /**
     * @param ChatThread[] $threads
     * @return ChatThread|\WP_Error|null
     */
    private function resolve_selected_thread( License $license, array $threads, ?int $selected_thread_id ) {
        if ( [] === $threads ) {
            return null;
        }

        if ( 'owner' !== $license->role ) {
            $thread = $threads[0];

            if ( null !== $selected_thread_id && $selected_thread_id !== $thread->id ) {
                return new \WP_Error(
                    'chat_thread_forbidden',
                    'You do not have access to that chat thread.',
                    [ 'status' => 403 ]
                );
            }

            return $thread;
        }

        if ( null === $selected_thread_id ) {
            return $threads[0];
        }

        foreach ( $threads as $thread ) {
            if ( $thread->id === $selected_thread_id ) {
                return $thread;
            }
        }

        return new \WP_Error(
            'chat_thread_not_found',
            'The requested chat thread could not be found.',
            [ 'status' => 404 ]
        );
    }

    /**
     * @return true|\WP_Error
     */
    private function require_active_activation( License $license, string $domain ) {
        $activation = $this->activation_repo->find_active( $license->id, $domain );

        if ( null !== $activation ) {
            return true;
        }

        return new \WP_Error(
            'chat_activation_required',
            'Chat access requires an active license activation for this site.',
            [ 'status' => 403 ]
        );
    }

    private function license_allows_chat( License $license ): bool {
        if ( ! in_array( 'native_chat', TierConfig::features_for_tier( $license->tier ), true ) ) {
            return false;
        }

        if ( in_array( $license->status, [ 'suspended', 'cancelled' ], true ) ) {
            return false;
        }

        $now        = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $expires_at = new \DateTime( $license->valid_until, new \DateTimeZone( 'UTC' ) );

        if ( $expires_at > $now ) {
            return 'active' === $license->status;
        }

        if ( ! in_array( $license->status, [ 'active', 'expired' ], true ) ) {
            return false;
        }

        $days_since = (int) $now->diff( $expires_at )->days;

        return $days_since <= self::GRACE_DAYS;
    }

    /**
     * @return array{id: int, domain: string, customerName: ?string, customerEmail: ?string, status: string, lastMessagePreview: ?string, lastMessageAt: string, createdAt: string}
     */
    private function map_thread( ChatThread $thread, bool $owner_view ): array {
        return [
            'id'                 => $thread->id,
            'domain'             => $thread->domain,
            'customerName'       => $owner_view ? $thread->customer_name : null,
            'customerEmail'      => $owner_view ? $thread->customer_email : null,
            'status'             => $thread->status,
            'lastMessagePreview' => $thread->last_message_preview,
            'lastMessageAt'      => $thread->last_message_at,
            'createdAt'          => $thread->created_at,
        ];
    }

    /**
     * @param \WpLicenseServer\Models\ChatMessage $message
     * @return array{id: int, authorRole: string, authorName: string, message: string, createdAt: string}
     */
    private function map_message( \WpLicenseServer\Models\ChatMessage $message ): array {
        return [
            'id'         => $message->id,
            'authorRole' => $message->author_role,
            'authorName' => $message->author_name,
            'message'    => $message->message,
            'createdAt'  => $message->created_at,
        ];
    }
}
