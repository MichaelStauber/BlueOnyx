<?php 
namespace Email\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
include_once("ServerScriptHelper.php");
include_once("Product.php");
use I18n;
use BxPage;
use ServerScriptHelper;
use Product;

class EmailAutoconfig extends BaseController {
    /**
     * Thunderbird Autoconfig:
     *  - /mail/config-v1.1.xml
     *  - /.well-known/autoconfig/mail/config-v1.1.xml
     */
    public function thunderbird() {
        $host = $this->getHost();
        $email = $this->getEmailFromRequest();

        // Determine the email domain we are configuring for
        $domain = $this->domainFromHostOrEmail($host, $email);

        $CI =& get_instance();

        $BX_SESSION['loginName'] = 'api-admin';
        $password = $this->getApiAdminPassword();
        $BX_SESSION['sessionId'] = $CI->cceClient->auth($BX_SESSION['loginName'], $password);

        if (!empty($BX_SESSION['sessionId'])) {

            $serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName']);
            $CI->setSSH($serverScriptHelper);
            $System = $CI->getSystem();
            $CI->setCCE($serverScriptHelper->getCceClient());
            $cceClient = $CI->getCCE();
            $serverScriptHelper = $CI->getSSH();
            $locale = 'en_US';
            $BX_SESSION = $CI->getBX_SESSION();
        }
        else {
            //
            //-- Login doesn't work. We need to ask for username and password:
            //

            $CI->cceClient->endkey();
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();

            header("Location: /gui/Forbidden403");
            exit;
        }

        $vsites = $CI->cceClient->getAll("Vsite", array());
        $users  = $CI->cceClient->getAll("User", array());

        $loginUser = $this->resolveLoginUsername($email, $users, $vsites);

        // Policy gate: only serve if we have a loginUser:
        if (!$loginUser) {
            //return $this->xmlError(404, 'Unknown mailbox user');
            // When no emailaddress is provided, Thunderbird should prompt for the account username.
            // Use %USERNAME% (NOT %EMAILADDRESS%).
            $loginUser = '%USERNAME%';
        }

        // Policy gate: only serve if allowed for that domain/vsite:
        if (!$this->isAutoconfigAllowed($domain)) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            return $this->xmlError(404, 'Autoconfig not available for this domain');
        }

        $fqdn_obj = $this->resolveVsiteForEmailDomain($domain, $vsites);

        if (!isset($fqdn_obj['OBJECT']['fqdn'])) {
            $fqdn = gethostname();
        }
        else {
            $fqdn = $fqdn_obj['OBJECT']['fqdn'];
        }

        $flags = $this->getSystemEmailServiceFlags($CI->cceClient);
        $services = $this->computeAdvertisedServices($flags);

        $cfg = $this->buildMailConfig($domain, $email, $loginUser, $fqdn, $services);
        $xml = $this->renderThunderbirdXml($cfg, $services);

        $CI->cceClient->endkey();
        $CI->cceClient->bye();
        $CI->serverScriptHelper->destructor();

        return $this->response
            ->setStatusCode(200)
            ->setContentType('application/xml', 'utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($xml);
    }

