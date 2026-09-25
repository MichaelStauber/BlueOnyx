<?php namespace App\Libraries;

/**
 * BxNotice — reads persistent notices for the BlueOnyx console GUI.
 *
 * Persistent notices are durable, server-side messages that render in the console
 * page frame on every page that goes through BxPage::render()'s standard layout, and stay
 * visible until the notice itself is removed; they reappear as soon as the user is back
 * on such a page. This is NOT literally every screen: anything that renders its own view
 * directly bypasses the choke point and has no notice region. That is the error pages
 * plus the handful of controllers that return their own views (the calendar, datepicker,
 * plugins and validation screens). See the "Where notices appear" note in
 * docs/persistent-notices.md before relying on a notice being universally visible.
 *
 * The directory is read on every render and deliberately NOT cached. The reason is
 * correctness, not cost: a notice cleared by a click would keep reappearing from the
 * cache until its entry expired. (Redis is already available on this path, so caching
 * would be cheap to add — which is exactly why the reason for not doing it needs to be
 * stated: it would be a bug, not a saving.) The read is a handful of small files, once
 * per request, on a directory that is empty by default.
 *
 * Two kinds of page do not carry the notice region, by two DIFFERENT mechanisms — this
 * distinction matters if you touch the guard in BxPage::render():
 *   - pages that call setOutOfStyle(), so render() selects a different layout and skips
 *     the notice read entirely. The guard tests isset(), NOT the value, because the
 *     override is not uniform: the out-of-style frames (ProcessFrame, WorkFrame) pass
 *     TRUE, while the wizard passes the string 'WIZARD' — and those two values select
 *     different layouts. Any non-null override suppresses notices;
 *   - the error pages (ErrorPages) never reach BxPage::render() at all — they build
 *     their variables locally and render elmer_minimalist_view directly.
 * They are
 * deliberately NOT the session-flash error mechanism (see BxPage::setErrors()): that
 * path is cleared by BxPage::render() as the page is drawn, so a flash message is
 * displayed once and then gone.
 *
 * One JSON file per notice, in the notice directory. The FILENAME is the notice's
 * identity, so notices written by different sources coexist without coordination.
 * Conventional naming encodes both source and identity, so several feeds can share
 * the directory without colliding, e.g. <source>_<identity>.json.
 *
 * The local notice directory is a MIRROR of an authoritative store held elsewhere.
 * It is refreshed by a pull-only cycle, because the servers may be NAT'd and the
 * authority therefore cannot push to them. A cleared notice will reappear if the
 * authority still lists it: that is correct mirror behaviour, not a fault.
 *
 * File contract (all keys optional except message):
 *     message       string  Text to display.
 *     type          string  Presentation style. Accepts the existing alert_* palette
 *                           names used by ErrorMessage() (alert_red, alert_light,
 *                           alert_green, alert_navy, alert_white). Defaults to
 *                           alert_red.
 *     icon          string  Icon name, as accepted by ErrorMessage(). Defaults to
 *                           alarm_bell.
 *     url           string  Where a click goes. NOT validated — see the note on
 *                           clearOnClick below and the design decision.
 *     audience      string  Who the notice is addressed to. One of 'all' (every console
 *                           user — the default when the field is absent), 'admin' (the
 *                           server administrators), 'reseller' (the resellers), or a
 *                           console user's login name. 'admin' and 'reseller' are
 *                           separate classes and do not nest. An unrecognised value
 *                           renders the notice to NOBODY and is logged.
 *     clearOnClick  bool    true  -> clicking clears the notice (the file is deleted),
 *                                   regardless of whether a url is present.
 *                           false -> clicking navigates to url and clears NOTHING
 *                                   locally; it is assumed some other handler or
 *                                   backend clears it by another method.
 *                           Defaults to false.
 *
 * SECURITY NOTE — the notice URL is intentionally NOT validated or sanitised.
 * This is a considered decision, not an omission. The notice directory is writable
 * only by root and the admserv GUI user, and no web-reachable code path writes notice
 * files or their contents (the web-facing code here only deletes). Anyone able to place
 * a URL in a notice file already holds local privilege and could modify the installed
 * code directly, so validation would defend only against an attacker who has already
 * won, while breaking the legitimate cross-host links this capability exists to support.
 * DO NOT add validation as though it were a bug fix. Output ENCODING is separate and is
 * applied — do not remove that either.
 */
