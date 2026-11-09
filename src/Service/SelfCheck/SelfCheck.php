<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\JitsiJwt;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RecordingFiles;

/** Tells an administrator, in plain words, what still stands between them and a first video visit. */
final class SelfCheck
{
    public const BACKGROUND_SERVICE = 'OpenVisitCleanup';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly Prober $prober,
        private readonly ?RecordingFiles $recordingFiles = null,
    ) {}

    /** @return list<CheckResult> */
    public function run(): array
    {
        $results = [$this->settings()];
        $domain = $this->config->jitsiDomain();
        if ($domain !== '' && self::isLocal($domain)) {
            $results[] = new CheckResult('jitsi', CheckResult::WARN, xl('Jitsi server'), xl('Local address; the OpenEMR server cannot check it, only browsers on this machine can.') . ' (' . $domain . ')', xl('Fine for development. A clinic needs a public domain with a trusted certificate.'));
        } elseif ($domain !== '') {
            $results[] = $this->jitsiReachable($domain);
            $results[] = $this->iframeApi($domain);
            $configJs = $this->prober->get("https://{$domain}/config.js");
            $results[] = $this->jitsiConfig($configJs);
            if ($this->config->allowRecording()) {
                $results[] = $this->jitsiRecording($configJs);
            }
        }
        $results[] = $this->jwt();
        $results[] = $this->publicUrl();
        $results[] = $this->mailer();
        $results[] = $this->background();
        if ($this->config->allowRecording()) {
            $results[] = $this->recording();
        }
        return $results;
    }

    /** @param list<CheckResult> $results */
    public static function allPassed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->status === CheckResult::FAIL) {
                return false;
            }
        }
        return true;
    }

    public static function isLocal(string $domain): bool
    {
        $host = explode(':', $domain)[0];
        return $host === 'localhost' || str_starts_with($host, '127.') || str_ends_with($host, '.local');
    }

    private function settings(): CheckResult
    {
        $missing = [];
        if ($this->config->jitsiDomain() === '') {
            $missing[] = xl('Jitsi domain');
        }
        if ($this->config->jwtAppId() === '') {
            $missing[] = xl('JWT app id');
        }
        if ($this->config->jwtAppSecret() === '') {
            $missing[] = xl('JWT app secret');
        }
        if ($missing !== []) {
            return new CheckResult('settings', CheckResult::FAIL, xl('Settings'), xl('Missing') . ': ' . implode(', ', $missing), xl('Fill them in under Administration, Globals, OpenVisit.'));
        }
        return new CheckResult('settings', CheckResult::PASS, xl('Settings'), xl('Jitsi domain, app id and secret are set.'));
    }

    private function jitsiReachable(string $domain): CheckResult
    {
        $response = $this->prober->get("https://{$domain}/");
        if ($response['status'] >= 200 && $response['status'] < 400) {
            return new CheckResult('jitsi', CheckResult::PASS, xl('Jitsi server'), xl('Reachable over HTTPS at') . ' ' . $domain);
        }
        $why = $response['error'] !== '' ? $response['error'] : xl('HTTP status') . ' ' . $response['status'];
        return new CheckResult('jitsi', CheckResult::FAIL, xl('Jitsi server'), xl('Not reachable from the OpenEMR server') . ': ' . $why, xl('Check the domain, DNS, firewall and the TLS certificate. Browsers will refuse a self-signed certificate.'));
    }

    private function iframeApi(string $domain): CheckResult
    {
        $response = $this->prober->get("https://{$domain}/external_api.js");
        if ($response['status'] === 200 && str_contains($response['body'], 'JitsiMeetExternalAPI')) {
            return new CheckResult('iframe', CheckResult::PASS, xl('Jitsi IFrame API'), xl('external_api.js is served by the Jitsi server.'));
        }
        return new CheckResult('iframe', CheckResult::FAIL, xl('Jitsi IFrame API'), xl('external_api.js was not served (status') . ' ' . $response['status'] . ').', xl('The domain must point at a Jitsi Meet web frontend, not only at the video bridge.'));
    }

    /** @param array{status: int, body: string, error: string} $response config.js of the Jitsi server */
    private function jitsiConfig(array $response): CheckResult
    {
        if ($response['status'] !== 200) {
            return new CheckResult('jitsi-config', CheckResult::WARN, xl('Jitsi configuration'), xl('config.js was not readable; cannot confirm authentication settings.'));
        }
        $body = $response['body'];
        $anonymous = preg_match('/anonymousdomain\s*:\s*[\'"]/', $body) === 1;
        $tokenAuth = str_contains($body, 'tokenAuthUrl') || str_contains($body, 'enableUserRolesBasedOnToken: true');
        if ($anonymous && !$tokenAuth) {
            return new CheckResult('jitsi-config', CheckResult::WARN, xl('Jitsi configuration'), xl('The server allows anonymous rooms. Patients could create rooms without a link.'), xl('Set ENABLE_AUTH=1, AUTH_TYPE=jwt and ENABLE_GUESTS=0 on the Jitsi server.'));
        }
        return new CheckResult('jitsi-config', CheckResult::PASS, xl('Jitsi configuration'), xl('Configuration loaded; TURN is handed out by the Jitsi server itself to each participant.'));
    }

    /** @param array{status: int, body: string, error: string} $response config.js of the Jitsi server */
    private function jitsiRecording(array $response): CheckResult
    {
        if ($response['status'] === 200 && preg_match('/recordingService\s*=\s*\{[^}]*enabled:\s*true/', $response['body']) === 1) {
            return new CheckResult('jitsi-recording', CheckResult::PASS, xl('Jitsi recording'), xl('The Jitsi server offers recording.'));
        }
        return new CheckResult('jitsi-recording', CheckResult::WARN, xl('Jitsi recording'), xl('The Jitsi server does not say it can record.'), xl('Set ENABLE_RECORDING=1 on the Jitsi server and run a Jibri next to it, as in docker/docker-compose.yml.'));
    }

    private function recording(): CheckResult
    {
        $inbox = $this->config->recordingsInbox();
        if ($this->recordingFiles === null || !$this->recordingFiles->inboxUsable()) {
            return new CheckResult('recording', CheckResult::FAIL, xl('Recording'), xl('The recordings inbox folder is missing or the web server cannot write to it') . ' (' . $inbox . ').', xl('Share the folder Jibri records into with the OpenEMR server, writable by the web server, or turn recording off.'));
        }
        if (!$this->recordingFiles->storageUsable()) {
            return new CheckResult('recording', CheckResult::FAIL, xl('Recording'), xl('Recordings cannot be stored in the site documents folder.'), xl('Give the web server write access to the documents folder of this site.'));
        }
        return new CheckResult('recording', CheckResult::PASS, xl('Recording'), xl('On. Finished recordings are picked up from') . ' ' . $inbox);
    }

    private function jwt(): CheckResult
    {
        $appId = $this->config->jwtAppId();
        $secret = $this->config->jwtAppSecret();
        if ($appId === '' || $secret === '') {
            return new CheckResult('jwt', CheckResult::FAIL, xl('JWT'), xl('App id or secret missing.'), xl('Use the same JWT_APP_ID and JWT_APP_SECRET as on the Jitsi server.'));
        }
        if (strlen($secret) < 32) {
            return new CheckResult('jwt', CheckResult::WARN, xl('JWT'), xl('The secret is short. Tokens can be signed, but a longer secret is safer.'), xl('Use at least 32 random characters, for example from openssl rand -hex 32.'));
        }
        $jwt = new JitsiJwt($appId, $secret, $this->config->jitsiDomain());
        $token = $jwt->mint('ov-selfcheck', 'Self check', true, time(), time() + 60);
        $claims = $jwt->claims($token);
        if ($claims === null || ($claims['room'] ?? null) !== 'ov-selfcheck') {
            return new CheckResult('jwt', CheckResult::FAIL, xl('JWT'), xl('A test token could not be signed and verified.'));
        }
        return new CheckResult('jwt', CheckResult::PASS, xl('JWT'), xl('Test token signed and verified with the configured secret.'));
    }

    private function publicUrl(): CheckResult
    {
        $base = $this->prober->publicBaseUrl();
        if ($base === '') {
            return new CheckResult('public-url', CheckResult::FAIL, xl('Public address'), xl('The site address is empty, so patient links cannot be built.'), xl('Set "Site Address (required for OAuth2 and FHIR)" under Administration, Globals, Connectors.'));
        }
        if (!str_starts_with($base, 'https://')) {
            return new CheckResult('public-url', CheckResult::WARN, xl('Public address'), xl('The site address is not HTTPS; browsers block cameras on insecure pages.') . ' (' . $base . ')');
        }
        if (preg_match('#^https://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)#', $base) === 1) {
            return new CheckResult('public-url', CheckResult::WARN, xl('Public address'), xl('The site address is local. Patients outside the clinic network cannot open their links.') . ' (' . $base . ')');
        }
        return new CheckResult('public-url', CheckResult::PASS, xl('Public address'), xl('Patient links will use') . ' ' . $base);
    }

    private function mailer(): CheckResult
    {
        if (!$this->config->sendEmail()) {
            return new CheckResult('mail', CheckResult::PASS, xl('Email invitations'), xl('Off. Providers copy the link; nothing to configure.'));
        }
        if ($this->prober->mailerConfigured()) {
            return new CheckResult('mail', CheckResult::PASS, xl('Email invitations'), xl('On, and OpenEMR has an email method configured.'));
        }
        return new CheckResult('mail', CheckResult::FAIL, xl('Email invitations'), xl('On, but OpenEMR has no email method configured.'), xl('Set it under Administration, Globals, Notifications, or turn email invitations off.'));
    }

    private function background(): CheckResult
    {
        if ($this->prober->backgroundServiceActive(self::BACKGROUND_SERVICE)) {
            return new CheckResult('background', CheckResult::PASS, xl('Cleanup service'), xl('Active. Abandoned visits are closed and old links purged hourly.'));
        }
        return new CheckResult('background', CheckResult::WARN, xl('Cleanup service'), xl('Not active. Visits left open stay open and old links are kept.'), xl('Disable and re-enable the module in Manage Modules, or activate OpenVisitCleanup under Administration, System, Services.'));
    }
}