    /**
     * Outlook Autodiscover:
     *  - /autodiscover/autodiscover.xml
     * Supports GET (debug) and POST (real clients).
     */
    public function autodiscover() {
        $host = $this->getHost();

        // Outlook usually POSTs an XML body. We'll accept email from:
        // - POST XML <EMailAddress>
        // - query string for testing: ?emailaddress=
        $email = $this->getEmailFromRequest();
        if (!$email) {
            $rawBody = $this->request->getBody();
            $email = $this->extractEmailFromAutodiscoverXml($rawBody ?? '');
        }

        $domain = $this->domainFromHostOrEmail($host, $email);

        if (!$this->isAutodiscoverAllowed($domain)) {
            return $this->xmlError(404, 'Autodiscover not available for this domain');
        }

        $CI =& get_instance();

        $BX_SESSION['loginName'] = 'api-admin';
        $password = $this->getApiAdminPassword();
        $BX_SESSION['sessionId'] = $CI->cceClient->auth($BX_SESSION['loginName'], $password);

        if (!empty($BX_SESSION['sessionId'])) {

            $serverScriptHelper = new ServerScriptHelper($BX_SESSION['sessionId'], $BX_SESSION['loginName']);
            $CI->setSSH($serverScriptHelper);
            $System = $CI->getSystem();
            $CI->setCCE($serverScriptHelper->getCceClient());
            $cceClient = $CI->getCCE();
            $serverScriptHelper = $CI->getSSH();
            $locale = 'en_US';
            $BX_SESSION = $CI->getBX_SESSION();
        }
        else {
            //
            //-- Login doesn't work. We need to ask for username and password:
            //

            $CI->cceClient->endkey();
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();

            header("Location: /gui/Forbidden403");
            exit;
        }

        $vsites = $CI->cceClient->getAll("Vsite", array());
        $users = $CI->cceClient->getAll("User", array());

        $loginUser = $this->resolveLoginUsername($email, $users, $vsites);

        // Always extract the email domain from the request if possible:
        $emailDomain = '';
        if ($email && str_contains($email, '@')) {
            [, $emailDomain] = explode('@', strtolower($email), 2);
            $emailDomain = trim($emailDomain);
        }

        $flags = $this->getSystemEmailServiceFlags($CI->cceClient);
        $services = $this->computeAdvertisedServices($flags);

        // If we cannot resolve user -> still serve something, but DO NOT use server FQDN for LoginName
        if (empty($loginUser)) {
            $loginUser = ''; // no username known
            $mailHost  = $emailDomain !== '' ? "mail.$emailDomain" : $host;  // best guess

            $cfg = $this->buildMailConfig($domain, $email, '', $mailHost, $services);

            // No override; and we pass %EMAILADDRESS% as the "email" placeholder if missing:
            $xml = $this->renderAutodiscoverXml(
                $cfg,
                $email ?: '%EMAILADDRESS%',
                '',          // no resolved login user
                $services,
                null
            );

            $CI->cceClient->endkey();
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();

            return $this->response
                ->setStatusCode(200)
                ->setContentType('text/xml', 'utf-8')
                ->setHeader('Cache-Control', 'no-store')
                ->setBody($xml);
        }

        // We *do* have a loginUser: use that to find the authoritative vsite (no ambiguity)
        $userObj = $this->findUserObjectByName($loginUser, $users);
        $userSite = $userObj['OBJECT']['site'] ?? '';
        $vsite = $this->findVsiteByName($userSite, $vsites);

        // Choose a stable mail hostname
        if ($vsite) {
            $mailHost = $this->pickMailHostnameForVsite($vsite, $emailDomain ?: $domain);
        }
        else {
            // fallback: still prefer mail.$domain over server hostname
            $mailHost = ($emailDomain !== '' ? "mail.$emailDomain" : "mail.$domain");
        }

        $cfg = $this->buildMailConfig($domain, $email, $loginUser, $mailHost, $services);

        $loginName = $this->pickAutodiscoverLoginName(
            $email,
            $loginUser,
            ($emailDomain ?: $domain),
            $users,
            $vsites
        );

        // Get XML if we use Postfix:
        if ($System['MTA'] === 'POSTFIX') {
            $xml = $this->renderAutodiscoverXml(
                $cfg,
                $email,
                $loginUser,
                $services,
                $loginName
            );
        }
        else {
            // Old method: Force de-alias of email address. IMPORTANT: pass $emailDomain so LoginName becomes user@vsite.tld (not @host.tld)
            $xml = $this->renderAutodiscoverXml($cfg, $emailDomain ?: $domain, $loginUser, $services);
        }

        $CI->cceClient->endkey();
        $CI->cceClient->bye();
        $CI->serverScriptHelper->destructor();

        return $this->response
            ->setStatusCode(200)
            ->setContentType('text/xml', 'utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($xml);
    }

    /**
     * Outlook (Classic) Autodiscover:
     *  - /autodiscover/autodiscover.json/v1.0/user@company.com?Protocol=ActiveSync&RedirectCount=1
     * Supports GET (debug) and POST (real clients).
     *  Won't help us as it requires ActiveSync or Z-Push, which we don't have.
     */
    public function autodiscoverJson(string $emailPath = '') {
        $email = strtolower(trim($emailPath));

        // If someone hits it without path email, optionally fall back:
        if ($email === '') {
            $email = strtolower(trim((string)$this->request->getGet('Email')));
        }

        $uri = $this->request->getUri();
        $seg6 = (string) $uri->getSegment(7); // email is segment 6 here
        $email = strtolower(trim($seg6));

        if ($email === '' || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {

            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();

            return $this->response->setStatusCode(400)
                ->setContentType('application/json', 'utf-8')
                ->setBody(json_encode(['Error' => 'Invalid email']));
        }

        $host   = $this->getHost();
        $domain = $this->domainFromHostOrEmail($host, $email);

        $protocol = (string) $this->request->getGet('Protocol');
        $protocol = $protocol !== '' ? $protocol : 'ActiveSync';

        // What URL to hand out?
        // For ActiveSync, clients expect /Microsoft-Server-ActiveSync (Exchange-style).
        // If you *don't* actually provide ActiveSync (Z-Push), you can still return something,
        // but Outlook may then try it and fail.
        $url = "https://mail.$domain/Microsoft-Server-ActiveSync";

        $payload = [
            'Protocol' => $protocol,
            'Url'      => $url,
        ];

        $CI->cceClient->bye();
        $CI->serverScriptHelper->destructor();

        return $this->response->setStatusCode(200)
            ->setHeader('Cache-Control', 'no-store')
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    // ---------------------------------------------------------------------
    // Helpers: parsing
    // ---------------------------------------------------------------------

    private function getHost(): string {
        // Behind proxy, preserve host; use CI request header
        $host = (string) $this->request->getHeaderLine('Host');
        $host = strtolower(trim($host));
        // Strip :port if present
        $host = preg_replace('/:\d+$/', '', $host);
        return $host ?: 'localhost';
    }

    private function getEmailFromRequest(): ?string {
        $email = $this->request->getGet('emailaddress')
              ?: $this->request->getGet('email')
              ?: $this->request->getPost('emailaddress')
              ?: $this->request->getPost('email');

        $email = is_string($email) ? trim($email) : '';
        if ($email === '') {
            return null;
        }

        // Basic sanity only (do not hard fail if odd):
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return null;
        }

        return strtolower($email);
    }

    private function domainFromHostOrEmail(string $host, ?string $email): string {
        // If host is autoconfig.X or autodiscover.X, strip the prefix
        if (preg_match('/^(autoconfig|autodiscover)\.(.+)$/i', $host, $m)) {
            return strtolower($m[2]);
        }

        if (str_starts_with($host, 'mail.')) {
            return substr($host, 5);
        }

        // If host is a normal vsite hostname (mail.company.com), use that hostname
        // (You may later map mail.company.com => company.com if you want.)
        if ($email) {
            $parts = explode('@', $email, 2);
            if (count($parts) === 2 && $parts[1] !== '') {
                return strtolower($parts[1]);
            }
        }

        return $host;
    }

    private function extractEmailFromAutodiscoverXml(?string $body): ?string {
        $body = trim((string) ($body ?? ''));
        if ($body === '') {
            return null;
        }

        // Do a safe-ish extraction without full XML parser dependency.
        // Outlook posts: <EMailAddress>user@domain</EMailAddress>
        if (preg_match('~<\s*EMailAddress\s*>([^<]+)<\s*/\s*EMailAddress\s*>~i', $body, $m)) {
            $email = strtolower(trim($m[1]));
            if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
                return $email;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Policy gates (wire these to CCE)
    // ---------------------------------------------------------------------

    private function isAutoconfigAllowed(string $domain): bool {
        // Suggested policy:
        // - vsite exists for $domain OR $domain belongs to a vsite mail domain
        // - vsite SSL enabled
        // - mail enabled
        // - (optional) feature flag: email_autoconfig enabled

        // TODO: replace with real CCE lookups.
        return $this->isSslEnabledForDomain($domain);
    }

    private function isAutodiscoverAllowed(string $domain): bool {
        // same policy as above; keep separate in case you want different toggles
        return $this->isSslEnabledForDomain($domain);
    }

    private function isSslEnabledForDomain(string $domain): bool {
        // TODO: implement:
        // - find vsite for domain (fqdn or alias)
        // - check vsite.SSL.enabled == 1 and cert exists
        // For now: allow everything for development.
        return true;
    }

    // ---------------------------------------------------------------------
    // Build config (wire to CCE later)
    // ---------------------------------------------------------------------

    private function buildMailConfig(string $domain, ?string $email, ?string $loginUser = null, ?string $fqdn = null, array $services = []): array {
        $host = $fqdn ?: gethostname();
        $imapHost = $host;
        $pop3Host = $host;
        $smtpHost = $host;

        // Username rules:
        $localPart = '';
        if (is_string($loginUser) && trim($loginUser) !== '' && !preg_match('/^%[A-Z0-9_]+%$/', trim($loginUser))) {
            $localPart = strtolower(trim($loginUser));
        }
        elseif (is_string($loginUser) && trim($loginUser) !== '' && preg_match('/^%[A-Z0-9_]+%$/', trim($loginUser))) {
            // Preserve macros like %USERNAME% / %EMAILADDRESS% as-is
            $localPart = trim($loginUser);
        }

        // Thunderbird "username" field should be the actual login username (no domain) in your setup.
        // If unknown, keep your macro-ish fallback.
        $tbUsername = $localPart !== '' ? $localPart : '%EMAILADDRESS%';

        // Outlook "loginName": you currently render user@domain (constructed in renderAutodiscoverXml),
        // so cfg['loginName'] is only a fallback.
        $outlookLoginName = $localPart !== '' ? $localPart : '%EMAILADDRESS%';

        // SMTP policy: prefer Submission 587 STARTTLS, else SMTPS 465 SSL, else 587 STARTTLS.
        $useSubmission = !empty($services['submission']);
        $useSmtps      = !$useSubmission && !empty($services['smtps']);

        $smtpPort   = $useSubmission ? 587 : ($useSmtps ? 465 : 587);
        $smtpSocket = $useSubmission ? 'STARTTLS' : ($useSmtps ? 'SSL' : 'STARTTLS');

        return [
            'providerId'  => $domain,
            'domain'      => $domain,
            'fqdn'        => $host,
            'displayName' => $domain . " Mail",

            // Thunderbird:
            'username'    => $tbUsername,

            // Outlook (local-part; domain will be appended in renderAutodiscoverXml):
            'loginName'   => $outlookLoginName,

            // Always TLS-only values (actual inclusion is gated by $services in renderers)
            'imap' => [
                'hostname' => $imapHost,
                'port'     => 993,
                'socket'   => 'SSL',
                'auth'     => 'password-cleartext',
            ],
            'pop3' => [
                'hostname' => $pop3Host,
                'port'     => 995,
                'socket'   => 'SSL',
                'auth'     => 'password-cleartext',
            ],
            'smtp' => [
                'hostname' => $smtpHost,
                'port'     => $smtpPort,
                'socket'   => $smtpSocket, // STARTTLS or SSL
                'auth'     => 'password-cleartext',
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // XML rendering
    // ---------------------------------------------------------------------

    private function renderThunderbirdXml(array $cfg, array $services = []): string {
        // Keep it simple and deterministic (no extra whitespace surprises).
        $providerId = htmlspecialchars((string)$cfg['providerId'], ENT_XML1);
        $domain     = htmlspecialchars((string)$cfg['domain'], ENT_XML1);
        $display    = htmlspecialchars((string)$cfg['displayName'], ENT_XML1);
        $username   = htmlspecialchars((string)$cfg['username'], ENT_XML1);

        // TLS-only endpoints (gated below)
        $imapHost = htmlspecialchars((string)$cfg['imap']['hostname'], ENT_XML1);
        $imapPort = (int)$cfg['imap']['port'];
        $imapSock = htmlspecialchars((string)$cfg['imap']['socket'], ENT_XML1);
        $imapAuth = htmlspecialchars((string)$cfg['imap']['auth'], ENT_XML1);

        $pop3Host = htmlspecialchars((string)$cfg['pop3']['hostname'], ENT_XML1);
        $pop3Port = (int)$cfg['pop3']['port'];
        $pop3Sock = htmlspecialchars((string)$cfg['pop3']['socket'], ENT_XML1);
        $pop3Auth = htmlspecialchars((string)$cfg['pop3']['auth'], ENT_XML1);

        $smtpHost = htmlspecialchars((string)$cfg['smtp']['hostname'], ENT_XML1);
        $smtpPort = (int)$cfg['smtp']['port'];
        $smtpSock = htmlspecialchars((string)$cfg['smtp']['socket'], ENT_XML1);
        $smtpAuth = htmlspecialchars((string)$cfg['smtp']['auth'], ENT_XML1);

        $incomingBlocks = '';

        if (!empty($services['imap'])) {
            $incomingBlocks .= <<<XML
    <incomingServer type="imap">
      <hostname>{$imapHost}</hostname>
      <port>{$imapPort}</port>
      <socketType>{$imapSock}</socketType>
      <authentication>{$imapAuth}</authentication>
      <username>{$username}</username>
    </incomingServer>

XML;
        }

        if (!empty($services['pop3'])) {
            $incomingBlocks .= <<<XML
    <incomingServer type="pop3">
      <hostname>{$pop3Host}</hostname>
      <port>{$pop3Port}</port>
      <socketType>{$pop3Sock}</socketType>
      <authentication>{$pop3Auth}</authentication>
      <username>{$username}</username>
    </incomingServer>

XML;
        }

        $outgoingBlock = '';
        if (!empty($services['submission']) || !empty($services['smtps'])) {
            $outgoingBlock = <<<XML
    <outgoingServer type="smtp">
      <hostname>{$smtpHost}</hostname>
      <port>{$smtpPort}</port>
      <socketType>{$smtpSock}</socketType>
      <authentication>{$smtpAuth}</authentication>
      <username>{$username}</username>
    </outgoingServer>

XML;
        }

        return <<<XML
<?xml version="1.0"?>
<clientConfig version="1.1">
  <emailProvider id="{$providerId}">
    <domain>{$domain}</domain>
    <displayName>{$display}</displayName>
{$incomingBlocks}{$outgoingBlock}  </emailProvider>
</clientConfig>

XML;
    }

    private function renderAutodiscoverXml(array $cfg, ?string $email, ?string $loginUser, array $services = [], ?string $loginNameOverride = null): string {

        $emailRaw     = trim((string)$email);
        $loginUserRaw = trim((string)$loginUser);
        $overrideRaw  = trim((string)$loginNameOverride);

        // Only lowercase if it looks like an actual email address.
        // This preserves %EMAILADDRESS% etc.
        $email     = (str_contains($emailRaw, '@') ? strtolower($emailRaw) : $emailRaw);
        $loginUser = $loginUserRaw !== '' ? strtolower($loginUserRaw) : '';

        // If override is provided, use it verbatim (caller is responsible for validation)
        $loginNameOverride = (str_contains($overrideRaw, '@') ? strtolower($overrideRaw) : $overrideRaw);

        if ($loginNameOverride !== '') {
            $login = htmlspecialchars($loginNameOverride, ENT_XML1);
        }
        else {
            // Determine domain part for LoginName:
            // Prefer the domain from the email address, else fall back to cfg['domain'].
            $emailDomain = '';
            if ($email !== '' && str_contains($email, '@')) {
                $parts = explode('@', $email, 2);
                if (count($parts) === 2) {
                    $emailDomain = trim($parts[1]);
                }
            }
            if ($emailDomain === '') {
                $emailDomain = strtolower(trim((string)($cfg['domain'] ?? '')));
            }
            if ($emailDomain === '') {
                $emailDomain = 'localhost';
            }

            // Determine local part for LoginName:
            // Prefer resolved loginUser; else fall back to local-part of email; else macro.
            $localPart = '';
            if ($loginUser !== '' && !preg_match('/^%[A-Z0-9_]+%$/', $loginUser)) {
                $localPart = $loginUser;
            }
            elseif ($email !== '' && str_contains($email, '@')) {
                $parts = explode('@', $email, 2);
                $localPart = trim($parts[0]);
            }
            if ($localPart === '') {
                $localPart = (string)($cfg['loginName'] ?? '%USERNAME%');
            }

            // Final login:
            // If localPart is a macro placeholder, return it verbatim without forcing a domain suffix.
            if (preg_match('/^%[A-Z0-9_]+%$/', $localPart)) {
                $login = htmlspecialchars($localPart, ENT_XML1);
            }
            else {
                $login = htmlspecialchars($localPart . '@' . $emailDomain, ENT_XML1);
            }
        }

        $imapHost = htmlspecialchars((string)$cfg['imap']['hostname'], ENT_XML1);
        $imapPort = (int)$cfg['imap']['port'];

        $pop3Host = htmlspecialchars((string)$cfg['pop3']['hostname'], ENT_XML1);
        $pop3Port = (int)$cfg['pop3']['port'];

        $smtpHost = htmlspecialchars((string)$cfg['smtp']['hostname'], ENT_XML1);
        $smtpPort = (int)$cfg['smtp']['port'];
        $smtpSock = strtoupper(trim((string)($cfg['smtp']['socket'] ?? 'STARTTLS')));

        $imapProto = '';
        if (!empty($services['imap'])) {
            $imapProto = <<<XML
      <Protocol>
        <Type>IMAP</Type>
        <Server>{$imapHost}</Server>
        <Port>{$imapPort}</Port>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
        <Encryption>SSL</Encryption>
        <LoginName>{$login}</LoginName>
      </Protocol>

XML;
        }

        $popProto = '';
        if (!empty($services['pop3'])) {
            $popProto = <<<XML
      <Protocol>
        <Type>POP3</Type>
        <Server>{$pop3Host}</Server>
        <Port>{$pop3Port}</Port>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
        <Encryption>SSL</Encryption>
        <LoginName>{$login}</LoginName>
      </Protocol>

XML;
        }

        $smtpProto = '';
        if (!empty($services['submission']) || !empty($services['smtps'])) {
            // Submission STARTTLS on 587
            if ($smtpSock === 'STARTTLS' || !empty($services['submission'])) {
                $smtpProto = <<<XML
      <Protocol>
        <Type>SMTP</Type>
        <Server>{$smtpHost}</Server>
        <Port>{$smtpPort}</Port>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>off</SSL>
        <TLS>on</TLS>
        <AuthRequired>on</AuthRequired>
        <Encryption>TLS</Encryption>
        <LoginName>{$login}</LoginName>
      </Protocol>

XML;
            }
            // SMTPS SSL on 465
            else {
                $smtpProto = <<<XML
      <Protocol>
        <Type>SMTP</Type>
        <Server>{$smtpHost}</Server>
        <Port>{$smtpPort}</Port>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
        <Encryption>SSL</Encryption>
        <LoginName>{$login}</LoginName>
      </Protocol>

XML;
            }
        }

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>

{$imapProto}{$popProto}{$smtpProto}    </Account>
  </Response>
</Autodiscover>

XML;
    }

    private function scalarToArray($scalar): array {
        // BlueOnyx scalar arrays often look like: &a&b&c&
        if (!is_string($scalar) || $scalar === '') {
            return [];
        }
        $scalar = trim($scalar);
        $scalar = trim($scalar, '&');
        if ($scalar === '') {
            return [];
        }
        $parts = array_filter(array_map('trim', explode('&', $scalar)));
        return array_values($parts);
    }

    private function resolveLoginUsername(?string $email, array $users, array $vsites): ?string {
        if (!$email || !str_contains($email, '@')) {
            return null;
        }

        [$local, $emailDomain] = explode('@', strtolower($email), 2);
        $local = trim($local);
        $emailDomain = trim($emailDomain);

        if ($local === '' || $emailDomain === '') {
            return null;
        }

        // Step 1: resolve which Vsite owns this email domain
        $vsite = $this->resolveVsiteForEmailDomain($emailDomain, $vsites);

        if (!$vsite) {
            return null; // unknown domain => refuse
        }

        $vObj = $vsite['OBJECT'] ?? [];
        $vsiteName = $vObj['name'] ?? '';  // e.g. "site9"
        if ($vsiteName === '') {
            return null;
        }

        // Gather all valid domains for this vsite (so alias entries "foo@aliasdomain" can match)
        $validDomains = [];
        foreach (['domain', 'fqdn'] as $k) {
            $val = strtolower(trim($vObj[$k] ?? ''));
            if ($val !== '') {
                $validDomains[$val] = true;
            }
        }
        foreach ($this->scalarToArray($vObj['mailAliases'] ?? '') as $d) {
            $d = strtolower(trim($d));
            if ($d !== '') {
                $validDomains[$d] = true;
            }
        }

        // Step 2: search ONLY users belonging to that Vsite
        $candidateUsers = [];
        foreach ($users as $u) {
            $uObj = $u['OBJECT'] ?? [];
            if (($uObj['site'] ?? '') === $vsiteName) {
                $candidateUsers[] = $u;
            }
        }

        // 2a) direct username hit (local-part equals actual login username)
        foreach ($candidateUsers as $u) {
            $uname = $u['OBJECT']['name'] ?? '';
            if ($uname !== '' && strtolower($uname) === $local) {
                return $uname;
            }
        }

        // 2b) alias hit inside that vsite only
        foreach ($candidateUsers as $u) {
            $uname = $u['OBJECT']['name'] ?? '';
            if ($uname === '') {
                continue;
            }

            $aliases = $this->scalarToArray($u['Email']['aliases'] ?? '');
            foreach ($aliases as $a) {
                $a = strtolower(trim($a));
                if ($a === '') {
                    continue;
                }

                // alias stored as "foo" (no domain)
                if ($a === $local) {
                    return $uname;
                }

                // alias stored as "foo@domain"
                if (str_contains($a, '@')) {
                    [$al, $ad] = explode('@', $a, 2);
                    $al = trim($al);
                    $ad = trim($ad);

                    if ($al === $local && $ad !== '' && isset($validDomains[$ad])) {
                        return $uname;
                    }
                }

                // full email string exact match (safe)
                if ($a === $email) {
                    return $uname;
                }
            }
        }

        return null;
    }

    private function resolveVsiteForEmailDomain(string $emailDomain, array $vsites): ?array {
        $emailDomain = strtolower(trim($emailDomain));
        if ($emailDomain === '') {
            return null;
        }

        $domainHits = [];
        $fqdnHits   = [];
        $aliasHits  = [];

        foreach ($vsites as $v) {
            $obj = $v['OBJECT'] ?? [];

            $domain = strtolower(trim($obj['domain'] ?? ''));
            $fqdn   = strtolower(trim($obj['fqdn'] ?? ''));

            if ($domain !== '' && $domain === $emailDomain) {
                $domainHits[] = $v;
                continue;
            }

            if ($fqdn !== '' && $fqdn === $emailDomain) {
                $fqdnHits[] = $v;
                continue;
            }

            $mailAliases = $this->scalarToArray($obj['mailAliases'] ?? '');
            foreach ($mailAliases as $a) {
                if (strtolower(trim($a)) === $emailDomain) {
                    $aliasHits[] = $v;
                    break;
                }
            }
        }

        // Prefer domain match, then fqdn match, then alias match:
        $candidates = $domainHits ?: ($fqdnHits ?: $aliasHits);
        if (!$candidates) {
            return null;
        }

        // If multiple candidates, prefer something that looks like a mail endpoint:
        // - fqdn starts with mail./imap./smtp.
        // - or mailAliases contains mail.$emailDomain
        $preferPrefixes = ['mail.', 'imap.', 'smtp.'];
        $wantMailAlias  = "mail.$emailDomain";

        foreach ($candidates as $v) {
            $obj = $v['OBJECT'] ?? [];
            $fqdn = strtolower(trim($obj['fqdn'] ?? ''));

            foreach ($preferPrefixes as $p) {
                if ($fqdn !== '' && str_starts_with($fqdn, $p)) {
                    return $v;
                }
            }

            $mailAliases = $this->scalarToArray($obj['mailAliases'] ?? '');
            foreach ($mailAliases as $a) {
                if (strtolower(trim($a)) === $wantMailAlias) {
                    return $v;
                }
            }
        }

        // Deterministic fallback:
        return $candidates[0];
    }

    private function getApiAdminPassword(): string {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $keyfile = '/etc/cced-api/master.key';
        $pwfile  = '/etc/cced-api/api-admin.passwd';

        $key = trim(@file_get_contents($keyfile));
        if ($key === '') {
            throw new \RuntimeException("Missing/empty master key");
        }
        if (!is_readable($pwfile)) {
            throw new \RuntimeException("Password file not readable");
        }

        // Avoid key injection into shell:
        $keyArg = escapeshellarg("pass:$key");
        $pwArg  = escapeshellarg($pwfile);

        $cmd = "openssl enc -d -aes-256-cbc -pbkdf2 -salt -pass $keyArg -in $pwArg 2>/dev/null";
        $out = shell_exec($cmd);

        $pw = is_string($out) ? trim($out) : '';
        if ($pw === '') {
            throw new \RuntimeException("Failed to decrypt api-admin password");
        }

        return $cached = $pw;
    }

    private function findUserObjectByName(string $username, array $users): ?array {
        $username = strtolower(trim($username));
        foreach ($users as $u) {
            $uObj = $u['OBJECT'] ?? [];
            $name = strtolower(trim($uObj['name'] ?? ''));
            if ($name !== '' && $name === $username) {
                return $u; // includes OBJECT and namespaces like Email
            }
        }
        return null;
    }

    private function findVsiteByName(string $siteName, array $vsites): ?array {
        $siteName = trim($siteName);
        if ($siteName === '') {
            return null;
        }
        foreach ($vsites as $v) {
            $vObj = $v['OBJECT'] ?? [];
            if (($vObj['name'] ?? '') === $siteName) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Pick a stable mail hostname for a vsite + emailDomain.
     * Prefer: "mail.$emailDomain" if present in mailAliases, else any alias that starts with "mail.",
     * else fall back to emailDomain (or vsite fqdn if you really must).
     */
    private function pickMailHostnameForVsite(array $vsite, string $emailDomain): string {
        $emailDomain = strtolower(trim($emailDomain));
        $vObj = $vsite['OBJECT'] ?? [];

        $mailAliases = $this->scalarToArray($vObj['mailAliases'] ?? '');

        // 1) exact "mail.$domain"
        $want = "mail.$emailDomain";
        foreach ($mailAliases as $a) {
            if (strtolower($a) === $want) {
                return $want;
            }
        }

        // 2) any mail.* alias
        foreach ($mailAliases as $a) {
            $a = strtolower(trim($a));
            if (str_starts_with($a, 'mail.')) {
                return $a;
            }
        }

        // 3) if the vsite fqdn itself is mail.*
        $fqdn = strtolower(trim($vObj['fqdn'] ?? ''));
        if ($fqdn !== '' && str_starts_with($fqdn, 'mail.')) {
            return $fqdn;
        }

        // 4) fallback: email domain (more portable than server hostname)
        return $emailDomain !== '' ? $emailDomain : ($fqdn ?: gethostname());
    }

    private function getSystemEmailServiceFlags($cceClient): array {
        // Find System OID (usually 1, but don’t assume)
        //$oids = $cceClient->find('System', []);
        //$oid  = is_array($oids) && isset($oids[0]) ? (int)$oids[0] : 1;

        $CI =& get_instance();
        $System = $CI->getSystem();
        $email = $CI->cceClient->get($System['OID'], "Email");

        if (!is_array($email)) {
            // Fail safe: advertise nothing except SMTP (optional), but safest is nothing
            return [
                'enableImaps' => false,
                'enablePops'  => false,
                'enableSubmissionPort' => false,
                'enableSMTPS' => false,
                'enableSMTPAuth' => false,
            ];
        }

        $bool = static function ($v): bool {
            return (string)$v === '1';
        };

        return [
            'enableImaps'         => $bool($email['enableImaps'] ?? '0'),
            'enablePops'          => $bool($email['enablePops'] ?? '0'),
            'enableSubmissionPort'=> $bool($email['enableSubmissionPort'] ?? '0'),
            'enableSMTPS'         => $bool($email['enableSMTPS'] ?? '0'),
            'enableSMTPAuth'      => $bool($email['enableSMTPAuth'] ?? '0'),
        ];
    }

    /**
     * Decide which endpoints to include in XML (TLS-only policy).
     */
    private function computeAdvertisedServices(array $flags): array {
        // TLS-only policy:
        $advertiseImap = !empty($flags['enableImaps']); // IMAPS only
        $advertisePop  = !empty($flags['enablePops']);  // POP3S only

        // Outgoing mail:
        // - Prefer submission (587 STARTTLS) if enabled and auth enabled
        // - Else SMTPS (465) if enabled and auth enabled
        $smtpAuth = !empty($flags['enableSMTPAuth']);

        $advertiseSubmission = $smtpAuth && !empty($flags['enableSubmissionPort']);
        $advertiseSmtps      = $smtpAuth && !empty($flags['enableSMTPS']);

        return [
            'imap' => $advertiseImap,
            'pop3' => $advertisePop,
            'submission' => $advertiseSubmission,
            'smtps' => $advertiseSmtps,
        ];
    }

    /**
     * Decide which LoginName to return in Autodiscover:
     * - If the requested email is a valid alias for the resolved mailbox user (within the owning vsite),
     *   return the requested email (aliased address).
     * - Else return "loginUser@domain" (legacy / safe fallback).
     */
    private function pickAutodiscoverLoginName(?string $requestedEmail, string $loginUser, string $emailDomain, array $users, array $vsites): string {
        $requestedEmail = strtolower(trim((string)$requestedEmail));
        $loginUser      = strtolower(trim((string)$loginUser));
        $emailDomain    = strtolower(trim((string)$emailDomain));

        // Fallback if we don't have enough info
        if ($loginUser === '') {
            return $requestedEmail !== '' ? $requestedEmail : '%EMAILADDRESS%';
        }
        if (($emailDomain === '') && ($requestedEmail != '%EMAILADDRESS%')) {
            // last resort, but keep deterministic
            $emailDomain = strtolower(trim((string) gethostname()));
        }

        // If requested email isn't parseable, just return safe fallback
        if ($requestedEmail === '' || !str_contains($requestedEmail, '@')) {
            return $loginUser . '@' . $emailDomain;
        }

        [$reqLocal, $reqDom] = explode('@', $requestedEmail, 2);
        $reqLocal = trim($reqLocal);
        $reqDom   = trim($reqDom);

        if ($reqLocal === '' || $reqDom === '') {
            return $loginUser . '@' . $emailDomain;
        }

        // Find user object
        $userObj = $this->findUserObjectByName($loginUser, $users);
        if (!$userObj) {
            return $loginUser . '@' . $emailDomain;
        }

        // Find owning vsite for this email domain (the request domain)
        $vsite = $this->resolveVsiteForEmailDomain($reqDom, $vsites);
        if (!$vsite) {
            // if we can't even map the domain, don't trust aliases
            return $loginUser . '@' . $emailDomain;
        }

        // Ensure the resolved login user actually belongs to that vsite
        $uSite = (string)($userObj['OBJECT']['site'] ?? '');
        $vName = (string)($vsite['OBJECT']['name'] ?? '');
        if ($uSite === '' || $vName === '' || $uSite !== $vName) {
            return $loginUser . '@' . $emailDomain;
        }

        // Build a set of valid domains for that vsite (domain, fqdn, mailAliases)
        $validDomains = [];
        foreach (['domain', 'fqdn'] as $k) {
            $val = strtolower(trim((string)($vsite['OBJECT'][$k] ?? '')));
            if ($val !== '') {
                $validDomains[$val] = true;
            }
        }
        foreach ($this->scalarToArray($vsite['OBJECT']['mailAliases'] ?? '') as $d) {
            $d = strtolower(trim($d));
            if ($d !== '') {
                $validDomains[$d] = true;
            }
        }

        // Requested domain must be one of that vsite's mail domains
        if (!isset($validDomains[$reqDom])) {
            return $loginUser . '@' . $emailDomain;
        }

        // If requested local-part equals the real login username, it's always acceptable to return it
        if ($reqLocal === $loginUser) {
            return $requestedEmail;
        }

        // Check whether requested local-part is one of the user's aliases
        $aliases = $this->scalarToArray($userObj['Email']['aliases'] ?? '');
        foreach ($aliases as $a) {
            $a = strtolower(trim($a));
            if ($a === '') {
                continue;
            }

            // alias stored as "foo"
            if ($a === $reqLocal) {
                return $requestedEmail;
            }

            // alias stored as "foo@domain"
            if (str_contains($a, '@')) {
                [$al, $ad] = explode('@', $a, 2);
                $al = trim($al);
                $ad = trim($ad);
                if ($al === $reqLocal && $ad !== '' && isset($validDomains[$ad]) && $ad === $reqDom) {
                    return $requestedEmail;
                }
            }

            // exact full email alias match
            if ($a === $requestedEmail) {
                return $requestedEmail;
            }
        }

        // Not an alias -> safe fallback
        return $loginUser . '@' . $emailDomain;
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>