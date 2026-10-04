
# Setup optional jobs

All optional jobs need Python to be installed. Please look up [how to install](https://wiki.python.org/moin/BeginnersGuide/Download) the latest Python version on your OS. You will also need `pip` to install packages.

When you have Python and pip installed, you can **install the MongoDB-Connector** that is required for all jobs by running:

```bash
pip install pymongo
```

Additionally, you have to copy the **configuration file** `config.default.ini` in the jobs folder and rename it to `config.ini`.
In this file, you must modify the values according to your needs.

For OpenAlex jobs, set `OpenAlex.ApiKey` to an API key from OpenAlex. Keep this
key only in `jobs/config.ini`, which is ignored by Git. The importer uses
cursor pagination with a local checkpoint at
`jobs/.openalex-import-checkpoint.json`; after a transient interruption,
rerunning `python jobs/import_data.py` resumes from the last completed page.
Use `python jobs/import_data.py --reset-checkpoint` only when you intentionally
want to start the cursor from the beginning.


## Setup the queue job feature

The queue job gets new activities from online sources and saves them in a queue. Users will be informed when new activities are waiting in the queue and they can easily add them to OSIRIS.


### Prepare

To set up this feature, you must first install diophila with pip:

```bash
pip install nameparser levenshtein
```

Additionally, you must change the OpenAlex-ID of your institute in the `config.ini` file.

```ini
[OpenAlex]
Institution = I7935750
```

### Init Cron Job

Finally, we init a cron job on the device. We use the editor nano for this (default on most devices is vi). The following settings are used to run the job weekly (2 a.m. on Sunday).

```bash
EDITOR=nano crontab -e 

# enter this as cronjob:
0 2 * * 0 python3 /var/www/html/jobs/openalex_parser.py

# press Ctrl+O to save and Ctrl+X to exit
```

## Update metadata and repair author links on existing publications

`update_publications.py` reads `Database.Connection`, `Database.Database`,
`OpenAlex.ApiKey` and `OpenAlex.Institution` from `jobs/config.ini`. It uses the
bundled OpenAlex client, including its API-key authentication, timeouts and retries.
The API key is never included in reports. The configured institution must be an
OpenAlex institution ID (`I12345` or its OpenAlex URL).

Without `--update-authors`, the script continues to fill missing open-access
status, concepts and abstracts. `--dry-run` previews those metadata updates too.

The new `--update-authors` mode selects unresolved institute authors independently
of metadata completeness. It fetches each existing publication by DOI, pairs
stored authors with OpenAlex authorships by ORCID or full name (never by list
position), and resolves them against current OSIRIS accounts. ORCIDs take priority;
registered alternative names support different spellings and name order. Initials,
missing middle names and transliterations are not guessed: register those variants
explicitly. Conflicting ORCIDs, duplicate identities and missing source authors are
reported for review. OpenAlex's authorship-level `institutions` are compared with
`OpenAlex.Institution`; existing publication-time affiliation is preserved.

Existing user links, manually edited or approved authors, author order, names,
positions, and publication metadata are preserved. Explicitly non-affiliated,
unlinked authors (`aoi: false`) are skipped: OSIRIS can use that state for a rejected
authorship and does not store a separate rejection marker. Those cases need manual
review. This mode updates authors, not editors or supervisors, although all three
roles remain included when rebuilding profile indexes.

Author mode **only previews by default**. Start with a report across all users:

```bash
.venv/bin/python jobs/update_publications.py --update-authors --dry-run --report /tmp/author-links-preview.jsonl
```

Use `--limit 10` for a sample, or `--doi 10.1007/s00265-026-03711-x` to select one
publication. `--doi` can be repeated. Reports are JSON Lines and are also printed
to stdout. An existing report file will not be overwritten. Review records with
`status: "review"`; only unambiguous `status: "link"` proposals can be applied.

To apply, add `--apply`. The read-only PHP companion prepares updated author units
at the publication date, formatted citations and `rendered.users` using OSIRIS's
native rendering functions. Python then saves the author links, derived fields and
an audit-history entry in one atomic update. A concurrent edit produces `conflict`
instead of overwriting it. A rendering failure leaves that publication unchanged.
There is no deletion/reimport and no separate browser rerender step.

The job selects local PHP when `vendor/autoload.php` is available. When it is
absent and this repository's `docker-compose.prod.yml` and Docker are available,
it automatically runs the helper in the production `app` container. Thus the same
command works on this repository's host and on a complete native installation:

```bash
.venv/bin/python jobs/update_publications.py --update-authors --apply --report /tmp/author-links-applied.jsonl
```

You can override the selection explicitly (for example, for a different Docker
Compose file or installation). The equivalent production command is:

```bash
.venv/bin/python jobs/update_publications.py --update-authors --apply \
  --render-command 'docker compose -f /opt/osiris/docker-compose.prod.yml exec -T app php /var/www/html/jobs/render_publication_update.php' \
  --report /tmp/author-links-applied.jsonl
```

Before any OpenAlex requests or updates, `--apply` checks that the renderer can
start and uses the same database/server and application version. A setup failure
stops the entire job immediately. The selected command is printed as
`status: "renderer-ready"`. Test just this connection without applying anything:

```bash
.venv/bin/python jobs/update_publications.py --check-renderer
```

The renderer uses the application's `CONFIG.php`. Its database name and MongoDB
server identity must match Python's configured database (MongoDB 6, as used by this
repository, supports this check). The application must be fully migrated. If PHP
cannot render a record, the job reports an error without saving it. This companion
is CLI-only and does not write to MongoDB, including during render preparation.

The job can be rerun after updating aliases/accounts or as a scheduled command.
Already linked authors are skipped; unresolved authors are reconsidered using fresh
OpenAlex data on each run. A successful `--apply` run is not limited to the contents
of an earlier preview report: it reads current data again. Exit code is nonzero if
any request/render fails or a concurrent edit is detected; inspect the report and
rerun after resolving the cause.

Run the isolated matching and workflow tests with:

```bash
.venv/bin/python -m unittest discover -s jobs/tests -v
```
