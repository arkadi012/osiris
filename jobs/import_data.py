"""Import OpenAlex publications for the configured institution."""

import argparse

from openalex_parser import OpenAlexParser


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--reset-checkpoint',
        action='store_true',
        help='Discard a saved OpenAlex cursor before starting the import.',
    )
    args = parser.parse_args()

    importer = OpenAlexParser()
    if args.reset_checkpoint:
        if importer.reset_import_checkpoint():
            print(f"Removed import checkpoint {importer.import_checkpoint_path}.")
        else:
            print("No import checkpoint was present.")

    importer.importJob()


if __name__ == '__main__':
    main()
