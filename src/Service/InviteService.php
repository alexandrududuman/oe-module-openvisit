<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;

/** What the provider sees in the Invite panel, and the optional email. */
final class InviteService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly ClinicalGateway $gateway,
        private readonly ModuleConfig $config,
    ) {}

    /**
     * Creates a link. The plaintext is returned once; it is not recoverable later.
     *
     * @return array{id: int, role: string, label: ?string, url: string, expiresAt: string}
     */
    public function create(Visit $visit, Appointment $appointment, string $role, ?string $label, int $userId): array
    {
        $issued = $this->tokens->issue($visit, $appointment, $role, $label, $userId);
        return [
            'id' => $issued->token->id,
            'role' => $issued->token->role,
            'label' => $issued->token->label,
            'url' => $this->tokens->joinUrl($issued->plaintext),
            'expiresAt' => $issued->token->expiresAt,
        ];
    }

    /** @return list<array{id: int, role: string, label: ?string, expiresAt: string, revoked: bool, uses: int}> */
    public function list(int $sessionId): array
    {
        $rows = [];
        foreach ($this->tokens->forSession($sessionId) as $token) {
            $rows[] = [
                'id' => $token->id,
                'role' => $token->role,
                'label' => $token->label,
                'expiresAt' => $token->expiresAt,
                'revoked' => $token->isRevoked(),
                'uses' => $token->uses,
            ];
        }
        return $rows;
    }

    public function emailEnabled(): bool
    {
        return $this->config->sendEmail();
    }

    /** Sends the patient link by email. Throws with a user-facing reason when it cannot. */
    public function emailPatient(Visit $visit, Appointment $appointment, string $url, int $userId): string
    {
        if (!$this->config->sendEmail()) {
            throw new VisitException(xl('Email invitations are turned off in the OpenVisit settings.'));
        }
        $contact = $this->gateway->patientContact($appointment->pid);
        if ($contact['email'] === '' || filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new VisitException(xl('This patient has no valid email address on file.'));
        }
        $when = $appointment->startsAt()->format('Y-m-d H:i');
        $subject = xl('Your video visit');
        $text = xl('Hello') . ' ' . $contact['firstName'] . ",\n\n"
            . xl('Your video visit is scheduled for') . ' ' . $when . ".\n"
            . xl('Open this link on a phone or computer with a camera a few minutes before:') . "\n" . $url . "\n\n"
            . xl('If you did not expect this, ignore this email.') . "\n";
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES));
        $html = str_replace(htmlspecialchars($url, ENT_QUOTES), '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . xl('Join the video visit') . '</a>', $html);

        if (!$this->gateway->sendEmail($contact['email'], trim($contact['firstName'] . ' ' . $contact['lastName']), $subject, $html, $text)) {
            throw new VisitException(xl('The email could not be sent. Check the email settings in Administration, Globals, Notifications.'));
        }
        $this->gateway->audit('openvisit-email', sprintf('patient link emailed for session %d by user %d', $visit->id, $userId), $appointment->pid);
        return $this->maskEmail($contact['email']);
    }

    public static function isAssignableRole(string $role): bool
    {
        return in_array($role, [JoinToken::ROLE_PATIENT, JoinToken::ROLE_GUEST], true);
    }

    private function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($user, 0, 1) . '***@' . $domain;
    }
}
