# tracker_monitoring

The Composer package and the repository are both `happiness/tracker_monitoring`. It needs
Drupal 11.4 or later, because its command is a Drupal CLI (`dr`) command and `dr` first shipped with 11.4.

A Drupal module that sends what Tracker (Happiness' issue tracker) needs to watch a site: the packages it runs, its
status report and its error log. Tracker turns that into tickets (security updates, warnings, new or frequent errors) and
closes them again when the problem is gone. The site only makes outgoing requests: nothing on it is reachable from outside.

**Status:** the packages, status and log reports are built and send (`dr tracker-monitoring:send packages|status|logs`).
Cron sends the packages and the status once a day and the new log rows every hour. The rest of this file is the plan;
change it as decisions are made.

The log server and the shipper configuration for servers with syslog are in `happiness/tracker-logs`.

## What it sends

Three kinds of report, each switched on separately in the module's settings.

1. **Packages.** The same three things Tracker gets from a `composer.lock` and a `composer.json` it reads from a
   repository (`src/Monitoring` in Tracker), plus Drupal core, PHP and database versions. This is for sites whose
   repository Tracker cannot read. Tracker looks up the advisories itself, so the module sends no advisory data, and
   the ticket logic, the fingerprint and the automatic closing are the ones phase 1 has.
   - **Installed packages:** package name (lower case) to installed version, without a leading `v`; production
     packages only, as `composer.lock` has them under `packages`.
   - **What each package requires:** package name to the packages it requires (no `php`, no extensions). Tracker needs
     it to find the package the project asked for that brings a vulnerable one in, so that a site gets one ticket per
     such package (`drupal/core-recommended`) and not one per vulnerable package.
   - **The project's own requirements:** the `require` of the project's `composer.json` (not `require-dev`).

   `Composer\InstalledVersions` has versions but not the requirements per package or the project's own, so the module
   reads `vendor/composer/installed.json` (it has `require` per package) and the project's `composer.json`. Where
   `composer.json` is (Drupal sites often keep it one level above the web root) and what `installed.json` holds is
   checked on one real site (the Happiness site: `composer.json` in the project root, `installed.json` with production
   and dev packages); other sites are not.
2. **Status.** Built. The warnings and errors of Drupal's status report (which includes cron age), maintenance mode,
   the updates the Update module already has (it fetches its own data from cron; this module fetches nothing), and the
   Drupal, PHP and database versions. Tracker turns it into two internal tickets; the format is in Tracker's
   documentation. The Update module is optional: without it `updates` is empty.
3. **Logs.** Built. New rows from `dblog` (the `watchdog` table), for sites where syslog is not possible, such as sites
   on shared hosting. Sites that can use syslog use rsyslog and Vector instead (see `tracker-logs`) and do not need this
   part. The rows go to Tracker (`"report": "logs"`), which scrubs them again and passes them on to the log server,
   keeping none. The project in Tracker needs its logs switched on and a name for the site.

### The log rows

`watchdog` stores `message` as a template (`@path`, `%user`) and the values separately in `variables`. The module sends
the **template**, the type, the severity and the time. The template is what Tracker groups errors by (the `signature`),
and the personal data is in `variables`, which is not sent. The message with the variables filled in is sent only if a
setting asks for it, and then only after scrubbing.

- Never read: `hostname` (the visitor's IP), `uid`, `referer`, `location`, `link`.
- Scrubbing, for the filled-in message and for modules that write data straight into `message`: email addresses, IP
  addresses and query strings are removed. The same rules are in `tracker-logs/shippers/vector.yaml` and in Tracker;
  they must give the same result, so they share test cases (`tests/fixtures/log-scrubbing.json` here, the same file in
  Tracker). Tracker scrubs again and makes the signature itself; the module's scrubbing is so that nothing leaves the
  site unscrubbed.
- Run from cron, every hour, at most 1,000 rows a report and five reports a run. The last sent `wid` is kept in Drupal's
  state and does not move when sending fails, so the rows are sent again next time. The first send only marks the
  newest `wid` ("Started"): history is not sent. `dblog` prunes old rows (`row_limit`), so a busy site needs frequent
  cron.
- Settings: the lowest severity to send (default warning) and a list of types to leave out (`page not found` and
  `access denied` are noisy).

## How it reaches Tracker

Settled for the packages report; Tracker's side is `POST /hooks/monitoring` (documented in Tracker's admin docs under
Integrations › Site monitoring › Reported by the site):

- `POST` over HTTPS with one key per project, `<project id>.<secret>`, made under the project's Site monitoring settings
  in Tracker. Headers: `X-Tracker-Project` (the id), `X-Tracker-Timestamp` and `X-Tracker-Signature`, the HMAC-SHA256 of
  `<timestamp>.<body>` with the whole key. The timestamp must be within five minutes, so a captured request cannot be
  replayed. The packages report is `{"report": "packages", "packages", "requirements", "requires"}`; the log report is
  `{"report": "logs", "rows": [{"time", "type", "level", "message"}]}`.
- Answers: 200 with counts; 400, 401, 409, 413 are refusals (logged on the site, not sent again until something is
  changed); 429 and 5xx mean nothing was changed and the same report is sent again on the next run.
- Tracker knows which project a key belongs to and sets `site` itself. A payload cannot claim to be another site, and
  a site holds no credential for the log server: Tracker forwards logs to VictoriaLogs.
- Tracker answers 503 when it cannot hand logs on, and the module sends the same rows again later.
- A site that stops reporting is a finding in itself: Tracker opens an internal ticket after three days without a report.
- The Tracker address (`endpoint`, no default: nothing is sent until it is set) and the levels are set in the module's
  configuration. The key is not; it belongs in
  `settings.php` or the environment, not in exported configuration, so it does not end up in the site's repository.

## Drupal CLI and cron

- `hook_cron` sends the packages and the status once a day and the log rows every hour (`ReportScheduler`): a report
  that went through waits its interval, one that did not waits an hour, so a Tracker that is down is not asked on every
  cron run and a replaced key is picked up by itself. `tracker-monitoring:send` sends at once and does not move these
  times.
- `dr tracker-monitoring:send [packages|status|logs]` sends at once and prints what it sent and what Tracker answered.
- `dr tracker-monitoring:status` shows the settings (without the key), the last `wid` sent and the last answer.

## What it does not do

- No data is sent that identifies a visitor, and no raw request data.
- It does not change anything on the site: no updates, no configuration.
- It has no pages for visitors, and no interface beyond the settings form and the Drupal CLI commands.
- It does not alert anyone. Tracker opens the tickets.

## The Monitoring module: inspiration, not a dependency

[drupal.org/project/monitoring](https://www.drupal.org/project/monitoring) is a framework of sensors that other tools
collect (it has integrations for Nagios and Munin). It gives us neither the log rows nor the package list, and it
adds a large module with its own configuration to every client site, so this module does not depend on it and does
not read its sensors. Its list of sensors is a good list of what a site can tell us. Decided: use it as a source of
ideas for what Tracker should watch.

Candidates, from its sensors, to weigh later (none is in the plan above unless it says so):

| Sensor idea | In this plan |
|---|---|
| Status report (requirements) | yes, *Status* |
| Cron age (cron has not run for too long) | yes, *Status* |
| Maintenance mode left on | yes, *Status* |
| Module and core updates | yes, *Packages* and *Status* |
| Enabled modules (a development module on a live site) | no |
| dblog by severity, page not found | partly: *Logs*, with a minimum level and types to leave out |
| Queues (items waiting, a queue that does not drain) | no |
| Response time of the site | no (needs a view from outside, such as UptimeRobot) |
| OPcache and APCu (memory, hit rate) | no |
| Search API index backlog, image derivative errors, Migrate, mail spool | no: depends on what a site uses |

Which of these a site gets would be a setting per project in Tracker. A candidate is worth
adding when it has a clear action for a person (a ticket that says what to do), not just a number.

## Plan

1. **More checks**, chosen from the candidates above.

## Not decided

- How sites that cannot reach Tracker directly (a firewall, a proxy) send.

## Decided

- The message with its variables filled in is sent only if the settings ask for it (off by default), and then scrubbed;
  otherwise the template is sent.
- One key covers all three reports.

- A project has one source for its packages in Tracker (the repository or the site), not both, because the two disagree
  whenever a deploy lags and would close and open the same ticket every day. A site with the module still sends its
  status.
