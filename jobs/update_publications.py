#!/usr/bin/env python3
"""Fill publication metadata, or reconcile existing institute authors with OpenAlex.

Author updates are a preview unless --apply is supplied. See jobs/README.md for
configuration and the PHP rendering command when running outside Docker.
"""
import argparse
from collections import defaultdict
import configparser
from copy import deepcopy
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys
import unicodedata

from bson import json_util
from diophila import OpenAlex
from nameparser import HumanName
from pymongo import MongoClient

JOB_DIR = Path(__file__).resolve().parent


def normalize_doi(value):
    return re.sub(r"^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)", "",
                  str(value or "").strip(), flags=re.I).lower()


def normalize_orcid(value):
    value = re.sub(r"^https?://(?:www\.)?orcid\.org/", "",
                   str(value or "").strip(), flags=re.I).upper().rstrip("/")
    return value if re.fullmatch(r"\d{4}-\d{4}-\d{4}-\d{3}[\dX]", value) else None


def institution_id(value):
    value = str(value or "").strip().rstrip("/").rsplit("/", 1)[-1].upper()
    return value if re.fullmatch(r"I\d+", value) else None


def name_key(value):
    """Compare full names and explicit aliases, without inventing initials matches."""
    name = HumanName(unicodedata.normalize("NFC", str(value or "")))
    def clean(part):
        return " ".join(part.casefold().replace(".", "").split())
    last = clean(name.last)
    given = clean(" ".join(filter(None, [name.first, name.middle])))
    return (last, given) if last and given else None


def stored_name(author):
    return f"{author.get('last', '')}, {author.get('first', '')}"


def source_names(authorship):
    return {key for key in [name_key(authorship.get("raw_author_name")),
                           name_key((authorship.get("author") or {}).get("display_name"))] if key}


def source_orcids(authorship):
    return {key for key in [normalize_orcid(authorship.get("raw_orcid")),
                           normalize_orcid((authorship.get("author") or {}).get("orcid"))] if key}


class PersonIndex:
    def __init__(self, persons):
        self.names = defaultdict(set)
        self.orcids = defaultdict(set)
        self.persons = {}
        for person in persons:
            username = person.get("username")
            if not isinstance(username, str) or not username:
                continue
            self.persons[username] = person
            aliases = person.get("names") or []
            if isinstance(aliases, str):
                aliases = [aliases]
            for value in [stored_name(person), *aliases]:
                key = name_key(value)
                if key:
                    self.names[key].add(username)
            orcid = normalize_orcid(person.get("orcid"))
            if orcid:
                self.orcids[orcid].add(username)

    def match(self, author, source):
        identifiers = source_orcids(source)
        stored_orcid = normalize_orcid(author.get("orcid"))
        if stored_orcid:
            identifiers.add(stored_orcid)
        if len(identifiers) > 1:
            return None, "conflicting ORCIDs"
        names = source_names(source) | {name_key(stored_name(author))}
        name_matches = set().union(*(self.names.get(key, set()) for key in names))
        orcid_matches = set().union(*(self.orcids.get(key, set()) for key in identifiers))
        if len(orcid_matches) > 1:
            return None, "ORCID belongs to multiple accounts"
        if orcid_matches:
            username = next(iter(orcid_matches))
            if name_matches and username not in name_matches:
                return None, "ORCID and name point to different accounts"
            return username, "ORCID"
        if len(name_matches) != 1:
            return None, "ambiguous name" if name_matches else "no account match"
        username = next(iter(name_matches))
        person_orcid = normalize_orcid(self.persons[username].get("orcid"))
        if identifiers and person_orcid and person_orcid not in identifiers:
            return None, "account ORCID conflicts with source"
        return username, "registered name"


def eligible(author):
    # Explicit non-affiliation plus a removed user can represent a rejection.
    # OSIRIS does not store a separate rejection flag. Preserve these decisions.
    return (not author.get("user") and not author.get("manually")
            and not author.get("approved") and author.get("aoi") is not False)


