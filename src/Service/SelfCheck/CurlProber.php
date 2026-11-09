<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck;

use MyMailer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class CurlProber implements Prober
{
    public function get(string $url, int $timeoutSeconds = 5): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl init failed'];
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'OpenVisit self-check',
        ]);
        $body = curl_exec($handle);
        $status = Scalars::int(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
        $error = curl_error($handle);
        curl_close($handle);
        return ['status' => is_string($body) ? $status : 0, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }

    public function mailerConfigured(): bool
    {
        return MyMailer::isConfigured();
    }

    public function backgroundServiceActive(string $name): bool
    {
        $active = QueryUtils::fetchSingleValue('SELECT active FROM background_services WHERE name = ?', 'active', [$name]);
        return Scalars::int($active) === 1;
    }

    public function publicBaseUrl(): string
    {
        return rtrim(OEGlobalsBag::getInstance()->getString('site_addr_oath'), '/');
    }
}
