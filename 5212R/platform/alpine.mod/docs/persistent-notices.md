# Persistent notices

Durable, server-side notices that render in the BlueOnyx console, above the page content,
on every page of the **standard console layout**, and stay visible until the notice itself is
removed.
(Pages that select a reduced layout, and the error pages, do not carry the notice region —
see Behaviour below.)

This is deliberately **not** the session-flash error mechanism. `BxPage::render()` clears the
error list as it draws the page, so a flash message appears once and is then gone — it cannot
persist. Notices are read from files on every render, so they persist by construction.

## Directory

    /usr/sausalito/notices/

Mode `755`, owned **`admserv:admserv`**. Created by this module's packaging, so it survives
upgrades, and its ownership is reasserted on every install.

The owner is `admserv` — the user the GUI runs as — because `clearOnClick: true` deletes a
notice file, and deleting requires **write permission on the directory**, not the file. Only
`admserv` can do that from the GUI. `root` still writes notices freely (root bypasses
permission checks), so the poller that populates this directory is unaffected.

An earlier draft made this directory `root:root`, which silently broke `clearOnClick`:
`admserv` could read notices but not delete them, so clicking did nothing and the notice
reappeared forever with no visible error. That is why ownership is reasserted at install
time rather than only at creation.

Deliberately **not** `/usr/sausalito/license/`, which is mode `700` and read-only to others —
sharing it would create a permissions conflict, and it holds unrelated data.

## File contract

One JSON file per notice. **The filename is the notice's identity**, so notices written by
different sources coexist without coordination. Conventional naming encodes the source:

    <source>_<identity>.json

| Key | Type | Required | Meaning |
|---|---|---|---|
| `message` | string | yes | Text to display. A notice without one is skipped. |
| `type` | string | no | Presentation style. The existing `alert_*` palette names used by `ErrorMessage()`: `alert_red`, `alert_light`, `alert_green`, `alert_navy`, `alert_white`. Defaults to `alert_red`. |
| `icon` | string | no | Icon name as accepted by `ErrorMessage()` (`alarm_bell`, `info_about`, `alert`, `alert_2`). Defaults to `alarm_bell`. |
| `url` | string | no | Where a click goes. |
| `clearOnClick` | bool | no | `true` — clicking clears the notice (its file is deleted), **regardless of whether a `url` is present**. `false` — clicking navigates to `url` and clears **nothing** locally; some other handler or backend is assumed to clear it by another method. Defaults to `false`. |
| `audience` | string | no | Who the notice is addressed to. `all` (default) — every console user; `admin` — the server administrators; `reseller` — the resellers; or a console user's **login name** — that one user. **`admin` and `reseller` are separate classes and do not nest**: a reseller does not see an `admin` notice and an administrator does not see a `reseller` notice, because that is how the platform itself draws the line (it strips site-level rights from a reseller). If a notice needs both, write two notices. An unset audience behaves as `all`. An **unrecognised** value renders the notice to **nobody** and is logged — see below. |

Clearing is a **site-level action** — it changes what every user of the host sees — so the clear
endpoint requires the `manageSite` capability, not merely a valid login.

The **writer** (the external poller — not this module, which only reads and deletes) is expected
to write files atomically (write a temp file, then `rename()` into place) so a reader never
sees a partial notice.

A file that cannot be read, is not valid JSON, or has no `message` is **skipped silently**.
One bad file never breaks the page and never suppresses the other notices.

## Example

```json
{
  "message": "Scheduled maintenance is due. Please review your configuration.",
  "type": "alert_red",
  "icon": "alarm_bell",
  "url": "https://example.com/more-information",
  "clearOnClick": false,
  "audience": "admin"
}
```

## Behaviour

- **Renders on every page that goes through `BxPage::render()`'s standard layout** — no
  per-controller work, and no way for such a page to opt out. This is not literally every
  screen: a controller that renders its own view directly never reaches the choke point and
  has no notice region (see the mechanisms below, and note there are more of these than just
  the error pages). The other exception is pages that deliberately select a different
  layout (pages that call `setOutOfStyle()` — the out-of-style frame pages pass `TRUE` and
  the wizard passes `'WIZARD'`; the guard tests `isset()`, not the value, so any override
  suppresses notices — and the
  error pages): those render a reduced page frame which does not include the notice region, so
  notices do not appear there. They reappear as soon as the user is back on a standard page.
  Note the two exclusions work by *different* mechanisms, which matters if you touch this:
  the wizard and the out-of-style frames go through `BxPage::render()` with
  `setOutOfStyle()` and select a different layout (with `TRUE` or `'WIZARD'`; the guard
  tests `isset()`); the error pages never reach
  `BxPage::render()` at all — `ErrorPages` builds its variables locally and renders
  `elmer_minimalist_view` directly. The same is true of the controllers that return their
  own views: the calendar, datepicker, plugins and validation screens (`Fullcalendar`,
  `Datepicker`, `Pluginsmin`, `Validation`, `Check_password`). Anything added to that list
  in future is also notice-free by construction.