def reconcile_authors(doc, work, persons, institution):
    """Return a minimal patch and review report without mutating either input."""
    authors = deepcopy(doc.get("authors") or [])
    sources = work.get("authorships") or []
    events = []
    if not isinstance(sources, list) or not sources:
        return {}, [{"status": "review", "reason": "OpenAlex has no authorships"}]

    # Include linked/manual authors in this map so one source author cannot be
    # used to link a second stored row. Never pair authors by array position.
    aligned = {}
    for index, author in enumerate(authors):
        key = name_key(stored_name(author))
        orcid = normalize_orcid(author.get("orcid"))
        by_orcid = [i for i, source in enumerate(sources) if orcid and orcid in source_orcids(source)]
        by_name = [i for i, source in enumerate(sources) if key and key in source_names(source)]
        aligned[index] = by_orcid or by_name
    source_owners = defaultdict(set)
    for index, candidates in aligned.items():
        for candidate in candidates:
            source_owners[candidate].add(index)

    proposals = {}
    already_linked = {a.get("user") for a in authors if a.get("user")}
    for index, author in enumerate(authors):
        if author.get("user"):
            continue
        event = {"index": index, "author": stored_name(author), "status": "review"}
        if not eligible(author):
            if author.get("manually") or author.get("approved"):
                events.append({**event, "reason": "manual or approved author preserved"})
            continue
        candidates = aligned[index]
        if len(candidates) != 1 or len(source_owners[candidates[0]]) != 1:
            events.append({**event, "reason": "source author missing or ambiguous"})
            continue
        source = sources[candidates[0]]
        identifiers = source_orcids(source)
        if len(identifiers) > 1:
            events.append({**event, "reason": "conflicting source ORCIDs"})
            continue
        affiliated = any(institution_id(i.get("id")) == institution
                         for i in source.get("institutions") or [])
        if not author.get("aoi") and not affiliated:
            events.append({**event, "reason": "no affiliation with configured institution"})
            continue
        username, reason = persons.match(author, source)
        if not username or username in already_linked:
            events.append({**event, "reason": reason if not username else "account already linked to another author"})
            continue
        proposed = deepcopy(author)
        proposed["user"] = username
        # Preserve existing affiliations, author names, positions and approvals.
        if not author.get("aoi"):
            proposed["aoi"] = affiliated
        if not author.get("orcid") and identifiers:
            proposed["orcid"] = next(iter(identifiers))
        proposals[index] = (proposed, {**event, "status": "link", "user": username,
                                     "matched_by": reason,
                                     "source_author": (source.get("author") or {}).get("id"),
                                     "institution_match": affiliated})

    counts = defaultdict(int)
    for proposed, _ in proposals.values():
        counts[proposed["user"]] += 1
    changed = False
    for index, (proposed, event) in proposals.items():
        if counts[proposed["user"]] > 1:
            events.append({**event, "status": "review", "reason": "multiple authors match the same account"})
            continue
        authors[index] = proposed
        changed = True
        events.append(event)
    return ({"authors": authors} if changed else {}), events


def getAbstract(inverted_abstract):
    if not inverted_abstract:
        return None
    words = [(position, word) for word, positions in inverted_abstract.items() for position in positions]
    return " ".join(word for _, word in sorted(words))


def metadata_patch(doc, work):
    patch = {}
    if not doc.get("oa_status") and work.get("open_access"):
        access = work["open_access"]
        if access.get("oa_status"):
            patch.update(open_access=access.get("is_oa"), oa_status=access["oa_status"])
    if not doc.get("concepts") and work.get("concepts"):
        patch["concepts"] = work["concepts"]
    if not doc.get("abstract") and work.get("abstract_inverted_index"):
        patch["abstract"] = getAbstract(work["abstract_inverted_index"])
    return patch


def publication_filter(update_authors=False, dois=None):
    query = {"type": "publication", "doi": {"$type": "string", "$nin": [""]}}
    if update_authors:
        query["authors"] = {"$elemMatch": {"user": {"$in": [None, ""]},
                                           "manually": {"$ne": True}, "approved": {"$ne": True},
                                           "aoi": {"$ne": False}}}
    else:
        query["$or"] = [{field: {"$in": [None, "", []]}} for field in ["oa_status", "concepts", "abstract"]]
    if dois:
        values = [re.escape(normalize_doi(doi)) for doi in dois]
        query["doi"] = {"$regex": r"^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)?(?:" + "|".join(values) + ")$", "$options": "i"}
    return query


class RendererError(RuntimeError):
    """An actionable renderer error with no credentials or raw subprocess output."""


def render_command(override=None):
    if override:
        return override
    local = shlex.join(["php", str(JOB_DIR / "render_publication_update.php")])
    root = JOB_DIR.parent
    compose = root / "docker-compose.prod.yml"
    if (not (root / "vendor" / "autoload.php").is_file()
            and compose.is_file() and shutil.which("docker")):
        return shlex.join(["docker", "compose", "-f", str(compose), "exec", "-T",
                           "app", "php", "/var/www/html/jobs/render_publication_update.php"])
    return local


