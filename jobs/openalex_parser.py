
from pymongo import MongoClient
import configparser
import os

from diophila import OpenAlex
from nameparser import HumanName
from Levenshtein import ratio

from datetime import datetime
import html
import json
from pathlib import Path
from pprint import pprint


class OpenAlexParser():
    IMPORT_CHECKPOINT_VERSION = 1

    def __init__(self, ignore_duplicates=False) -> None:
        self.TYPES = {
            "book-section": "chapter",
            "monograph": "book",
            "report-component": "others",
            "report": "others",
            "peer-review": "others",
            "book-track": "book",
            "journal-article": "article",
            "article": "article",
            "book-part": "book",
            "other": "others",
            "book": "book",
            "journal-volume": "article",
            "book-set": "book",
            "reference-entry": "others",
            "proceedings-article": "others",
            "journal": "others",
            "component": "others",
            "book-chapter": "chapter",
            "proceedings-series": "others",
            "report-series": "others",
            "proceedings": "others",
            "database": "others",
            "standard": "others",
            "reference-book": "book",
            "posted-content": "others",
            "journal-issue": "others",
            "dissertation": "dissertation",
            "grant": "others",
            "dataset": "others",
            "book-series": "book",
            "edited-book": "book",
            "review": "magazine",
            "preprint": "preprint",
        }


        # read the config file
        config = configparser.ConfigParser()
        path = os.path.dirname(__file__)
        config.read(os.path.join(path, 'config.ini'))

        self.inst_id = config['OpenAlex']['Institution'].upper()
        self.startyear = config['DEFAULT']['StartYear']
        api_key = config['OpenAlex'].get('ApiKey', fallback='').strip() or None

        checkpoint_value = config['OpenAlex'].get(
            'ImportCheckpoint',
            fallback='.openalex-import-checkpoint.json',
        ).strip()
        self.import_checkpoint_path = None
        if checkpoint_value:
            checkpoint_path = Path(checkpoint_value)
            self.import_checkpoint_path = (
                checkpoint_path if checkpoint_path.is_absolute() else Path(path) / checkpoint_path
            )

        # set up database connection
        client = MongoClient(config['Database']['Connection'])
        self.osiris = client[config['Database']['Database']]


        # set up OpenAlex
        self.openalex = OpenAlex(config['DEFAULT'].get('AdminMail'), api_key=api_key)
        self._person_lookup_cache = {}
        self._journal_cache = {}
        
        self.possible_dupl = []
        if not ignore_duplicates:
            possible_dupl = self.osiris['activities'].find({
                'type': 'publication',
                        'year': {'$gte': int(self.startyear)},
            }, {'title': 1})
            self.possible_dupl = [
                (i['_id'], i['title']) for i in possible_dupl
            ]

    def _default_work_filters(self):
        return {
            "from_publication_date": self.startyear + "-01-01",
            "institutions.id": self.inst_id,
            "has_doi": 'true'
        }

    def _checkpoint_metadata(self, filters):
        return {
            'version': self.IMPORT_CHECKPOINT_VERSION,
            'institution': self.inst_id,
            'startyear': self.startyear,
            'filters': filters,
        }

    def _load_import_checkpoint(self, filters):
        if self.import_checkpoint_path is None or not self.import_checkpoint_path.exists():
            return None

        try:
            with self.import_checkpoint_path.open('r', encoding='utf-8') as handle:
                checkpoint = json.load(handle)
        except (OSError, json.JSONDecodeError) as exc:
            print(f"Ignoring unreadable import checkpoint {self.import_checkpoint_path}: {exc}")
            return None

        metadata = self._checkpoint_metadata(filters)
        if any(checkpoint.get(key) != value for key, value in metadata.items()):
            print(
                f"Ignoring import checkpoint {self.import_checkpoint_path}: "
                "its institution, start year, or filters do not match this import."
            )
            return None

        cursor = checkpoint.get('cursor')
        if not isinstance(cursor, str) or not cursor:
            print(f"Ignoring import checkpoint {self.import_checkpoint_path}: no valid cursor.")
            return None
        return cursor

    def _save_import_checkpoint(self, filters, next_cursor):
        if self.import_checkpoint_path is None:
            return

        if not next_cursor:
            try:
                self.import_checkpoint_path.unlink()
            except FileNotFoundError:
                pass
            return

        checkpoint = self._checkpoint_metadata(filters)
        checkpoint.update({
            'cursor': next_cursor,
            'updated': datetime.now().isoformat(timespec='seconds'),
        })
        self.import_checkpoint_path.parent.mkdir(parents=True, exist_ok=True)
        temporary_path = self.import_checkpoint_path.with_suffix(
            self.import_checkpoint_path.suffix + '.tmp'
        )
        with temporary_path.open('w', encoding='utf-8') as handle:
            json.dump(checkpoint, handle, ensure_ascii=False, sort_keys=True)
            handle.write('\n')
        temporary_path.replace(self.import_checkpoint_path)

    def reset_import_checkpoint(self):
        if self.import_checkpoint_path is None:
            return False
        try:
            self.import_checkpoint_path.unlink()
        except FileNotFoundError:
            return False
        return True
            


    def getUserId(self, name, orcid=None):
        if orcid:
            orcid_key = ('orcid', orcid)
            if orcid_key in self._person_lookup_cache:
                return self._person_lookup_cache[orcid_key]
            user = self.osiris['persons'].find_one({'orcid': orcid})
            if user:
                username = user['username']
                self._person_lookup_cache[orcid_key] = username
                return username

        name_key = ('name', name.last, name.first)
        if name_key in self._person_lookup_cache:
            return self._person_lookup_cache[name_key]
        user = self.osiris['persons'].find_one(
            {'$or': [
                {'last': name.last, 'first': {'$regex': '^'+name.first+'.*'}},
                {'names': f'{name.last}, {name.first}'}
            ]}
        )
        # print(user)
        # exit()
        username = user['username'] if user else None
        self._person_lookup_cache[name_key] = username
        return username

    def getAbstract(self, inverted_abstract):
        if not inverted_abstract: return None
        
        abstract = []
        for word in inverted_abstract:
            occurence = inverted_abstract[word]
            for oc in occurence:
                abstract.append((oc, word))
        abstract = " ".join([i[1] for i in sorted(abstract)])
        return abstract

    def getJournal(self, issn):
        if isinstance(issn, str):
            issns = [issn]
        elif isinstance(issn, (list, tuple)):
            issns = [value for value in issn if value]
        else:
            issns = []

        if not issns:
            return None

        for value in issns:
            if value in self._journal_cache:
                return self._journal_cache[value]

        journal = self.osiris['journals'].find_one({'issn': {'$in': issns}})
        if journal:
            self._cache_journal(journal, issns)
            return journal

        # If the journal does not exist, retrieve its metadata from OpenAlex.
        # This lookup is supplementary: a temporary venue API failure must not
        # abort importing the publication itself.
        try:
            source = self.openalex.get_single_venue(issns[-1], "issn")
        except Exception as exc:
            print(
                f"Could not retrieve OpenAlex venue for ISSN {issns[-1]}: {exc}. "
                "Importing publication without journal metadata."
            )
            return None

        if not isinstance(source, dict) or source.get('type') != 'journal':
            return None

        journal_name = source.get('display_name') or source.get('title')
        if not journal_name:
            print(f"OpenAlex venue for ISSN {issns[-1]} has no display name; skipped journal creation.")
            return None

        source_issns = source.get('issn') or issns
        if isinstance(source_issns, str):
            source_issns = [source_issns]

        publisher = source.get('host_organization_name') or source.get('publisher')
        if isinstance(publisher, dict):
            publisher = publisher.get('display_name') or publisher.get('name')

        new_journal = {
            'journal': journal_name,
            # OpenAlex does not provide abbreviated_title for every venue.
            'abbr': source.get('abbreviated_title') or journal_name,
            'publisher': publisher,
            'issn': source_issns,
            'oa': bool(source.get('is_oa', False)),
            'openalex': str(source.get('id') or '').replace('https://openalex.org/', '')
        }
        new_doc = self.osiris['journals'].insert_one(new_journal)

        new_journal['_id'] = new_doc.inserted_id
        self._cache_journal(new_journal, issns)
        return new_journal

    def _cache_journal(self, journal, requested_issns):
        journal_issns = journal.get('issn') or []
        if isinstance(journal_issns, str):
            journal_issns = [journal_issns]
        for value in list(requested_issns) + list(journal_issns):
            if value:
                self._journal_cache[value] = journal



    def parseWork(self, work):
        if work['is_retracted']:
            print('retracted')
            print(work)
            return False

        # print(work['doi'])
        if not work['doi'] or 'https://doi.org/' not in work['doi']:
            
            print('doi not found')
            print(work)
            return False

        pubmed = work['ids'].get('pmid')
        if pubmed:
            pubmed = pubmed.replace('https://pubmed.ncbi.nlm.nih.gov/', '')

        # check if element is in the database
        doi = work['doi'].replace('https://doi.org/', '')
        if doi and self.osiris["activities"].count_documents({'doi': doi}) > 0:
            print(f'DOI {doi} exists and was omitted.')
            return False
        if pubmed and self.osiris["activities"].count_documents({'pubmed': pubmed}) > 0:
            print(f'Pubmed {pubmed} exists and was omitted.')
            return False
        if self.osiris['queue'].count_documents({'doi': doi}) > 0:
            print(f'DOI {doi} exists in queue and was omitted.')
            return False
        # print(doi)
        typ = self.TYPES.get(work['type'])
        if not typ:
            print(f'Activity type {work["type"]} is unknown (DOI: {doi}).')
            return False

        # print(doi)
        authors = []
        for a in work['authorships']:
            # match via name and ORCID
            name = HumanName(a['author']['display_name'])
            orcid = a['author'].get('orcid')
            if (orcid):
                orcid = orcid.replace('https://orcid.org/', '')

            user = self.getUserId(name, orcid)
            pos = a['author_position']
            if pos == 'middle' and a.get('is_corresponding'):
                pos = 'corresponding'

            inst = [i.get('id') for i in a['institutions']]
            authors.append({
                'last': name.last,
                'first': name.first + (' ' + name.middle if name.middle else ''),
                'position': pos,
                'aoi': ('https://openalex.org/'+self.inst_id in inst),
                'orcid': orcid,
                'user': user,
                'approved': False
            })

        pages = None
        if work['biblio']['first_page']:
            pages = work['biblio']['first_page']
            if work['biblio']['last_page'] and work['biblio']['last_page'] != pages:
                pages += '-' + work['biblio']['last_page']

        # journal
        loc = work['primary_location']['source']
        # journal = loc['display_name']

        # date
        date = work['publication_date'].split('-')
        month = None
        day = None
        if len(date) >= 2:
            month = int(date[1])
        if len(date) >= 3:
            day = int(date[2])

        abstract = self.getAbstract(work.get('abstract_inverted_index'));
        work['title'] = html.unescape(work['title'])
        element = {
            'doi': doi,
            'type': 'publication',
            'subtype': typ,
            'title': work['title'],
            'year': work['publication_year'],
            'abstract': abstract,
            'month': month,
            'day': day,
            'authors': authors,
            'pages': pages,
            'openalex': work['id'].replace('https://openalex.org/', ''),
            'pubmed': pubmed,
            'open_access': work['open_access']['is_oa'],
            'oa_status': work['open_access']['oa_status'],
            'correction': False,
            'epub': False
        }
        if (typ == 'others'):
            element['doc_type'] = work['type'].title()
        
        journal = None
        if loc and loc.get('type') == 'journal':
            element['location'] = loc.get('display_name')
            journal = self.getJournal(loc.get('issn'))
            if journal:
                element.update({
                        'volume': work['biblio']['volume'],
                        'issue': work['biblio']['issue'],
                        'journal': journal['journal'],
                        'issn': journal['issn'],
                        'journal_id': str(journal['_id'])
                    })
                if (not element['volume']) and not element['issue']:
                    element['epub'] = True

        if (typ == 'article'):
            if not loc or not loc.get('issn'):
                element['subtype'] = 'magazine'
            elif loc.get('type')== 'repository':
                element['subtype'] = 'preprint'
            elif not journal:
                element['subtype'] = 'magazine'

        if (typ == 'chapter' and loc and loc.get('display_name')):
            element.update({
                'book': loc['display_name'],
                'issn': loc['issn'],
                
            })
        if typ == 'preprint':
            element['subtype'] = 'preprint'
        
        if (typ == 'magazine' or typ == 'preprint'):
            element['magazine'] = loc.get('display_name') if loc else None


        for id, dupl in self.possible_dupl:
            dist = ratio(dupl, element['title'])
            # print(dist, dupl)
            if (dist > 0.9):
                element['duplicate'] = id
                break
        return element
    
    def get_work(self, id, idtype='doi', ignoreDupl=True, test=False):
        if (test):
            # delete all entries with the same DOI
            self.osiris['activities'].delete_many({'doi': id})
        work = self.openalex.get_single_work(id, idtype)
        element = self.parseWork(work)
        if test:
            pprint(element)
            
        if (element != False):
            if ignoreDupl and element.get('duplicate'):
                print(f'Activity might have a duplicate (DOI {element["doi"]}) and was omitted.')
                return
            self.osiris['activities'].insert_one(element)
            print(f'{idtype.upper()} {id} has been added to the database.')
    
    def get_works_dois(self, filters=None):
        if not filters:
            filters = self._default_work_filters()
        pages_of_works = self.openalex.get_list_of_works(
            filters=filters,
            pages=None,
            per_page=100,
        )
        for page in pages_of_works:
            for work in page['results']:
                yield work['doi']
                    
    def get_works(self, filters=None, checkpoint=False):
        # NOPE: use created_date and updated_date to filter
        # Not possible, needs payed version

        if not filters:
            filters = self._default_work_filters()

        resume_cursor = self._load_import_checkpoint(filters) if checkpoint else None
        if resume_cursor:
            print(f"Resuming OpenAlex import from checkpoint {self.import_checkpoint_path}.")

        on_page_complete = None
        if checkpoint:
            on_page_complete = lambda next_cursor: self._save_import_checkpoint(filters, next_cursor)

        pages_of_works = self.openalex.get_list_of_works(
            filters=filters,
            pages=None,
            per_page=100,
            cursor=resume_cursor,
            on_page_complete=on_page_complete,
        )

        i = 0
        for page in pages_of_works:
            for work in page['results']:
                try: 
                    element = self.parseWork(work)
                    if element == False: continue
                    i+=1
                    yield element
                except Exception as e:
                    print(f'Error with DOI {work["doi"]}')
                    print(e)
                    continue
        print(f'--- Finished. Prepared {i} documents.')
    
    def getHistory(self, element):
        return {
            'type': 'imported',
            'user': None,
            'date': datetime.now().date().isoformat(),
            # 'data': element
        }
    
    def queueJob(self):
        for element in self.get_works():
            print(element)
            self.osiris['queue'].insert_one(element)
    
    def importJob(self):
        inserted = 0
        duplicates = 0
        completed = False
        try:
            for element in self.get_works(checkpoint=True):
                if element.get('duplicate'):
                    duplicates += 1
                    print(f'Activity might have a duplicate (DOI {element["doi"]}) and was omitted.')
                    continue
                element['imported'] = datetime.now().date().isoformat()
                element['history'] = [self.getHistory(element)]
                self.osiris['activities'].insert_one(element)
                inserted += 1
            completed = True
        finally:
            state = 'finished' if completed else 'stopped before completion'
            print(
                f'--- OpenAlex import {state}: inserted {inserted} documents, '
                f'skipped {duplicates} likely duplicates.'
            )


if __name__ == '__main__':
    parser = OpenAlexParser()
    # parser.queueJob()
    
    parser.get_work('10.1007/978-3-319-69075-9_13', test=True)
