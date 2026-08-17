<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
class CRM_SumupPaymentProcessor_Page_ApplePayDomainAssociation extends CRM_Core_Page
{
    public function run(): void
    {
        self::serve();
    }

    /**
     * Intercept early in the request lifecycle if matching the Apple Pay verification URL.
     */
    public static function checkAndServeEarly(): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('#^/\.well-known/apple-developer-merchantid-domain-association(?:\?.*)?$#', $requestUri)) {
            self::serve();
        }
    }

    /**
     * Serve the Apple Developer Merchant ID Domain Association text content.
     */
    public static function serve(): void
    {
        $content = self::getContent();
        if ($content === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Apple Pay domain association file not configured.\n";
            echo "Please configure it in CiviCRM System Settings > SumUp Settings.\n";
            CRM_Utils_System::civiExit();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . (string) strlen($content));
        header('Cache-Control: public, max-age=86400');
        echo $content;
        CRM_Utils_System::civiExit();
    }

    /**
     * Retrieve the association text from setting or local filesystem candidates.
     */
    public static function getContent(): string
    {
        // 1. Check CiviCRM setting
        try {
            $setting = (string) Civi::settings()->get('sumup_apple_pay_domain_association');
            if (trim($setting) !== '') {
                return trim($setting);
            }
        } catch (\Throwable) {
            // Setting might not be initialized yet
        }

        // 2. Check webroot /.well-known directory
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docRoot !== '') {
            $webrootCandidate = rtrim($docRoot, '/') . '/.well-known/apple-developer-merchantid-domain-association';
            if (is_file($webrootCandidate) && is_readable($webrootCandidate)) {
                $fileContent = (string) file_get_contents($webrootCandidate);
                if (trim($fileContent) !== '') {
                    return trim($fileContent);
                }
            }
        }

        // 3. Check extension directory
        $extCandidate = __DIR__ . '/../../../apple-developer-merchantid-domain-association';
        if (is_file($extCandidate) && is_readable($extCandidate)) {
            $fileContent = (string) file_get_contents($extCandidate);
            if (trim($fileContent) !== '') {
                return trim($fileContent);
            }
        }

        return '';
    }
}