class Renderer:
    """Read-only PHP bridge, preparing native display fields before the write."""
    ERROR_DETAILS = {
        "missing_dependencies": "Composer dependencies are missing in the PHP runtime",
        "database_mismatch": "PHP and Python use different database names",
        "server_mismatch": "PHP and Python connect to different MongoDB servers",
        "migration_required": "the application database version does not match the code",
        "invalid_publication": "the publication document is invalid",
        "publication_not_found": "the publication is absent from the PHP database",
    }

    def __init__(self, command, server_id=None):
        self.server_id = server_id
        self.command = shlex.split(command)
        if not self.command:
            raise ValueError("Empty renderer command")

    def request(self, database, **payload):
        request = {"database": database, "server_id": self.server_id, **payload}
        try:
            result = subprocess.run(self.command, input=json_util.dumps(request),
                                    capture_output=True, text=True, timeout=120, check=False)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise RendererError(f"PHP renderer could not run ({type(exc).__name__}); check --render-command") from None
        try:
            response = json_util.loads(result.stdout)
        except (ValueError, TypeError):
            response = {}
        if not isinstance(response, dict):
            response = {}
        if result.returncode or response.get("error"):
            detail = self.ERROR_DETAILS.get(response.get("error"), "the PHP process failed")
            raise RendererError(f"PHP renderer failed: {detail}. No update saved; check --render-command and Docker/PHP availability")
        if response.get("database") != database:
            raise RendererError("PHP renderer database/response mismatch; no update saved")
        return response

    def check(self, database):
        response = self.request(database, action="check")
        if response.get("status") != "ready":
            raise RendererError("PHP renderer did not confirm readiness; no update saved")

    def prepare(self, database, document):
        response = self.request(database, document=document)
        if not isinstance(response.get("patch"), dict):
            raise RendererError("PHP renderer returned invalid display data; no update saved")
        return response["patch"]


def process_publication(collection, doc, work, *, persons=None, institution=None,
                        dry_run=True, renderer=None, database=None):
    if not isinstance(work, dict) or normalize_doi(work.get("doi")) != normalize_doi(doc.get("doi")):
        return {"status": "error", "reason": "OpenAlex response DOI does not match"}
    if persons is not None:
        patch, events = reconcile_authors(doc, work, persons, institution)
    else:
        patch, events = metadata_patch(doc, work), []
    report = {"status": "unchanged", "authors": events, "fields": sorted(patch)}
    if not patch:
        return report
    if dry_run:
        report["status"] = "would-update"
        return report
    if persons is not None:
        if renderer is None:
            raise RuntimeError("Author updates require the PHP renderer")
        proposed = deepcopy(doc)
        proposed.update(patch)
        prepared = renderer.prepare(database, proposed)
        # Unit calculation may add units, but must not change author identities.
        identities = lambda rows: [{k: v for k, v in a.items() if k != "units"} for a in rows]
        if identities(prepared.get("authors", [])) != identities(proposed["authors"]):
            raise RendererError("PHP renderer changed author identities; no update saved")
        expected_users = []
        for role in ["authors", "editors", "supervisors"]:
            for author in proposed.get(role) or []:
                if author.get("user") and author["user"] not in expected_users:
                    expected_users.append(author["user"])
        if (prepared.get("rendered") or {}).get("users") != expected_users:
            raise RendererError("PHP renderer returned inconsistent profile links; no update saved")
        patch.update(prepared)
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    patch.update(updated=now[:10], updated_by="openalex-update")
    history = {"type": "edited", "user": "openalex-update", "date": now,
               "source": "OpenAlex", "changes": sorted(patch),
               "author_links": [event for event in events if event["status"] == "link"]}
    # Optimistic locking: do not overwrite a concurrent manual edit or changes
    # to fields used by the renderer with a stale author array or display HTML.
    result = collection.update_one(
        {"_id": doc["_id"], "$expr": {"$eq": ["$$ROOT", {"$literal": doc}]}},
        {"$set": patch, "$push": {"history": history}})
    report["status"] = "updated" if result.modified_count else "conflict"
    return report


