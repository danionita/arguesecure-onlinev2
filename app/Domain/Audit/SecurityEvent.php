<?php

namespace App\Domain\Audit;

use Monolog\Level;

/**
 * Every line the security log can write (security.md §11). The value is the
 * line's `event` field, which an operator's SIEM rules match on: a value is
 * never renamed once released, only added.
 *
 * The `mfa.*` and `sso.*` cases are recorded for W116 and W117
 * (PLAN-2.7-identity.md), which dispatch SecurityEventOccurred rather than
 * writing a line of their own; they are named here so both packages build
 * against one list.
 */
enum SecurityEvent: string
{
    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Lockout = 'auth.lockout';
    case Logout = 'auth.logout';
    case PasswordChanged = 'auth.password_changed';

    case AccessDenied = 'authz.denied';

    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AdminGranted = 'account.admin_granted';
    case AdminRevoked = 'account.admin_revoked';
    case AccountDeactivated = 'account.deactivated';
    case AccountReactivated = 'account.reactivated';
    case PasswordReset = 'account.password_reset';
    case AccountDeleted = 'account.deleted';

    case SettingsUpdated = 'admin.settings_updated';
    case LogDownloaded = 'admin.log_downloaded';

    case ShareLinkCreated = 'share.link_created';
    case ShareLinkRevoked = 'share.link_revoked';
    case ShareLinkRedeemed = 'share.link_redeemed';
    case ShareLinkRejected = 'share.link_rejected';
    case PublicAccessChanged = 'share.public_changed';
    case CollaboratorAdded = 'share.collaborator_added';
    case CollaboratorRoleChanged = 'share.collaborator_role_changed';
    case CollaboratorRemoved = 'share.collaborator_removed';
    case OwnershipTransferred = 'share.ownership_transferred';

    case AssessmentExported = 'assessment.exported';

    // W141. A template is offered to every account and deleting one is final.
    case TemplatePublished = 'template.published';
    case TemplateCreated = 'template.created';
    case TemplateDeleted = 'template.deleted';

    case MfaEnabled = 'mfa.enabled';
    case MfaDisabled = 'mfa.disabled';
    case MfaRemovedByAdmin = 'mfa.removed_by_admin';
    case MfaRecoveryCodeUsed = 'mfa.recovery_code_used';
    case MfaChallengeFailed = 'mfa.challenge_failed';
    case SsoLogin = 'sso.login';
    case SsoLoginFailed = 'sso.login_failed';
    case SsoIdentityLinked = 'sso.identity_linked';
    case SsoIdentityUnlinked = 'sso.identity_unlinked';

    /** A refusal is a warning, so a SIEM can alert on level alone. */
    public function level(): Level
    {
        return match ($this) {
            self::LoginFailed,
            self::Lockout,
            self::AccessDenied,
            self::ShareLinkRejected,
            self::MfaChallengeFailed,
            self::SsoLoginFailed => Level::Warning,
            default => Level::Info,
        };
    }
}
