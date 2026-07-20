"""This module wraps all API calls to the OpenAlex API."""
from typing import Callable, Optional, List, Iterable
import time

import requests


class APICaller:
    """This class wraps all API calls to the OpenAlex API."""

    # Basic paging only works for to read the first 10,000 results of any list.
    # see https://docs.openalex.org/api#basic-paging
    PAGING_RESULTS_MAX = 10000

    # OpenAlex currently accepts at most 100 results per page.
    PER_PAGE_MAX = 100
    DEFAULT_PER_PAGE = 100
    DEFAULT_TIMEOUT = (5, 30)
    DEFAULT_MAX_ATTEMPTS = 4
    RETRYABLE_STATUS_CODES = {429, 500, 502, 503, 504}

    def __init__(self,
                 base_url: str,
                 email: Optional[str] = None,
                 api_key: Optional[str] = None,
                 timeout=DEFAULT_TIMEOUT,
                 max_attempts: int = DEFAULT_MAX_ATTEMPTS,
                 session: Optional[requests.Session] = None) -> object:
        """ Init API caller, preferably with an email to get into the polite pool."""
        self.base_url = base_url
        self.headers = {'Accept': 'application/json'}
        if email:
            self.headers['User-Agent'] = f'mailto:{email}'
        self.api_key = api_key.strip() if api_key else None
        self.timeout = timeout
        self.max_attempts = max(1, max_attempts)
        self.session = session or requests.Session()

    def get(self, path: str, params: Optional[dict] = None) -> dict:
        """ Make a GET request to the API.

        Args:
            path (str): path that will be concatenated to the base URL of the OpenAlex API.
            params (Optional[dict]): dictionary containing items that will be constructed
                        into a query string, optional.

        Returns:
            JSON object from HTTP response.
         """
        request_params = dict(params or {})
        if self.api_key:
            # OpenAlex documents API-key authentication as a query parameter.
            request_params.setdefault('api_key', self.api_key)

        for attempt in range(1, self.max_attempts + 1):
            response = None
            try:
                response = self.session.get(
                    url=f"{self.base_url}/{path}",
                    params=request_params,
                    headers=self.headers,
                    timeout=self.timeout,
                )

                if response.status_code in self.RETRYABLE_STATUS_CODES:
                    if attempt == self.max_attempts:
                        status_code = response.status_code
                        self.__close_response(response)
                        raise RuntimeError(
                            f"OpenAlex request returned HTTP {status_code} after "
                            f"{self.max_attempts} attempts."
                        )
                    status_code = response.status_code
                    delay = self.__retry_delay(response, attempt)
                    self.__close_response(response)
                    print(
                        f"OpenAlex request returned HTTP {status_code} "
                        f"(attempt {attempt}/{self.max_attempts}); retrying in {delay:g}s."
                    )
                    time.sleep(delay)
                    continue

                response.raise_for_status()
                result = response.json()
                self.__close_response(response)
                return result
            except (requests.RequestException, ValueError) as exc:
                if attempt == self.max_attempts:
                    detail = (
                        f"HTTP {response.status_code}"
                        if response is not None else exc.__class__.__name__
                    )
                    self.__close_response(response)
                    raise RuntimeError(
                        f"OpenAlex request failed after {self.max_attempts} attempts ({detail})."
                    ) from None
                delay = self.__retry_delay(response, attempt)
                self.__close_response(response)
                print(
                    f"OpenAlex request failed ({exc.__class__.__name__}, "
                    f"attempt {attempt}/{self.max_attempts}); retrying in {delay:g}s."
                )
                time.sleep(delay)

        # The loop either returns a response or raises the final error.
        raise RuntimeError("OpenAlex request retry loop exited unexpectedly")

    def get_all(self,
                path: str,
                params: dict,
                per_page: Optional[int] = None,
                pages: Optional[List[int]] = None,
                cursor: Optional[str] = None,
                on_page_complete: Optional[Callable[[Optional[str]], None]] = None) -> Iterable:
        """ Make multiple GET requests to the API to paginate through results.

        Args:
            path (str): path that will be concatenated to the base URL of the OpenAlex API.
            params (dict): dictionary containing items that will be constructed
                        into a query string.
            per_page (Optional[int]): number of entities per page. Needs to be in [1;100].
                Defaults to 100.
            pages (Optional[List[int]]): list of page numbers to query from API, optional.
                If empty, cursor pagination will be used.
            cursor (Optional[str]): cursor from a previous cursor-paginated request.
            on_page_complete: called with the next cursor after the consumer has
                fully processed a page.

        Returns:
            Generator, each item a dict from JSON representing a (partial) list of entities.
         """
        params = dict(params)
        params['per_page'] = self.__validate_per_page_param(per_page)
        if pages:
            return self.__do_basic_paging(path, params, pages)
        return self.__do_cursor_paging(path, params, cursor, on_page_complete)

    def __do_basic_paging(self, path: str, params: dict, pages: List[int]):
        """ Use basic pagination to loop thought the specified result pages. """
        pages = self.__validate_pages(pages, params['per_page'])
        for page in pages:
            params['page'] = page
            yield self.get(path, params)

    def __do_cursor_paging(self,
                            path: str,
                            params: dict,
                            cursor: Optional[str] = None,
                            on_page_complete: Optional[Callable[[Optional[str]], None]] = None):
        """ Use cursor pagination to loop thought the results. """
        params['cursor'] = cursor or "*"  # start cursor pagination
        while True:
            json_response = self.get(path, params)
            yield json_response

            next_cursor = json_response['meta'].get('next_cursor')
            # This runs only once the consumer requests the next page, meaning
            # the yielded page has been fully processed and is safe to checkpoint.
            if on_page_complete:
                on_page_complete(next_cursor)
            if not next_cursor:
                break

            params['cursor'] = next_cursor

    def __validate_per_page_param(self, per_page: int) -> Optional[int]:
        """Helper method validating the 'per_page' parameter."""
        if not per_page or per_page <= 0:
            return self.DEFAULT_PER_PAGE
        if 0 < per_page <= self.PER_PAGE_MAX:
            return per_page
        return self.PER_PAGE_MAX

    @staticmethod
    def __close_response(response) -> None:
        if response is not None:
            response.close()

    @staticmethod
    def __retry_delay(response, attempt: int) -> float:
        if response is not None:
            retry_after = response.headers.get('Retry-After')
            if retry_after:
                try:
                    return max(0.0, float(retry_after))
                except ValueError:
                    pass
        return float(2 ** (attempt - 1))

    def __validate_pages(self, pages, per_page):
        """Helper method validating the 'pages' parameter."""
        max_pages = self.PAGING_RESULTS_MAX / per_page
        valid_pages = [page for page in pages if 0 < page <= max_pages]
        return valid_pages