def read_config(path, update_authors):
    config = configparser.ConfigParser(interpolation=None)
    if not config.read(path):
        raise ValueError("Configuration file not found")
    for section, option in [("Database", "Connection"), ("Database", "Database"), ("OpenAlex", "ApiKey")]:
        if not config.get(section, option, fallback="").strip():
            raise ValueError(f"Missing {section}.{option} in configuration")
    institution = institution_id(config.get("OpenAlex", "Institution", fallback=""))
    if update_authors and not institution:
        raise ValueError("OpenAlex.Institution must be an institution ID such as I12345")
    return config, institution


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=JOB_DIR / "config.ini")
    parser.add_argument("--update-authors", action="store_true", help="Reconcile unresolved institute authors on existing publications")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", help="Report proposed changes without database writes")
    mode.add_argument("--apply", action="store_true", help="Save author updates (author mode otherwise only previews)")
    parser.add_argument("--doi", action="append", help="Restrict to a DOI; repeat for multiple publications")
    parser.add_argument("--limit", type=int, help="Maximum number of publications to inspect")
    parser.add_argument("--report", type=Path, help="Write a JSON Lines report, also printed to stdout")
    parser.add_argument("--render-command", help="Override automatic PHP/Docker renderer selection")
    parser.add_argument("--check-renderer", action="store_true",
                        help="Check the renderer/database connection and exit without API requests or writes")
    args = parser.parse_args(argv)
    if args.limit is not None and args.limit < 1:
        parser.error("--limit must be positive")
    if args.check_renderer and (args.apply or args.dry_run):
        parser.error("--check-renderer is a separate read-only check; omit --apply and --dry-run")
    if args.apply and not args.update_authors:
        parser.error("--apply is only needed with --update-authors; metadata updates keep their existing default")
    return args


def main(argv=None):
    args = parse_args(argv)
    report_file = None
    client = None
    try:
        config, institution = read_config(args.config, args.update_authors)
        client = MongoClient(config["Database"]["Connection"], serverSelectionTimeoutMS=10000)
        db = client[config["Database"]["Database"]]
        renderer = None
        if args.check_renderer or (args.update_authors and args.apply):
            # The host and container can have different MongoDB addresses.
            # Verify that both runtimes still read the exact same server.
            server_id = client.admin.command("hello")["topologyVersion"]["processId"]
            renderer = Renderer(render_command(args.render_command), server_id)
            # Fail before API requests or writes, instead of repeating the same
            # environment failure for every publication with a possible match.
            renderer.check(db.name)
            print(json.dumps({"status": "renderer-ready", "database": db.name,
                              "command": renderer.command}), flush=True)
            if args.check_renderer:
                return 0
        api = OpenAlex(config["DEFAULT"].get("AdminMail"), api_key=config["OpenAlex"]["ApiKey"].strip())
        persons = PersonIndex(db.persons.find({}, {"username": 1, "first": 1, "last": 1, "names": 1, "orcid": 1})) if args.update_authors else None
        dry_run = args.dry_run or (args.update_authors and not args.apply)
        cursor = db.activities.find(publication_filter(args.update_authors, args.doi)).sort("_id", 1)
        if args.limit:
            cursor = cursor.limit(args.limit)
        if args.report:
            # A mistyped report path must not overwrite config.ini or source.
            report_file = args.report.open("x", encoding="utf-8")
        counts = defaultdict(int)
        def emit(value):
            line = json.dumps(value, ensure_ascii=False, default=str)
            print(line, flush=True)
            if report_file:
                report_file.write(line + "\n")
                report_file.flush()
        emit({"mode": "authors" if args.update_authors else "metadata", "dry_run": dry_run,
              "institution": institution, "database": db.name})
        for doc in cursor:
            try:
                work = api.get_single_work(normalize_doi(doc["doi"]), "doi")
                result = process_publication(db.activities, doc, work, persons=persons,
                                             institution=institution, dry_run=dry_run,
                                             renderer=renderer, database=db.name)
            except Exception as exc:
                # Client exceptions can contain credentials in request URLs.
                result = {"status": "error", "reason": type(exc).__name__}
                if isinstance(exc, RendererError):
                    result["reason"] = str(exc)
            counts[result["status"]] += 1
            emit({"id": str(doc["_id"]), "doi": doc["doi"], **result})
        emit({"summary": dict(counts)})
        return 1 if counts["error"] or counts["conflict"] else 0
    except Exception as exc:
        message = str(exc) if isinstance(exc, (ValueError, RendererError)) and not isinstance(exc, configparser.Error) else type(exc).__name__
        print(f"Update failed: {message}", file=sys.stderr)
        return 1
    finally:
        if report_file:
            report_file.close()
        if client is not None:
            client.close()


if __name__ == "__main__":
    raise SystemExit(main())