class BxNotice
{
    /**
     * Notice directory. Deliberately separate from /usr/sausalito/license/, which is
     * mode 700 admserv:admserv — notices are written by a root-owned poller and read
     * by the GUI (running as admserv), so sharing that directory would create a
     * writer/reader permissions conflict.
     */
    private const NOTICE_DIR = '/usr/sausalito/notices';

    /**
     * Where a clearOnClick notice links to. It is fixed by the route declared in the
     * same module (Modules/Base/Gui/Config/Routes.php), so it lives here as a constant
     * rather than being passed in by every caller.
     */
    private const CLEAR_URL = '/gui/notice/clear';

    /**
     * Read every valid notice from the notice directory.
     *
     * Returns an array of notice descriptor arrays, each carrying:
     *   'id'           filename without the .json extension (the notice identity)
     *   'message'      string
     *   'type'         string (alert_* palette name)
     *   'icon'         string
     *   'url'          string, '' when absent
     *   'clearOnClick' bool
     *
     * A file that cannot be read or parsed is skipped without raising, so one bad
     * file cannot break the page or suppress the other notices. Returns an empty
     * array if the directory is missing or unreadable, so the feature ships inert.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $dir = self::NOTICE_DIR;

        // No directory — first deployment, packaging not yet run, or the feature simply
        // has nothing to show. This is an EXPECTED state, not an error: render nothing.
        // We deliberately do NOT create the directory from here. This code runs as the
        // GUI user (admserv); system directories are created by packaging, not by the
        // web application, and a GUI that silently creates system paths is a liability.
        //
        // scandir() is the first call: it distinguishes all three states on its own
        // (false = missing or unreadable, [] = empty), so this runs on the page-render
        // choke point without paying extra stat syscalls on the healthy path. The two
        // stat checks happen only once scandir has already failed, to decide WHICH
        // diagnostic to log.
        $entries = @scandir($dir);

        if ($entries === false) {

            if (!is_dir($dir)) {
                return [];
            }

            // Directory exists but cannot be read — a real fault (wrong owner/mode), and
            // one that would otherwise be invisible: notices would simply never appear,
            // with no explanation anywhere. Log it so it is diagnosable.
            error_log(sprintf(
                'BxNotice: notice directory %s exists but is not readable by %s — no notices can render. '
                . 'Expected: owner admserv:admserv, mode 755. Check with: ls -ld %s',
                $dir, self::currentUser(), $dir
            ));
            return [];
        }

        $notices  = [];

        // scandir returns . and .. first; sort the rest for a stable render order.
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'json') {
                continue;
            }
            $files[] = $entry;
        }
        sort($files, SORT_STRING);

        foreach ($files as $entry) {
            $path = $dir . '/' . $entry;

            // Skip anything that is not a regular file. A symlink is followed by
            // is_file(), so it is rejected explicitly: notices are meant to be plain
            // files in this directory, and following a link would widen the read from
            // "files here" to "any readable file that this directory points at".
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                // Unreadable or empty — skip this notice, keep the others.
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                // Malformed JSON — skip. Do not break the page.
                continue;
            }

            // A notice without a message has nothing to display. Skip it rather than
            // rendering an empty banner.
            if (!isset($data['message']) || !is_string($data['message']) || trim($data['message']) === '') {
                continue;
            }

            $id = pathinfo($entry, PATHINFO_FILENAME);

            $notices[] = [
                'id'           => $id,
                'message'      => $data['message'],
                'type'         => (isset($data['type']) && is_string($data['type']) && $data['type'] !== '')
                                    ? $data['type']
                                    : 'alert_red',
                'icon'         => (isset($data['icon']) && is_string($data['icon']) && $data['icon'] !== '')
                                    ? $data['icon']
                                    : 'alarm_bell',
                'url'          => (isset($data['url']) && is_string($data['url']))
                                    ? $data['url']
                                    : '',
                // Strict boolean read: only a real true enables click-to-clear.
                'clearOnClick' => (isset($data['clearOnClick']) && $data['clearOnClick'] === true),
                // Who this notice is addressed to. Absent means 'all', which is the
                // documented default. An unrecognised value matches nobody and is
                // logged, so a mistake withholds a notice rather than publishing it
                // to people it was not meant for. See read_audience().
                'audience'     => self::read_audience($data, $entry),
            ];
        }

        return $notices;
    }

    /**
     * Clear a single notice by its identifier (filename without extension).
     *
     * Removes ONLY that notice and leaves every other notice in place. Used for the
     * clearOnClick: true behaviour. Returns true if the notice was removed.
     *
     * The identifier is sanitised to a basename so a crafted identifier cannot escape
     * the notice directory — the directory is root-writable, but this method may be
     * reached from a web request via the clear endpoint, so it must not become a
     * path-traversal vector.
     */
    public static function clear(string $id): bool
    {
        // Never allow traversal: take the basename and reject anything with a
        // separator or a parent-directory component.
        $id = basename($id);
        if ($id === '' || $id === '.' || $id === '..' || str_contains($id, '/') || str_contains($id, '\\')) {
            return false;
        }

        $dir  = self::NOTICE_DIR;
        $path = $dir . '/' . $id . '.json';

        // Already gone (a stale link, or a second click) — not an error. Treat as done:
        // the notice is not displayed either way, and reporting a fault here would turn
        // a double-click into a visible failure.
        if (!is_file($path)) {
            return false;
        }

        // Deleting requires WRITE permission on the DIRECTORY, not on the file. If the
        // directory is not writable by the GUI user, clearing silently does nothing and
        // the notice would reappear forever with no explanation. Check up front and say
        // exactly what is wrong and how to fix it.
        if (!is_writable($dir)) {
            error_log(sprintf(
                'BxNotice: cannot clear notice "%s" — directory %s is not writable by %s. '
                . 'Expected: owner admserv:admserv, mode 755 (the GUI user must be able to '
                . 'delete notice files for clearOnClick). Check with: ls -ld %s',
                $id, $dir, self::currentUser(), $dir
            ));
            return false;
        }

        $ok = @unlink($path);

        if (!$ok) {
            error_log(sprintf(
                'BxNotice: unlink failed for %s (running as %s). Check directory ownership '
                . 'and mode on %s.',
                $path, self::currentUser(), $dir
            ));
        }

        return $ok;
    }

