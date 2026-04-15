<?php
declare(strict_types=1);

namespace WpLicenseServer;

enum ErrorCodes: string {
    // Auth / HMAC
    case MISSING_AUTH_HEADERS    = 'missing_auth_headers';
    case INVALID_SIGNATURE       = 'invalid_signature';
    case REQUEST_EXPIRED         = 'request_expired';
    case REPLAY_DETECTED         = 'replay_detected';

    // License
    case LICENSE_NOT_FOUND       = 'license_not_found';
    case LICENSE_EXPIRED         = 'license_expired';
    case LICENSE_NOT_VALID       = 'license_not_valid';
    case INVALID_LICENSE_ID      = 'invalid_license_id';
    case DECRYPTION_FAILED       = 'decryption_failed';

    // Activation
    case ACTIVATION_FAILED       = 'activation_failed';
    case ACTIVATION_LIMIT_REACHED = 'activation_limit_reached';
    case ACTIVATION_SECRET_UNAVAILABLE = 'activation_secret_unavailable';
    case ALREADY_ACTIVATED       = 'already_activated';
    case NOT_ACTIVATED           = 'not_activated';

    // Validation
    case INVALID_KEY             = 'invalid_key';
    case INVALID_DOMAIN          = 'invalid_domain';
    case INVALID_EMAIL           = 'invalid_email';
    case INVALID_TIER            = 'invalid_tier';
    case INVALID_ROLE            = 'invalid_role';
    case INVALID_STATUS          = 'invalid_status';
    case INVALID_DATE            = 'invalid_date';
    case INVALID_MAX_ACTIVATIONS = 'invalid_max_activations';
    case INVALID_PAYMENT_INTERVAL = 'invalid_payment_interval';
    case INVALID_TRANSITION      = 'invalid_transition';
    case INVALID_WEBHOOK_PAYLOAD = 'invalid_webhook_payload';
    case DATE_IN_PAST            = 'date_in_past';
    case MISSING_VALID_UNTIL     = 'missing_valid_until';
    case EMPTY_UPDATE            = 'empty_update';

    // Mutations
    case ROTATION_FAILED         = 'rotation_failed';
    case ROTATION_IN_PROGRESS    = 'rotation_in_progress';
    case UPDATE_FAILED           = 'update_failed';
    case DELETE_FAILED           = 'delete_failed';
    case OWNER_EXISTS            = 'owner_exists';
    case OWNER_LOCK_FAILED       = 'owner_lock_failed';

    // Feature flags
    case FEATURE_NOT_AVAILABLE   = 'feature_not_available';
    case RATE_LIMIT_EXCEEDED     = 'rate_limit_exceeded';

    // Chat
    case CHAT_NOT_AVAILABLE      = 'chat_not_available';
    case CHAT_ACTIVATION_REQUIRED = 'chat_activation_required';
    case CHAT_THREAD_NOT_FOUND   = 'chat_thread_not_found';
    case CHAT_THREAD_FORBIDDEN   = 'chat_thread_forbidden';
    case CHAT_THREAD_REQUIRED    = 'chat_thread_required';
    case CHAT_MESSAGE_EMPTY      = 'chat_message_empty';
}