- **No dismiss control.** There is no close button and no user-initiated dismissal state
  anywhere. A notice only stops being displayed when its file is removed.
- **Not session-bound**, so it survives page navigation, browser reloads, new logins and
  host reboots.
- **No client-side scripting required.** Notices are server-rendered markup and their
  click behaviour is ordinary link navigation, so they work in any browser, including with
  scripting disabled, and on mobile.

## Authority and the mirror

The **authoritative** notice store lives elsewhere. This directory is a **mirror**. Because the
managed servers may be NAT'd, the authority **cannot push** to them — synchronisation is
**pull-only**, on a cycle set by whatever writes these files.

Consequences to keep in mind:

- Clearing a notice here is local. If the authority still lists it, the next pull will
  re-create it. That is correct mirror behaviour, not a fault.
- A notice is only as stale as the last successful pull. Withdrawing a notice the authority
  can no longer justify is the responsibility of whatever writes these files.

## Why `audience` exists

The console is a single GUI shared by more than one class of user: the
`systemAdministrator`, resellers (the `adminUser` class), and site-level users. A notice file
is host-wide, so without an `audience` field every console user sees every notice.

`audience` exists so a notice can be addressed to the people it actually concerns. Messaging
about the server, the platform, or the provider relationship — the sort of thing that is
the administrator's business rather than an end user's — sets `audience: admin` and is not
rendered for ordinary console users.

The audience values are the distinctions the console can determine about the current viewer
at render time:

| Value | Addresses |
|---|---|
| `all` | every console user (the default when the field is absent) |
| `admin` | the server administrators |
| `reseller` | the resellers |
| a login name | that one console user |

The viewer's **class** is resolved from the session's **capabilities**, not from a name:
`admin` is not "the user called `admin`". The platform decides administration by the
`systemAdministrator` flag, and a reseller is the `adminUser` class. A **named user** is by
contrast a name match by definition and is never widened by capability — an administrator is
not shown a notice that names somebody else.

**`admin` and `reseller` do not nest.** A reseller is not a server administrator, and the
platform already draws that line. Treating one as a subset of the other would contradict the
platform's own model, so a notice that genuinely needs both is two notices.

Not supported, deliberately: per-site or per-owner targeting. A notice file is host-wide and
carries no site association, and the console does not know which site a viewer is acting for
at render time — that belongs to whatever writes these files. If a notice needs to reach a
site, address the user: a user belongs to a site.

**An unrecognised `audience` is rendered to NOBODY and logged, with the filename and the
accepted values.** That direction is deliberate and is the opposite of a "show it to
everyone" fallback. This field exists to *withhold* a notice, so a misspelling must not
publish it more widely than intended: a notice that never appears is visible and correctable,
whereas one shown to the wrong audience cannot be un-shown. Do not "fix" this into a
fallback-to-`all`.

## Clearing, and who is offered it

Clearing a notice removes it for **every** viewer, so it stays restricted to those who
administer the server — the `manageSite` capability, which the clear endpoint requires. An
audience decides **visibility**, never **permission**: a notice addressed to a non-admin user
is visible to them and not clearable by them, and that is correct rather than a gap.

Because those are now two independent facts, the banner is presented with care: a notice with
`clearOnClick` true is offered as **clearable only to a viewer who can actually clear it**.
A viewer who can see it but cannot clear it is not given a click whose only outcome is a
refusal — their click instead follows the notice's `url` if it has one, and if it has none the
notice is displayed with no click target at all.

## Security note — the URL is intentionally not validated

The `url` is **not** validated or sanitised, on read or on write. This is a considered
decision, not an oversight. It is, however, output-encoded when rendered, which is a
different thing: encoding keeps a crafted value inside the href attribute and is required
however trusted the writer is.

The directory is writable only by **root and the `admserv` GUI user** (owner
`admserv:admserv`, mode 755 — see Directory above), and **no web-reachable code path writes
notice files or their contents**: the only web-facing code here deletes files, it never
creates or edits them. Anyone able to write a notice file already holds local privilege on
this host and could modify the installed code directly, so validating the URL would defend
only against an attacker who has already won — while rejecting the legitimate cross-host
links this capability exists to support.


**Do not add validation later as though it were a bug fix.**

## Implementation

- `ci4/app/Libraries/BxNotice.php` — reads the directory (`BxNotice::all()`) and clears a
  single notice (`BxNotice::clear($id)`).
- The banner is rendered by `BxPage::render()`, above the page content, alongside the existing
  ActiveMonitor display.
- `BxNotice` never creates the directory itself. It runs as the GUI user; system directories
  are created by packaging, not by the web application. A missing directory is an expected
  state and renders nothing.
- If the directory cannot be **read**, or a notice cannot be **deleted**, `BxNotice` writes a
  diagnostic to the error log naming the path, the user, the expected ownership and the
  command to check it. Without that, a permissions fault is invisible — notices would simply
  never appear, or clearing would silently do nothing.