    /**
     * The current process user, for diagnostics. Helps distinguish "the GUI could not
     * read/delete this" from "the file genuinely is not there" in logs.
     */
    private static function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return $pw['name'];
            }
        }
        return 'uid=' . (function_exists('posix_geteuid') ? posix_geteuid() : 'unknown');
    }

    /**
     * The audiences that are NAMED rather than a login name.
     *
     *   'all'       — every console user. The default when the field is absent.
     *   'admin'     — the server administrators.
     *   'reseller'  — the resellers.
     *
     * Anything else that looks like a login name is a named-user audience: a notice
     * addressed to one account. 'admin' and 'reseller' are deliberately SEPARATE and do
     * NOT nest: a reseller is not a server administrator and the platform already draws
     * that distinction (it strips site-level rights from a reseller). A notice needing
     * both is two notices. Recorded because the temptation to nest them is obvious and
     * the platform's own model says no.
     */
    private const AUDIENCES = ['all', 'admin', 'reseller'];

    /**
     * Sentinel audience meaning "matches nobody". Used for a value that cannot be
     * understood, so an unrecognised audience renders to nobody rather than to everyone.
     *
     * The empty string is used because it cannot be a login name either — an account
     * name always begins with a letter — so it can never accidentally match a viewer.
     */
    private const AUDIENCE_NOBODY = '';

    /**
     * A login name begins with a lowercase letter and continues with lowercase letters,
     * digits, '_', '.' or '-'. This is the account-name rule CCE enforces on User.name
     * (the 'accountname' typedef in sauce-basic.schema), applied here so a named-user
     * audience can be told apart from a value that is simply not understood.
     */
    private const LOGIN_NAME_RE = '/^[a-z][a-z0-9_.\-]{0,30}$/';

    /**
     * Read the 'audience' field, normalising it the same way the other string fields are.
     *
     * Returns one of 'all', 'admin', 'reseller', a normalised login name, or
     * AUDIENCE_NOBODY when the value cannot be understood.
     *
     * The fail direction is deliberate and REVERSED from the earlier 'scope' field: an
     * unrecognised audience renders the notice to NOBODY and is logged, where 'scope'
     * fell back to 'all'. This field exists to WITHHOLD a notice, so a mistake must not
     * publish it more widely than intended. The two failure modes are not symmetric: a
     * notice that never appears is visible and correctable; one shown to the wrong
     * audience cannot be un-shown. Do not "restore consistency" with the old field.
     */
    private static function read_audience(array $data, string $entry): string
    {
        // Absent -> everyone, so a notice written before this field existed is unchanged.
        if (!isset($data['audience'])) {
            return 'all';
        }

        if (!is_string($data['audience'])) {
            error_log("BxNotice: notice '$entry' has a non-string audience; "
                      . 'rendering it to nobody. Accepted: ' . implode(', ', self::AUDIENCES) . ', or a login name.');
            return self::AUDIENCE_NOBODY;
        }

        $audience = strtolower(trim($data['audience']));

        if (in_array($audience, self::AUDIENCES, true)) {
            return $audience;
        }

        // Not a named audience. A login-name-shaped value addresses that one user; it is
        // a name match by definition and is never widened by capability.
        if (preg_match(self::LOGIN_NAME_RE, $audience) === 1) {
            return $audience;
        }

        // Neither a named audience nor a possible login name — refuse rather than widen.
        error_log("BxNotice: notice '$entry' has an unrecognised audience '"
                  . $data['audience'] . "'; rendering it to nobody. Accepted: "
                  . implode(', ', self::AUDIENCES) . ', or a console user\'s login name.');
        return self::AUDIENCE_NOBODY;
    }

    /**
     * Does a notice with this audience apply to this viewer?
     *
     * $viewer is supplied by the caller because only the console knows who is logged in —
     * this library deliberately does not reach into the session. It carries:
     *   'loginName'  string  the viewer's login name
     *   'isAdmin'    bool    whether the viewer is a server administrator
     *   'isReseller' bool    whether the viewer is a reseller
     *
     * A missing key is treated as absent/false, which WITHHOLDS the notice. That is the
     * safe assumption for a caller that does not know the viewer, and matches the fail
     * direction above.
     */
    private static function audience_applies(string $audience, array $viewer): bool
    {
        // Normalise for the same predictable matching read_audience() applies to the file
        // value, so the comparison does not depend on the caller having done it.
        $audience = strtolower(trim($audience));

        // Unrecognised audience — matches nobody. Already logged when it was read.
        if ($audience === self::AUDIENCE_NOBODY) {
            return false;
        }

        if ($audience === 'all') {
            return true;
        }

        // 'admin' and 'reseller' do not nest: each matches its own class only.
        if ($audience === 'admin') {
            return !empty($viewer['isAdmin']);
        }

        if ($audience === 'reseller') {
            return !empty($viewer['isReseller']);
        }

        // A named-user audience: a name match ONLY, never satisfied by capability. An
        // administrator is not shown a notice that names somebody else.
        return $audience === strtolower(trim((string) ($viewer['loginName'] ?? '')));
    }

    /**
     * The alert palette and icon names live in ONE place only: the ErrorMessage()
     * helper (app/Helpers/uifc_ng_helper.php). This renderer used to carry copies of
     * those lookup tables and of ErrorMessage()'s markup; two copies of the same
     * palette drift apart, so the copies were removed.
     *
     * That helper is declared by BaseController's $helpers list, so it is loaded for
     * controller requests. This library may also be reached from BxPage::render(), and
     * must not assume CI4's helper() loader is available on every path, so the helper
     * FILE is required directly. That is a plain PHP file and needs nothing from CI4.
     */
    private static function ensureErrorHelper(): void
    {
        if (function_exists('ErrorMessage')) {
            return;
        }

        $file = APPPATH . 'Helpers/uifc_ng_helper.php';

        if (is_file($file)) {
            require_once $file;
        }
    }

    /**
     * Render every notice as a banner for the console, above the page content.
     *
     * Returns the complete HTML block (empty string when there are no notices, so an
     * empty or absent directory produces no visible change to the console).
     *
     * The banner is produced by delegating to ErrorMessage() with $dismissible=FALSE,
     * which gives exactly the required behaviour: no close control is emitted, and
     * notice styling stays identical to every other alert in the product because there
     * is a single source for the palette and markup.
     *
     * Design constraints honoured:
     *  - Server-rendered markup only. No client-side scripting is used or required, so
     *    this works in any browser, including with scripting disabled, and on mobile.
     *    The click behaviour is ORDINARY LINK NAVIGATION (a real <a href>), which also
     *    keeps standard browser affordances working — open in new tab, middle click,
     *    keyboard activation, long-press on mobile.
     *  - NO dismiss control, and no dismissal state recorded anywhere. A notice only
     *    stops being displayed when it is removed from the notice directory.
     *  - Responsive, because the underlying alert markup is.
     *
     * @param array $viewer   Who is loading the page, so the audience can be matched. Keys:
     *                        'loginName' (string), 'isAdmin' (bool), 'isReseller' (bool),
     *                        and 'canClear' (bool — whether this viewer may clear a notice,
     *                        i.e. holds the site-management capability). A missing key is
     *                        treated as absent/false, which withholds the notice. The
     *                        default empty array is the safe assumption for a caller that
     *                        does not know the viewer: only 'all'-addressed notices render.
     */
    public static function render(array $viewer = []): string
    {
        $notices = self::all();

        if ($notices === []) {
            return '';
        }

        // Keep only the notices addressed to this viewer. See audience_applies(). This is
        // per-viewer: it changes nothing about the notice itself, so two viewers loading
        // the same page see their own sets.
        $notices = array_values(array_filter(
            $notices,
            static function (array $notice) use ($viewer): bool {
                return self::audience_applies($notice['audience'], $viewer);
            }
        ));

        if ($notices === []) {
            return '';
        }

        self::ensureErrorHelper();

        // A notice cannot be rendered without the alert helper. Rather than emit a
        // broken banner, fall silent and say why.
        if (!function_exists('ErrorMessage')) {
            error_log('BxNotice: cannot render notices — ErrorMessage() helper unavailable.');
            return '';
        }

        // Whether this viewer may clear a notice. Clearing is host-wide and stays with the
        // site-management capability; the audience decides VISIBILITY, never permission.
        $canClear = !empty($viewer['canClear']);

        $out = '';

        foreach ($notices as $notice) {
            // Decide the link target.
            //
            // clearOnClick=true  -> the click ITSELF clears: link to the clear endpoint,
            //                       regardless of whether a url is present. This is only
            //                       offered to a viewer who can actually clear — a click
            //                       whose only outcome is a refusal must not be presented.
            // clearOnClick=false -> the click navigates to the notice's url and clears
            //                       nothing locally; offered to anyone who can see it.
            $href = '';
            if ($notice['clearOnClick'] === true) {
                if ($canClear) {
                    $href = self::CLEAR_URL . '/' . rawurlencode($notice['id']);
                } else {
                    // The viewer can see this notice but not clear it. Fall back to the
                    // notice's own url if it has one; otherwise it is displayed with no
                    // click target below. Never point at the clear endpoint here — that
                    // would offer a click whose only outcome is a refusal.
                    $href = $notice['url'];
                }
            } elseif ($notice['url'] !== '') {
                // NOTE: the URL is intentionally NOT validated or restricted — no scheme
                // or host allowlist. See the class docblock and docs/persistent-notices.md;
                // that is a considered decision.
                //
                // It IS output-encoded, which is a different thing and is required
                // regardless: encoding stops a crafted value breaking out of the href
                // attribute. Do not remove the encoding on the grounds that the directory
                // is trusted — it is not the same question.
                $href = $notice['url'];
            }

            // $dismissible = FALSE: notices must offer no close control.
            //
            // Escape everything that came from the notice file before it reaches the
            // shared helper. ErrorMessage() interpolates its arguments raw (it is an
            // internal helper written for hard-coded call sites), so a message of
            // '</p><script>…</script><p>' would otherwise execute on every console page,
            // and a type/icon would break out of its class attribute. Escaping here —
            // at the boundary where file data enters a shared rendering helper — is
            // output encoding, and is required regardless of how trusted the writer is.
            // htmlspecialchars() with an explicit charset, not CI4's esc(): it is the
            // idiom this product already uses for output encoding, and naming the
            // charset removes any dependence on a configured escaper default (there is
            // no Config/Escaper.php override here, so esc() would silently inherit
            // App::$charset). ENT_QUOTES covers both quote styles so these values are
            // safe in text and in attribute context alike.
            $banner = ErrorMessage(
                htmlspecialchars($notice['message'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($notice['type'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($notice['icon'], ENT_QUOTES, 'UTF-8'),
                FALSE
            );

            if ($href !== '') {
                // Wrap the alert in a real anchor so the WHOLE banner is clickable.
                // No scripting, so this works in any browser and keeps standard link
                // affordances (new tab, middle click, keyboard activation).
                //
                // A wrapping anchor is required because ErrorMessage() has no way to
                // make the whole banner clickable: it can only append a link AFTER the
                // message text, which is a different affordance.
                //
                // The three declarations are load-bearing, not decoration:
                //   display:block     — the alert is a block element; an inline <a>
                //                       around it breaks the layout.
                //   color:inherit     — otherwise the text renders link-blue on the
                //                       alert's own background and becomes unreadable.
                //   text-decoration   — otherwise the whole banner is underlined.
                //
                // They are inline because this module ships no stylesheet of its own,
                // and the only always-loaded sheets belong to the vendor (style.css)
                // or to the server owner (customer.css) — neither is ours to edit.
                // 'notice-link' is therefore a semantic marker (handy for targeting and
                // tests), NOT a styling hook: no rule defines it today, and a theme
                // that wants to restyle this banner must beat the inline style. If this
                // module ever gains its own sheet, move these three here.
                //
                // The href is output-encoded: the clear URL is built from our own base
                // plus a rawurlencode()'d id, but a notice-supplied url is arbitrary, so
                // it must not be able to break out of the attribute.
                $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="notice-link"'
                      . ' style="display: block; color: inherit; text-decoration: none;">'
                      . $banner . '</a>';
            } else {
                // No link target: displayed, simply not clickable.
                $out .= $banner;
            }
        }

        return $out;
    }
}
